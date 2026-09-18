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
 * Renommage d'une personne.
 *
 * Le vrai risque ici n'est pas la securite mais la SURPRISE : `display_name`
 * suit par defaut la graphie la plus frequente des documents. Sans le drapeau
 * `name_overridden`, un nom choisi a la main serait reecrit au prochain envoi
 * de photo et l'utilisateur verrait « Papa » redevenir « M. JEAN DUPONT » sans
 * comprendre pourquoi. C'est ce que verrouille ce fichier.
 */
final class PersonNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'dupont jean',
            'name' => 'Papa',
            'aliases' => ['M. Jean Dupont'],
        ], $overrides);
    }

    #[Test]
    public function un_visiteur_ne_peut_pas_renommer(): void
    {
        $this->postJson('/api/people', $this->payload())->assertUnauthorized();
    }

    #[Test]
    public function renommer_cree_la_personne_si_elle_n_existait_pas(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)
            ->postJson('/api/people', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Papa')
            ->assertJsonPath('data.name_overridden', true);

        $person = Person::query()->firstOrFail();

        $this->assertSame('Papa', $person->display_name);
        $this->assertFalse($person->followsDocuments());
        // La graphie reste memorisee : c'est elle qui permettra de rejouer un
        // changement de normalisation.
        $this->assertSame(['M. Jean Dupont'], $person->aliases()->pluck('raw')->all());
    }

    #[Test]
    public function un_nom_choisi_survit_a_l_envoi_d_une_photo(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)->postJson('/api/people', $this->payload())->assertCreated();

        // L'envoi de photo transporte le nom LU SUR LES DOCUMENTS. Il ne doit
        // pas defaire le choix de l'utilisateur.
        $this->actingAs($alice)
            ->post('/api/people/photo', [
                'key' => 'dupont jean',
                'name' => 'M. JEAN DUPONT',
                'aliases' => ['M. JEAN DUPONT'],
                'photo' => UploadedFile::fake()->image('portrait.jpg', 900, 1200),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Papa');

        $this->assertSame('Papa', Person::query()->firstOrFail()->display_name);
    }

    #[Test]
    public function un_nom_non_choisi_suit_les_documents(): void
    {
        $alice = User::factory()->create();

        $photo = fn (string $name) => [
            'key' => 'dupont jean',
            'name' => $name,
            'aliases' => [$name],
            'photo' => UploadedFile::fake()->image('portrait.jpg', 900, 1200),
        ];

        $this->actingAs($alice)->post('/api/people/photo', $photo('M. Jean Dupont'))->assertCreated();

        // Personne n'a rien choisi : la graphie la plus frequente a change,
        // le nom affiche suit.
        $this->actingAs($alice)
            ->post('/api/people/photo', $photo('DUPONT Jean'))
            ->assertOk()
            ->assertJsonPath('data.name', 'DUPONT Jean');
    }

    #[Test]
    public function renommer_deux_fois_garde_le_dernier_nom(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)->postJson('/api/people', $this->payload())->assertCreated();

        $this->actingAs($alice)
            ->postJson('/api/people', $this->payload(['name' => 'Jean']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Jean');

        $this->assertSame(1, Person::query()->count());
    }

    #[Test]
    public function un_nom_vide_est_refuse(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)
            ->postJson('/api/people', $this->payload(['name' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, Person::query()->count());
    }

    #[Test]
    public function renommer_n_affecte_que_son_propre_compte(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->postJson('/api/people', $this->payload())->assertCreated();
        $this->actingAs($bob)->postJson('/api/people', $this->payload(['name' => 'Tonton']))->assertCreated();

        $this->assertSame(2, Person::query()->count());
        $this->assertSame(
            'Papa',
            Person::query()->where('user_id', $alice->getKey())->value('display_name'),
        );
    }
}
