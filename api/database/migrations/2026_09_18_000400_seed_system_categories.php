<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Les douze catégories système, posées par une MIGRATION et non par un
     * seeder.
     *
     * Ce n'est pas l'usage habituel, et c'est assumé. La production a tourné
     * avec une table `categories` vide : l'analyse choisissait bien un slug —
     * le schéma d'extraction l'impose — mais la correspondance ne trouvait
     * rien, et chaque document restait non classé sans le moindre signal. Un
     * seeder qu'il faut penser à lancer après chaque déploiement, sur chaque
     * environnement, finit toujours par ne pas l'être.
     *
     * Ces douze lignes ne sont pas des données de démonstration : ce sont des
     * données de RÉFÉRENCE, au même titre qu'une table de pays. L'application
     * ne fonctionne pas sans elles.
     *
     * Écriture en SQL direct, sans le modèle Eloquent : une migration doit
     * rester lisible dans dix ans, quand App\Models\Category aura changé de
     * casts, d'observers ou de nom.
     *
     * Ajouter une catégorie plus tard : l'ajouter à CategorySeeder et relancer
     * `db:seed --class=CategorySeeder --force`. Cette migration-ci ne rejouera
     * pas, et c'est très bien : elle garantit le socle, elle ne le maintient pas.
     */
    public function up(): void
    {
        $now = now();

        $categories = [
            ['slug' => 'facture', 'name' => 'Facture', 'color' => '#F97316', 'icon' => 'receipt'],
            ['slug' => 'contrat', 'name' => 'Contrat', 'color' => '#6366F1', 'icon' => 'file-signature'],
            ['slug' => 'sante', 'name' => 'Santé', 'color' => '#EF4444', 'icon' => 'heart-pulse'],
            ['slug' => 'impots', 'name' => 'Impôts', 'color' => '#0EA5E9', 'icon' => 'landmark'],
            ['slug' => 'banque', 'name' => 'Banque', 'color' => '#10B981', 'icon' => 'banknote'],
            ['slug' => 'assurance', 'name' => 'Assurance', 'color' => '#8B5CF6', 'icon' => 'shield-check'],
            ['slug' => 'administratif', 'name' => 'Administratif', 'color' => '#64748B', 'icon' => 'building-2'],
            ['slug' => 'scolaire', 'name' => 'Scolaire', 'color' => '#F59E0B', 'icon' => 'graduation-cap'],
            ['slug' => 'immobilier', 'name' => 'Immobilier', 'color' => '#14B8A6', 'icon' => 'home'],
            ['slug' => 'vehicule', 'name' => 'Véhicule', 'color' => '#3B82F6', 'icon' => 'car'],
            ['slug' => 'emploi', 'name' => 'Emploi', 'color' => '#A855F7', 'icon' => 'briefcase'],
            ['slug' => 'autre', 'name' => 'Autre', 'color' => '#9CA3AF', 'icon' => 'folder'],
        ];

        foreach ($categories as $category) {
            // Idempotent : une base qui a déjà reçu le seeder n'est pas touchée,
            // et la migration peut donc arriver après lui sans rien casser.
            $exists = DB::table('categories')
                ->whereNull('user_id')
                ->where('slug', $category['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('categories')->insert($category + [
                'user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Volontairement vide.
     *
     * Supprimer ces lignes mettrait à NULL la catégorie de tous les documents
     * déjà classés (la clé étrangère est en nullOnDelete). Un rollback de
     * schéma ne doit pas détruire du classement.
     */
    public function down(): void
    {
        //
    }
};
