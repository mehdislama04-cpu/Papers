<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Jeton du raccourci iOS : SHA-256 hexadécimal (64 caractères).
            // Le jeton en clair n'existe qu'une fois, dans la réponse à
            // /api/ingest/token ; il transite ensuite dans l'input du
            // raccourci, qui est partagé entre tous les utilisateurs — d'où
            // usage unique, TTL court, et hachage au repos.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            // IP de consommation, à fin de diagnostic uniquement.
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_tokens');
    }
};
