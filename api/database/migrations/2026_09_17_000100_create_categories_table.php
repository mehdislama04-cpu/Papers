<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Première migration du lot : on garantit la présence de pgvector avant
        // que `documents` ne déclare sa colonne `vector(1536)`. Laravel 13 sait
        // le faire nativement, aucun package tiers n'est nécessaire.
        Schema::ensureVectorExtensionExists();

        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // user_id NULL = catégorie système, partagée par tous les comptes
            // et non modifiable. Une catégorie personnalisée appartient à un
            // utilisateur et disparaît avec lui.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('slug', 64);
            $table->string('name', 96);
            $table->string('color', 9);   // #RRGGBB ou #RRGGBBAA
            $table->string('icon', 64);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        // PostgreSQL considère deux NULL comme distincts : la contrainte
        // UNIQUE(user_id, slug) ci-dessus n'empêche PAS deux catégories
        // système ayant le même slug. D'où cet index unique partiel.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX categories_system_slug_unique
                ON categories (slug)
                WHERE user_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
