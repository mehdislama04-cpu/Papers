<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            // Identifiant exact du modèle ayant produit la sortie : indispensable
            // pour rejouer ou comparer après un changement de version.
            $table->string('model', 96);

            // jsonb (et non json) : stockage binaire, opérateurs d'indexation
            // disponibles, et normalisation des clés.
            $table->jsonb('payload');

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            // Coût en millionièmes d'euro, en entier : un decimal flottant sur
            // des montants à 6 décimales accumule des erreurs à l'agrégation.
            $table->bigInteger('cost_micros')->default(0);

            // Pas d'updated_at : une extraction est un enregistrement immuable.
            // Le modèle déclare `const UPDATED_AT = null`.
            $table->timestamp('created_at')->nullable();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extractions');
    }
};
