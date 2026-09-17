<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\IngestToken;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Isolation entre comptes et jetons d'ingestion.
 *
 * Les documents scannes sont des papiers personnels — factures, contrats,
 * courriers de sante. Une fuite entre comptes serait la pire defaillance
 * possible de cette application, et un jeton d'upload rejouable en serait le
 * vecteur le plus simple.
 */
final class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function jpeg(string $name = 'page-1.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 1600);
    }

    #[Test]
    public function un_visiteur_ne_peut_pas_lister_les_documents(): void
    {
        $this->getJson('/api/documents')->assertUnauthorized();
    }

    #[Test]
    public function un_utilisateur_ne_voit_pas_les_documents_d_un_autre(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Document::factory()->for($alice)->create(['title' => 'Facture Alice']);
        Document::factory()->for($bob)->create(['title' => 'Facture Bob']);

        $response = $this->actingAs($alice)->getJson('/api/documents')->assertOk();

        $titles = array_column($response->json('data'), 'title');

        $this->assertContains('Facture Alice', $titles);
        $this->assertNotContains('Facture Bob', $titles);
    }

    #[Test]
    public function acceder_au_document_d_un_autre_renvoie_une_erreur(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $document = Document::factory()->for($bob)->create();

        $this->actingAs($alice)
            ->getJson("/api/documents/{$document->getKey()}")
            ->assertStatus(403);
    }

    #[Test]
    public function supprimer_le_document_d_un_autre_est_refuse(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $document = Document::factory()->for($bob)->create();

        $this->actingAs($alice)
            ->deleteJson("/api/documents/{$document->getKey()}")
            ->assertStatus(403);

        $this->assertDatabaseHas('documents', ['id' => $document->getKey(), 'deleted_at' => null]);
    }

    #[Test]
    public function modifier_la_tache_d_un_autre_est_refuse(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $todo = Todo::factory()->for($bob)->create(['title' => 'Tache de Bob']);

        $this->actingAs($alice)
            ->patchJson("/api/todos/{$todo->getKey()}", ['status' => 'done'])
            ->assertStatus(403);

        $this->assertDatabaseHas('todos', ['id' => $todo->getKey(), 'title' => 'Tache de Bob']);
    }

    #[Test]
    public function deposer_un_document_cree_les_pages_et_planifie_l_analyse(): void
    {
        Bus::fake();
        Storage::fake('documents');

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/api/documents', [
                'pages' => [$this->jpeg('page-1.jpg'), $this->jpeg('page-2.jpg')],
                'source' => 'scanner',
                'title' => 'Facture EDF',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202);

        $document = Document::query()->where('user_id', $user->getKey())->firstOrFail();

        $this->assertSame('Facture EDF', $document->title);
        $this->assertSame(2, $document->page_count);
        $this->assertSame(2, $document->pages()->count());

        Bus::assertDispatched(\App\Jobs\AnalyzeDocument::class);
    }

    #[Test]
    public function un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        Bus::fake();
        Storage::fake('documents');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/api/documents', [
                'pages' => [UploadedFile::fake()->create('virus.exe', 12, 'application/octet-stream')],
                'source' => 'scanner',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function un_jeton_d_ingestion_ne_sert_qu_une_fois(): void
    {
        Bus::fake();
        Storage::fake('documents');

        $user = User::factory()->create();

        $issued = $this->actingAs($user)
            ->postJson('/api/ingest/token')
            ->assertCreated()
            ->json('data.token');

        $this->assertNotEmpty($issued);

        // Le jeton en clair ne doit exister nulle part en base.
        $this->assertDatabaseMissing('ingest_tokens', ['token_hash' => $issued]);

        $send = fn () => $this->withHeaders(['Authorization' => "Bearer {$issued}", 'Accept' => 'application/json'])
            ->post('/api/ingest/shortcut', ['file' => $this->jpeg('Scanned Document.jpg')]);

        $send()->assertSuccessful();

        // Le rejeu doit echouer : c'est ce qui empeche un jeton intercepte de
        // servir a deposer des documents dans le compte de la victime.
        $send()->assertUnauthorized();
    }

    #[Test]
    public function un_jeton_d_ingestion_expire_est_refuse(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $plain = 'jeton-de-test-expire-0123456789';

        IngestToken::query()->forceCreate([
            'user_id' => $user->getKey(),
            'token_hash' => IngestToken::hashFor($plain),
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])
            ->post('/api/ingest/shortcut', ['file' => $this->jpeg()])
            ->assertUnauthorized();
    }

    #[Test]
    public function un_jeton_d_ingestion_inconnu_est_refuse(): void
    {
        Storage::fake('documents');

        $this->withHeaders(['Authorization' => 'Bearer jeton-totalement-invente', 'Accept' => 'application/json'])
            ->post('/api/ingest/shortcut', ['file' => $this->jpeg()])
            ->assertUnauthorized();
    }

    #[Test]
    public function l_ingestion_sans_jeton_est_refusee(): void
    {
        Storage::fake('documents');

        $this->post('/api/ingest/shortcut', ['file' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    #[Test]
    public function le_mot_de_passe_d_application_icloud_n_est_jamais_renvoye(): void
    {
        $user = User::factory()->create();

        \App\Models\CalendarAccount::factory()->for($user)->create([
            'apple_id' => 'test@icloud.com',
            'app_password' => 'abcd-efgh-ijkl-mnop',
        ]);

        $response = $this->actingAs($user)->getJson('/api/calendar/account')->assertOk();

        $this->assertStringNotContainsString('abcd-efgh-ijkl-mnop', $response->getContent() ?: '');
        $this->assertArrayNotHasKey('app_password', (array) $response->json('data'));
    }

    #[Test]
    public function le_mot_de_passe_d_application_est_chiffre_en_base(): void
    {
        $user = User::factory()->create();

        \App\Models\CalendarAccount::factory()->for($user)->create([
            'app_password' => 'abcd-efgh-ijkl-mnop',
        ]);

        $stored = (string) \Illuminate\Support\Facades\DB::table('calendar_accounts')->value('app_password');

        $this->assertNotSame('abcd-efgh-ijkl-mnop', $stored);
        $this->assertStringNotContainsString('abcd-efgh-ijkl-mnop', $stored);
    }
}
