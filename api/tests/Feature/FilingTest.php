<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Classement : creation d'une categorie, et deplacement d'un document.
 *
 * Le classement automatique reste la regle. Ces deux gestes couvrent les cas ou
 * la machine ne suffit pas — un rangement qu'elle ne connait pas, un document
 * qu'elle a mal place.
 */
final class FilingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Les douze categories systeme. Leur absence est exactement ce qui
        // faisait qu'aucun document n'etait classe en production.
        $this->seed(CategorySeeder::class);
    }

    #[Test]
    public function le_socle_de_categories_est_servi(): void
    {
        $alice = User::factory()->create();

        $response = $this->actingAs($alice)->getJson('/api/categories')->assertOk();

        $this->assertCount(12, $response->json('data'));
        $this->assertContains('facture', array_column($response->json('data'), 'slug'));
    }

    #[Test]
    public function un_visiteur_ne_peut_pas_creer_de_categorie(): void
    {
        $this->postJson('/api/categories', ['name' => 'Voyages'])->assertUnauthorized();
    }

    #[Test]
    public function creer_une_categorie_la_rattache_a_son_auteur(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice)
            ->postJson('/api/categories', ['name' => 'Copropriété', 'color' => '#DF6A0C', 'icon' => 'home'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'copropriete')
            ->assertJsonPath('data.name', 'Copropriété')
            // Personnelle, donc modifiable — le front doit pouvoir le savoir.
            ->assertJsonPath('data.is_system', false);

        $this->assertSame(
            (int) $alice->getKey(),
            (int) Category::query()->where('slug', 'copropriete')->value('user_id'),
        );
    }

    #[Test]
    public function une_categorie_ne_peut_pas_doubler_une_categorie_systeme(): void
    {
        $alice = User::factory()->create();

        // « Facture » existe deja dans le socle : deux categories de meme slug
        // rendraient l'extraction ambigue.
        $this->actingAs($alice)
            ->postJson('/api/categories', ['name' => 'Facture'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function deux_utilisateurs_peuvent_avoir_la_meme_categorie_personnelle(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->postJson('/api/categories', ['name' => 'Voyages'])->assertCreated();
        $this->actingAs($bob)->postJson('/api/categories', ['name' => 'Voyages'])->assertCreated();

        $this->assertSame(2, Category::query()->where('slug', 'voyages')->count());
    }

    #[Test]
    public function la_categorie_d_un_autre_n_est_pas_visible(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->postJson('/api/categories', ['name' => 'Voyages'])->assertCreated();

        $slugs = array_column($this->actingAs($bob)->getJson('/api/categories')->json('data'), 'slug');

        $this->assertNotContains('voyages', $slugs);
    }

    #[Test]
    public function deplacer_un_document_change_sa_categorie(): void
    {
        $alice = User::factory()->create();
        $document = Document::factory()->for($alice)->create(['category_id' => null]);

        $this->actingAs($alice)
            ->patchJson("/api/documents/{$document->getKey()}", ['category' => 'vehicule'])
            ->assertOk()
            ->assertJsonPath('data.category.slug', 'vehicule');
    }

    #[Test]
    public function un_document_peut_redevenir_sans_categorie(): void
    {
        $alice = User::factory()->create();
        $facture = Category::query()->system()->where('slug', 'facture')->firstOrFail();
        $document = Document::factory()->for($alice)->create(['category_id' => $facture->getKey()]);

        // « Sans categorie » est un etat legitime, pas une absence de reponse.
        $this->actingAs($alice)
            ->patchJson("/api/documents/{$document->getKey()}", ['category' => null])
            ->assertOk();

        $this->assertNull($document->refresh()->category_id);
    }

    #[Test]
    public function on_ne_peut_pas_ranger_dans_la_categorie_d_un_autre(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($bob)->postJson('/api/categories', ['name' => 'Voyages'])->assertCreated();

        $document = Document::factory()->for($alice)->create(['category_id' => null]);

        $this->actingAs($alice)
            ->patchJson("/api/documents/{$document->getKey()}", ['category' => 'voyages'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    #[Test]
    public function on_ne_peut_pas_deplacer_le_document_d_un_autre(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $document = Document::factory()->for($alice)->create(['category_id' => null]);

        $this->actingAs($bob)
            ->patchJson("/api/documents/{$document->getKey()}", ['category' => 'vehicule'])
            ->assertForbidden();
    }
}
