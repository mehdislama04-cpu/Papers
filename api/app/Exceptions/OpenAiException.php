<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Erreur remontée par la couche OpenAI (transport, HTTP, refus, réponse inexploitable).
 *
 * `$retryable` distingue ce qui mérite une nouvelle tentative du job (panne passagère,
 * 429/5xx après épuisement des tentatives internes du client) de ce qui est définitif
 * (schéma invalide, clé absente, refus de sécurité, quota épuisé) et ne doit surtout pas
 * être rejoué : chaque tentative est facturée.
 */
class OpenAiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly string $kind = 'unknown',
        public readonly bool $retryable = false,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function missingApiKey(): self
    {
        return new self(
            "Clé API OpenAI absente : renseigne OPENAI_API_KEY dans .env (lue via config('papers.openai.api_key')).",
            kind: 'config',
        );
    }

    public static function transport(Throwable $previous, int $attempts): self
    {
        return new self(
            "Appel OpenAI injoignable après {$attempts} tentative(s) : {$previous->getMessage()}",
            kind: 'transport',
            retryable: true,
            context: ['attempts' => $attempts],
            previous: $previous,
        );
    }

    public static function http(int $status, ?string $errorCode, string $message, int $attempts, bool $retryable): self
    {
        return new self(
            "OpenAI a répondu {$status}".($errorCode !== null ? " ({$errorCode})" : '')." : {$message}",
            status: $status,
            errorCode: $errorCode,
            kind: 'http',
            retryable: $retryable,
            context: ['attempts' => $attempts],
        );
    }

    public static function refusal(string $refusal): self
    {
        return new self(
            "Le modèle a refusé de traiter le document : {$refusal}",
            kind: 'refusal',
        );
    }

    public static function incomplete(?string $reason): self
    {
        return new self(
            'Réponse OpenAI incomplète : '.($reason ?? 'raison inconnue'),
            kind: 'incomplete',
            // max_output_tokens atteint : rejouer à l'identique redonnera le même résultat.
            retryable: false,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function payload(string $message, array $context = []): self
    {
        return new self($message, kind: 'payload', context: $context);
    }
}
