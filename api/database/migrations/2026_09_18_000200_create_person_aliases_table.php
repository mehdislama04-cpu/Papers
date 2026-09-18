<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les graphies sous lesquelles une personne a été vue.
     *
     * « M. Jean Dupont », « DUPONT Jean », « jean dupont » : trois lignes ici,
     * une seule personne. Ce n'est pas de la décoration.
     *
     * `people.match_key` est le produit d'une normalisation qui vit dans le
     * front et qui évoluera (une civilité oubliée, un tiret, une particule).
     * Le jour où elle change, les clés changent, et une photo rattachée à
     * l'ancienne clé serait perdue — silencieusement. Garder les graphies
     * brutes rend ce changement rejouable : on recalcule la clé à partir des
     * alias et on remappe. Sans elles, seule une suppression manuelle resterait.
     */
    public function up(): void
    {
        Schema::create('person_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();

            // La graphie TELLE QU'IMPRIMÉE sur le document, sans retouche.
            // C'est tout l'intérêt : une valeur normalisée ne servirait à rien
            // pour rejouer une normalisation.
            $table->string('raw');

            $table->timestamps();

            $table->unique(['person_id', 'raw']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_aliases');
    }
};
