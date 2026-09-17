<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();

            // Les tags sont proposés par le modèle à partir du contenu d'un
            // document : ils sont donc toujours propres à un utilisateur,
            // contrairement aux catégories qui ont un socle système.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('slug', 64);
            $table->string('name', 96);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('document_tag', function (Blueprint $table) {
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['document_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_tag');
        Schema::dropIfExists('tags');
    }
};
