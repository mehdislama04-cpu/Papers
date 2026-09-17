<?php

use App\Enums\CalendarAccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_accounts', function (Blueprint $table) {
            $table->id();

            // Un seul compte iCloud par utilisateur : l'API expose
            // /api/calendar/account au singulier.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('apple_id');

            // Mot de passe d'application, cast `encrypted` côté modèle. `text`
            // et non `string` : le chiffrement Laravel produit un payload JSON
            // base64 bien plus long que les 16 caractères d'origine.
            // Une colonne chiffrée n'est ni indexable ni interrogeable — elle
            // n'alimente donc aucun filtre ni search_vector.
            $table->text('app_password');

            // Renvoyés par la découverte CalDAV. Le calendar-home-set pointe
            // vers une PARTITION numérotée (https://pNN-caldav.icloud.com/...)
            // propre au compte : elle doit être persistée, sinon chaque appel
            // repasse par une redirection inter-hôtes qui perd l'en-tête
            // Authorization (Guzzle, CVE-2022-31090).
            $table->string('dsid', 64)->nullable();
            $table->string('principal_url')->nullable();
            $table->string('calendar_home_url')->nullable();
            $table->string('papers_calendar_url')->nullable();

            $table->string('status', 32)->default(CalendarAccountStatus::Pending->value);
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_accounts');
    }
};
