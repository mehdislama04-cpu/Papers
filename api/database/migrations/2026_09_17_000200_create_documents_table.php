<?php

use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            // UUID v7 (trait HasUuids) : ordonné dans le temps, donc un index
            // btree qui ne se fragmente pas, contrairement à l'UUID v4.
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');

            // Enums portés côté PHP (App\Enums\*) plutôt que par un type
            // PostgreSQL : ajouter une valeur à un type ENUM natif impose un
            // ALTER TYPE non transactionnel, pénible en migration.
            $table->string('status', 16)->default(DocumentStatus::Pending->value);
            $table->string('source', 16)->default(DocumentSource::Scanner->value);

            // Nom de fichier tel qu'il a été reçu. Jamais utilisé comme chemin :
            // le raccourci iOS renvoie systématiquement « Scanned Document.pdf ».
            $table->string('original_filename')->nullable();

            $table->unsignedSmallInteger('page_count')->default(0);
            $table->string('language', 8)->nullable();
            $table->text('summary')->nullable();

            $table->date('doc_date')->nullable();
            $table->string('issuer')->nullable();
            $table->string('recipient')->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('reference')->nullable();

            // Texte OCR brut : entrée HOSTILE (injection de prompt). Il est
            // stocké tel quel, mais n'est encadré comme donnée qu'au moment de
            // l'appel modèle, et ne déclenche jamais d'action privilégiée.
            $table->text('raw_text')->nullable();

            $table->text('analysis_error')->nullable();
            $table->timestamp('analyzed_at')->nullable();

            // text-embedding-3-small : 1536 dimensions. La variante -large
            // (3072) dépasse la limite de 2000 dimensions d'un index HNSW
            // pgvector, l'index serait donc impossible à créer.
            $table->vector('embedding', dimensions: 1536)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'doc_date']);
            $table->index(['user_id', 'created_at']);

            // ->vectorIndex() et NON ->index() : ->index() produirait un index
            // btree inutile sur une colonne vector, alors que vectorIndex()
            // génère un index hnsw avec vector_cosine_ops, cohérent avec
            // l'opérateur <=> émis par orderByVectorDistance().
            $table->vectorIndex('embedding');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
