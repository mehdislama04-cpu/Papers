<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le nom a-t-il été choisi à la main ?
     *
     * Sans ce drapeau, renommer une personne ne tiendrait pas : `display_name`
     * suit la graphie la plus fréquente des documents et serait réécrit au
     * prochain envoi de photo — l'utilisateur verrait son nom revenir tout
     * seul à « M. JEAN DUPONT » sans comprendre pourquoi.
     *
     * Une fois vrai, le nom n'est plus jamais dérivé : il n'appartient plus
     * aux documents, il appartient à l'utilisateur.
     */
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('name_overridden')->default(false)->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('name_overridden');
        });
    }
};
