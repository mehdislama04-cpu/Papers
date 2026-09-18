<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Photos des personnes.
 *
 * Une photo de personne est le visage de quelqu'un : elle merite au moins
 * autant de precautions qu'une facture. Les tests couvrent donc en priorite
 * l'isolation entre comptes et la signature des URLs, et seulement ensuite le
 * comportement fonctionnel.
 */
final class PersonPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    private function portrait(): UploadedFile
    {
        return UploadedFile::fake()->image('portrait.jpg', 900, 1200);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'dupont jean',
            'name' => 'M. Jean Dupont',
            'aliases' => ['M. Jean Dupont', 'DUPONT Jean'],
            'photo' => $this->portrait(),
        ], $overrides);
    }

    #[Test]
    public function un_visiteur_ne_peut_pas_envoyer_de_photo(): void
    {
        $this->postJson('/api/people/photo', $this->payload())->assertUnauthorized();
    }

    #[Test]
    public function un_visiteur_ne_peut_pas_lister_les_personnes(): void
    {
        $this->getJson('/api/people')->assertUnauthorized();
    }

    #[Test]
    public function envoyer_une_photo_cree_la_personne_et_ses_graphies(): void
    {
        $alice = User::factory()->create();

        // 201 : la personne n'existait pas, l'envoi vient de la creer.
        $this->actingAs($alice)
            ->post('/api/people/photo', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.key', 'dupont jean')
            ->assertJsonPath('data.name', 'M. Jean Dupont');

        $person = Person::query()->firstOrFail();

        $this->assertSame((int) $alice->getKey(), (int) $person->user_id);
        $this->assertTrue($person->hasPhoto());
        Storage::disk('documents')->assertExists((string) $person->photo_path);

        // Les graphies sont ce qui rendra un changement de normalisation
        // rejouable : sans elles, la photo serait perdue silencieusement.
        $this->assertEqualsCanonicalizing(
            ['M. Jean Dupont', 'DUPONT Jean'],
            $person->aliases()->pluck('raw')->all(),
        );
    }

    #[Test]
    public function renvoyer_une_photo_met_a_jour_au_lieu_de_dupliquer(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)->post('/api/people/photo', $this->payload())->assertCreated();

        // 200 et non 201 : le second envoi met a jour, il ne cree rien. C'est
        // la contrainte unique (user_id, match_key) qui le garantit.
        $this->actingAs($alice)
            ->post('/api/people/photo', $this->payload([
                'name' => 'Jean DUPONT',
                'aliases' => ['Jean DUPONT'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Jean DUPONT');

        // Une seule personne : c'est la contrainte unique (user_id, match_key)
        // qui rend l'envoi idempotent.
        $this->assertSame(1, Person::query()->count());

        // Les anciennes graphies ne sont pas effacees par les nouvelles.
        $this->assertCount(3, Person::query()->firstOrFail()->aliases);
    }

    #[Test]
    public function deux_utilisateurs_peuvent_avoir_la_meme_cle_sans_se_marcher_dessus(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->post('/api/people/photo', $this->payload())->assertCreated();

        // Meme cle, autre compte : une seconde CREATION, pas un conflit. La
        // contrainte unique porte sur (user_id, match_key), pas sur la cle
        // seule — deux foyers peuvent avoir un « Jean Dupont » chacun.
        $this->actingAs($bob)->post('/api/people/photo', $this->payload())->assertCreated();

        $this->assertSame(2, Person::query()->count());
    }

    #[Test]
    public function un_utilisateur_ne_voit_pas_les_personnes_d_un_autre(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->post('/api/people/photo', $this->payload())->assertCreated();

        $this->actingAs($bob)->getJson('/api/people')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function la_photo_d_un_autre_compte_est_inaccessible(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->post('/api/people/photo', $this->payload())->assertCreated();

        $url = $this->actingAs($alice)->getJson('/api/people')->json('data.0.photo_url');
        $this->assertIsString($url);

        // L'URL est valablement signee : ce qui doit bloquer Bob, c'est la
        // policy, pas la signature.
        $this->actingAs($alice)->get($url)->assertOk();
        $this->actingAs($bob)->get($url)->assertForbidden();
    }

    #[Test]
    public function le_fichier_exige_une_url_signee(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)->post('/api/people/photo', $this->payload())->assertCreated();

        $person = Person::query()->firstOrFail();

        // Meme authentifie et proprietaire : sans signature, rien.
        $this->actingAs($alice)
            ->get("/api/people/{$person->getKey()}/photo/file")
            ->assertForbidden();
    }

    #[Test]
    public function un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)
            ->postJson('/api/people/photo', $this->payload([
                'photo' => UploadedFile::fake()->create('facture.pdf', 64, 'application/pdf'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertSame(0, Person::query()->count());
    }

    #[Test]
    public function supprimer_la_photo_garde_la_personne_et_ses_graphies(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)->post('/api/people/photo', $this->payload())->assertCreated();

        $person = Person::query()->firstOrFail();
        $path = (string) $person->photo_path;

        $this->actingAs($alice)
            ->deleteJson("/api/people/{$person->getKey()}/photo")
            ->assertNoContent();

        Storage::disk('documents')->assertMissing($path);

        $person->refresh();

        $this->assertFalse($person->hasPhoto());
        // La personne survit : ses graphies restent exploitables pour rejouer
        // une normalisation, et le front retombe simplement sur le monogramme.
        $this->assertCount(2, $person->aliases);
    }

    #[Test]
    public function supprimer_la_photo_d_un_autre_est_refuse(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->post('/api/people/photo', $this->payload())->assertCreated();

        $person = Person::query()->firstOrFail();

        $this->actingAs($bob)
            ->deleteJson("/api/people/{$person->getKey()}/photo")
            ->assertForbidden();

        $this->assertTrue($person->refresh()->hasPhoto());
    }
}
