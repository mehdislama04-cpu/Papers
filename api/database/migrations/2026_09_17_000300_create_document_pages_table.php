<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('page_number');

            // Chemins relatifs dans le disque privé : jamais exposés tels quels
            // au client, qui ne reçoit que des URL signées temporaires.
            $table->string('storage_path');
            $table->string('thumb_path')->nullable();

            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');

            // SHA-256 hexadécimal du fichier : déduplication et détection de
            // réenvoi d'une même page par la file de reprise hors-ligne.
            $table->string('checksum', 64);

            $table->timestamps();

            $table->unique(['document_id', 'page_number']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pages');
    }
};
