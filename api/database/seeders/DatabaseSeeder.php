<?php

namespace Database\Seeders;

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Les catégories vivent dans leur propre seeder : c'est LUI qu'on lance
        // en production (db:seed --class=CategorySeeder --force), sans risquer
        // d'appeler au passage le jeu de démonstration.
        $this->call(CategorySeeder::class);

        // Le jeu de démonstration n'a rien à faire en production.
        if (! app()->environment('production')) {
            $this->seedDemoUser();
        }
    }


    private function seedDemoUser(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'demo@papers.test'],
            [
                'name' => 'Démo Papers',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        // Ne rien réempiler si la démo a déjà été semée.
        if ($user->documents()->exists()) {
            return;
        }

        $facture = Category::query()->system()->where('slug', 'facture')->first();
        $assurance = Category::query()->system()->where('slug', 'assurance')->first();

        $edf = $user->documents()->create([
            'category_id' => $facture?->getKey(),
            'title' => 'Facture EDF — mars 2026',
            'status' => DocumentStatus::Analyzed,
            'source' => DocumentSource::Scanner,
            'page_count' => 1,
            'language' => 'fr',
            'summary' => 'Facture d’électricité pour la période du 1er au 31 mars 2026, prélèvement automatique le 15 avril.',
            'doc_date' => '2026-04-02',
            'issuer' => 'EDF',
            'recipient' => 'Démo Papers',
            'total_amount' => 128.44,
            'currency' => 'EUR',
            'reference' => 'FR-20260402-8891',
            'raw_text' => "EDF\nFacture d'électricité\nPériode du 01/03/2026 au 31/03/2026\n"
                ."Montant TTC : 128,44 EUR\nRéférence client : FR-20260402-8891\n"
                .'Prélèvement automatique le 15/04/2026.',
            'analyzed_at' => now(),
        ]);

        DocumentPage::factory()->for($edf)->create();

        $mutuelle = $user->documents()->create([
            'category_id' => $assurance?->getKey(),
            'title' => 'Contrat mutuelle santé',
            'status' => DocumentStatus::Analyzed,
            'source' => DocumentSource::Shortcut,
            'original_filename' => 'Scanned Document.pdf',
            'page_count' => 2,
            'language' => 'fr',
            'summary' => 'Contrat de complémentaire santé, reconduction tacite au 31 décembre 2026, résiliation possible deux mois avant.',
            'doc_date' => '2026-01-12',
            'issuer' => 'MAIF',
            'recipient' => 'Démo Papers',
            'reference' => 'MUT-4471-C',
            'raw_text' => "MAIF\nContrat de complémentaire santé\nNuméro : MUT-4471-C\n"
                ."Reconduction tacite au 31/12/2026.\nRésiliation possible jusqu'au 31/10/2026.",
            'analyzed_at' => now(),
        ]);

        DocumentPage::factory()->for($mutuelle)->count(2)
            ->sequence(['page_number' => 1], ['page_number' => 2])
            ->create();

        // Les échéances sont des PROPOSITIONS issues de l'analyse : elles
        // restent locales tant que l'utilisateur n'a pas connecté de compte
        // iCloud, et aucune n'est poussée automatiquement sans décision
        // applicative explicite.
        $user->todos()->create([
            'document_id' => $edf->getKey(),
            'title' => 'Vérifier le prélèvement EDF',
            'details' => 'Prélèvement automatique de 128,44 € annoncé le 15/04/2026.',
            'due_at' => now()->addWeeks(2),
            'all_day' => true,
            'priority' => 0,
        ]);

        $user->todos()->create([
            'document_id' => $mutuelle->getKey(),
            'title' => 'Résilier la mutuelle avant reconduction',
            'details' => 'Date limite de résiliation : 31/10/2026.',
            'due_at' => now()->addMonths(2),
            'all_day' => true,
            'priority' => 2,
        ]);

        // Une tâche sans document, pour couvrir le cas de la saisie manuelle.
        Todo::factory()->for($user)->withoutDueDate()->create([
            'title' => 'Classer les papiers de la voiture',
        ]);
    }
}
