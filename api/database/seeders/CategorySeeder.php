<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Catégories système (user_id NULL) : socle commun à tous les comptes.
 *
 * Extrait de DatabaseSeeder pour pouvoir être lancé SEUL en production :
 *
 *     php artisan db:seed --class=CategorySeeder --force
 *
 * DatabaseSeeder garde bien le jeu de démonstration derrière un test
 * d'environnement, mais personne n'ose lancer un seeder nommé « database » sur
 * une base vivante — et c'est ainsi qu'une prod se retrouve sans aucune
 * catégorie : l'analyse choisit un slug, la correspondance ne trouve rien, et
 * chaque document reste non classé sans que rien ne le signale.
 *
 * Le slug est l'identifiant stable manipulé par le front, par le filtre
 * `?category=` et par le modèle d'extraction ; il est volontairement sans
 * accent ni espace. Le libellé, lui, est affiché tel quel.
 */
class CategorySeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var list<array{slug: string, name: string, color: string, icon: string}> */
    public const CATEGORIES = [
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

    /**
     * Idempotent : rejouable après l'ajout d'une catégorie sans dupliquer les
     * existantes (index unique partiel sur slug WHERE user_id IS NULL).
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            Category::query()->updateOrCreate(
                ['user_id' => null, 'slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'color' => $category['color'],
                    'icon' => $category['icon'],
                ],
            );
        }
    }
}
