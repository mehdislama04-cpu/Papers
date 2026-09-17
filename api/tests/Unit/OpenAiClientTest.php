<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\OpenAiClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Client OpenAI.
 *
 * Les cas testes ici sont ceux qui, mal geres, produisent un `null` silencieux
 * ou une boucle de retry infinie en production :
 *  - un refus de securite ne respecte PAS le schema JSON demande ;
 *  - une reponse tronquee a `status != completed` mais un corps qui ressemble
 *    a une reponse valide ;
 *  - depuis 2026, une montee en charge trop rapide renvoie 429 + `slow_down`
 *    la ou l'API renvoyait auparavant 503.
 */
final class OpenAiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Retry instantane : on teste la logique, pas l'horloge.
        config([
            'papers.openai.retry_base_ms' => 0,
            'papers.openai.retry_max_ms' => 0,
            'papers.openai.retry_jitter_ms' => 0,
            'papers.openai.api_key' => 'sk-test',
        ]);
    }

    private function client(): OpenAiClient
    {
        return app(OpenAiClient::class);
    }

    /** @param array<string, mixed> $extra */
    private function completed(array $extra = []): array
    {
        return array_merge([
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => '{"title":"Facture"}']],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ], $extra);
    }

    #[Test]
    public function une_reponse_complete_est_renvoyee_telle_quelle(): void
    {
        Http::fake(['*' => Http::response($this->completed(), 200)]);

        $response = $this->client()->responses(['model' => 'test', 'input' => []]);

        $this->assertSame('completed', $response['status']);
    }

    #[Test]
    public function un_refus_de_securite_leve_une_exception(): void
    {
        // Un refus ne respecte PAS le schema : un json_decode naif renverrait
        // null sans rien signaler, et le document finirait « analyse » et vide.
        Http::fake(['*' => Http::response([
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'refusal', 'refusal' => 'Je ne peux pas traiter ce contenu.']],
            ]],
        ], 200)]);

        $this->expectException(\Throwable::class);

        $this->client()->responses(['model' => 'test', 'input' => []]);
    }

    #[Test]
    public function une_reponse_incomplete_leve_une_exception(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [],
        ], 200)]);

        $this->expectException(\Throwable::class);

        $this->client()->responses(['model' => 'test', 'input' => []]);
    }

    #[Test]
    public function un_429_est_retente_puis_aboutit(): void
    {
        // Semantique 2026 : 429 + slow_down pour une montee en charge trop
        // rapide. Un gestionnaire ecrit avant 2026 n'attendrait cela que sur 503.
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'slow_down', 'message' => 'Trop vite']], 429)
            ->push($this->completed(), 200)]);

        $response = $this->client()->responses(['model' => 'test', 'input' => []]);

        $this->assertSame('completed', $response['status']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function un_503_est_egalement_retente(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'server_is_overloaded']], 503)
            ->push($this->completed(), 200)]);

        $response = $this->client()->responses(['model' => 'test', 'input' => []]);

        $this->assertSame('completed', $response['status']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function un_400_n_est_jamais_retente(): void
    {
        // Une 400 vient d'un schema invalide : retenter le meme payload ne peut
        // que reproduire la meme erreur, en facturant a chaque fois.
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid schema']], 400)]);

        try {
            $this->client()->responses(['model' => 'test', 'input' => []]);
            $this->fail('Une exception etait attendue.');
        } catch (\Throwable) {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function un_401_n_est_jamais_retente(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        try {
            $this->client()->responses(['model' => 'test', 'input' => []]);
            $this->fail('Une exception etait attendue.');
        } catch (\Throwable) {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function le_nombre_de_tentatives_est_borne(): void
    {
        config(['papers.openai.max_attempts' => 3]);

        Http::fake(['*' => Http::response(['error' => ['code' => 'slow_down']], 429)]);

        try {
            $this->client()->responses(['model' => 'test', 'input' => []]);
            $this->fail('Une exception etait attendue.');
        } catch (\Throwable) {
            Http::assertSentCount(3);
        }
    }

    #[Test]
    public function la_cle_d_api_part_en_en_tete_et_jamais_dans_l_url(): void
    {
        Http::fake(['*' => Http::response($this->completed(), 200)]);

        $this->client()->responses(['model' => 'test', 'input' => []]);

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString('sk-test', $request->url());

            return str_contains($request->header('Authorization')[0] ?? '', 'sk-test');
        });
    }
}
