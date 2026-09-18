<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Première pierre de l'entité « personne ».
     *
     * Jusqu'ici les personnes n'existaient qu'à l'exécution : le front groupait
     * les documents par destinataire normalisé et rien n'en survivait au
     * rechargement (cf. resources/js/lib/people.ts). Une photo, elle, doit être
     * rattachée à quelque chose de durable — d'où cette table.
     *
     * Elle est volontairement MINIMALE : pas encore de `documents.person_id`,
     * donc aucun rattachement n'est imposé au pipeline d'analyse. Le
     * regroupement reste calculé côté client ; la table ne porte que ce que
     * l'utilisateur a explicitement décidé (aujourd'hui : une photo). Le jour
     * où le volume dépassera la page de cent documents, c'est ici que
     * viendront le rattachement et le regroupement côté serveur.
     */
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            // UUID v7 (trait HasUuids), comme documents : ordonné dans le
            // temps, donc un index btree qui ne se fragmente pas.
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             | Clé de rapprochement : le nom du destinataire débarrassé de la
             | civilité, des accents, de la casse et de l'ordre des mots.
             |
             | Elle est calculée par le CLIENT (normalizeRecipient), parce que
             | c'est lui qui groupe. Le serveur la stocke telle quelle plutôt
             | que de dupliquer la normalisation : deux implémentations d'une
             | même règle finissent toujours par diverger, et une divergence
             | ici détacherait silencieusement les photos.
             |
             | 191 et non 255 : longueur d'index sûre quel que soit le moteur.
             */
            $table->string('match_key', 191);

            // Nom tel qu'on l'affiche : la graphie la plus fréquente au moment
            // où la personne a été créée. Rafraîchi à chaque envoi de photo.
            $table->string('display_name');

            // Chemin sur le disque privé « documents ». Jamais exposé : le
            // fichier est servi par une route signée qui repasse par la policy.
            $table->string('photo_path')->nullable();
            $table->timestamp('photo_updated_at')->nullable();

            $table->timestamps();

            // Une personne par clé et par utilisateur. C'est cette contrainte
            // qui rend l'envoi de photo idempotent : réenvoyer met à jour.
            $table->unique(['user_id', 'match_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
