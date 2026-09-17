<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\OpenAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP OpenAI bâti sur Http:: (client natif Laravel).
 *
 * Pas de paquet tiers : openai-php/laravel exige Guzzle ^7.9.3 alors que Laravel 13
 * installe Guzzle 8.2.0 (incompatible, constaté à l'installation). Voir ARCHITECTURE.md §6.
 *
 * Deux points non négociables :
 *  - timeout(120)->connectTimeout(10) : sur Windows les timeouts de job Laravel reposent
 *    sur pcntl_alarm, absent. C'est la SEULE protection contre un worker bloqué (§7).
 *  - retry maison : le client ne retente rien tout seul. Sémantique 2026 : 429 + slow_down
 *    (montée en charge trop rapide) ET 503 + server_is_overloaded (modèle saturé).
 */
class OpenAiClient
{
    /** Repli si `papers.openai.retry_on_status` n'est pas configuré. */
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    /** Codes d'erreur métier : jamais de retry, ça ne passera pas davantage la 5e fois. */
    private const FATAL_ERROR_CODES = [
        'insufficient_quota',
        'billing_hard_limit_reached',
        'billing_not_active',
        'account_deactivated',
        'invalid_api_key',
    ];

    /**
     * POST {base}/v1/responses.
     *
     * Retourne la réponse décodée, après avoir vérifié qu'elle est exploitable
     * (status == completed, pas de content part "refusal", pas d'incomplete_details).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function responses(array $payload): array
    {
        $response = $this->send('/v1/responses', $payload, $this->timeout());

        $this->assertUsable($response);

        return $response;
    }

    /**
     * POST {base}/v1/embeddings — retourne le vecteur.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $model = (string) config('papers.openai.embedding.model', 'text-embedding-3-small');
        $dimensions = (int) config('papers.openai.embedding.dimensions', 1536);

        $payload = [
            'model' => $model,
            'input' => $this->truncateForEmbedding($text),
        ];

        // Le paramètre `dimensions` (réduction Matryoshka) n'existe que sur les modèles -3-*.
        if (str_starts_with($model, 'text-embedding-3')) {
            $payload['dimensions'] = $dimensions;
        }

        $body = $this->send('/v1/embeddings', $payload, (int) config('papers.openai.embed_timeout', 60));

        $vector = $body['data'][0]['embedding'] ?? null;

        if (! is_array($vector) || $vector === []) {
            throw OpenAiException::payload('Réponse /v1/embeddings sans vecteur exploitable.');
        }

        if (count($vector) !== $dimensions) {
            // Une dimension inattendue casserait l'insertion pgvector : mieux vaut échouer ici.
            throw OpenAiException::payload(sprintf(
                'Embedding de %d dimensions reçu, %d attendues (colonne vector(%d)).',
                count($vector), $dimensions, $dimensions,
            ));
        }

        return array_map(floatval(...), array_values($vector));
    }

    /**
     * Concatène les parts output_text d'une réponse /v1/responses.
     *
     * @param  array<string, mixed>  $response
     */
    public static function outputText(array $response): string
    {
        $text = '';

        foreach ((array) ($response['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $part) {
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        if ($text === '' && is_string($response['output_text'] ?? null)) {
            $text = $response['output_text'];
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{input_tokens: int, output_tokens: int, cached_tokens: int, reasoning_tokens: int, total_tokens: int}
     */
    public static function usage(array $response): array
    {
        $usage = (array) ($response['usage'] ?? []);

        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cached_tokens' => (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? ($input + $output)),
        ];
    }

    /**
     * Boucle d'envoi : backoff exponentiel + jitter, Retry-After traité comme un minimum.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $path, array $payload, int $timeout): array
    {
        $maxAttempts = max(1, (int) config('papers.openai.max_attempts', 3));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->request($timeout)->post($this->url($path), $payload);
            } catch (ConnectionException $e) {
                // Timeout, DNS, TLS. Le connectTimeout/timeout garantit qu'on passe bien ici
                // au lieu de pendre indéfiniment.
                if ($attempt >= $maxAttempts) {
                    throw OpenAiException::transport($e, $attempt);
                }

                $this->wait($path, $attempt, $this->backoff($attempt, null), 'connexion');

                continue;
            }

            if ($response->successful()) {
                $decoded = $response->json();

                if (! is_array($decoded)) {
                    throw OpenAiException::payload("Corps de réponse illisible sur {$path}.");
                }

                return $decoded;
            }

            $error = (array) ($response->json('error') ?? []);
            $code = is_string($error['code'] ?? null) ? $error['code'] : null;
            $message = is_string($error['message'] ?? null) ? $error['message'] : $response->body();
            $status = $response->status();
            $retryable = $this->isRetryable($status, $code);

            if (! $retryable || $attempt >= $maxAttempts) {
                throw OpenAiException::http($status, $code, mb_substr($message, 0, 500), $attempt, $retryable);
            }

            $this->wait($path, $attempt, $this->backoff($attempt, $this->retryAfter($response)), (string) $status);
        }
    }

    private function request(int $timeout): PendingRequest
    {
        $request = Http::withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('papers.openai.connect_timeout', 10))
            ->timeout($timeout);

        if (is_string($organization = config('papers.openai.organization')) && $organization !== '') {
            $request = $request->withHeaders(['OpenAI-Organization' => $organization]);
        }

        if (is_string($project = config('papers.openai.project')) && $project !== '') {
            $request = $request->withHeaders(['OpenAI-Project' => $project]);
        }

        return $request;
    }

    private function apiKey(): string
    {
        // Jamais de clé en dur ni de env() ici : config/papers.php lit OPENAI_API_KEY,
        // ce qui reste correct quand la config est mise en cache (config:cache).
        $key = config('papers.openai.api_key');

        if (! is_string($key) || trim($key) === '') {
            throw OpenAiException::missingApiKey();
        }

        return trim($key);
    }

    private function url(string $path): string
    {
        $base = (string) config('papers.openai.base_url', 'https://api.openai.com');
        $base = rtrim(trim($base), '/');

        // Tolère un OPENAI_BASE_URL fini par /v1 (forme utilisée pour la résidence UE)
        // sans produire /v1/v1/responses.
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }

        return $base.$path;
    }

    private function timeout(): int
    {
        return (int) config('papers.openai.timeout', 120);
    }

    private function isRetryable(int $status, ?string $code): bool
    {
        // Un code de quota ou de facturation ne passera pas davantage à la 5e tentative.
        if ($code !== null && in_array($code, self::FATAL_ERROR_CODES, true)) {
            return false;
        }

        $statuses = config('papers.openai.retry_on_status', self::RETRYABLE_STATUS);

        return in_array($status, is_array($statuses) ? $statuses : self::RETRYABLE_STATUS, true);
    }

    /**
     * Retry-After est un MINIMUM (présent sur 429 et sur les 503 passagers).
     */
    private function retryAfter(Response $response): ?float
    {
        $value = $response->header('Retry-After');

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0.0, (float) ($timestamp - time()));
    }

    /**
     * Backoff exponentiel plafonné, plus un jitter aléatoire. Retry-After, quand il est
     * présent, ne peut qu'allonger l'attente : c'est un minimum imposé par le serveur.
     */
    private function backoff(int $attempt, ?float $retryAfter): float
    {
        $base = (int) config('papers.openai.retry_base_ms', 2000) / 1000;
        $max = (int) config('papers.openai.retry_max_ms', 30000) / 1000;
        $jitter = random_int(0, max(0, (int) config('papers.openai.retry_jitter_ms', 1000))) / 1000;

        $exponential = min($base * (2 ** ($attempt - 1)), $max);

        return max($exponential, $retryAfter ?? 0.0) + $jitter;
    }

    private function wait(string $path, int $attempt, float $seconds, string $reason): void
    {
        Log::warning('OpenAI: nouvelle tentative', [
            'path' => $path,
            'attempt' => $attempt,
            'reason' => $reason,
            'sleep' => round($seconds, 2),
        ]);

        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Refus de sécurité et réponse tronquée : à détecter AVANT tout json_decode,
     * un refus ne respecte pas le schéma.
     *
     * @param  array<string, mixed>  $response
     */
    private function assertUsable(array $response): void
    {
        foreach ((array) ($response['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    throw OpenAiException::refusal((string) ($part['refusal'] ?? 'sans motif'));
                }
            }
        }

        if (($response['status'] ?? null) !== 'completed') {
            throw OpenAiException::incomplete(
                $response['incomplete_details']['reason']
                    ?? $response['error']['message']
                    ?? ($response['status'] ?? null)
            );
        }
    }

    /**
     * 8 192 tokens maximum pour text-embedding-3-*. On coupe large en caractères.
     */
    private function truncateForEmbedding(string $text): string
    {
        $limit = (int) config('papers.openai.embedding.max_input_chars', 24000);

        return mb_substr(trim($text), 0, $limit);
    }
}
