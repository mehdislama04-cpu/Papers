<?php

use App\Enums\CalendarSyncStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('todo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_account_id')->constrained()->cascadeOnDelete();

            // UID déterministe « papers-todo-{uuid}@papers.app ». C'est lui qui
            // rend le PUT idempotent : rejouer une synchronisation écrase le
            // même objet au lieu d'en créer un second.
            $table->string('uid')->unique();

            // ETag renvoyé par iCloud : envoyé en If-Match à la mise à jour
            // pour ne pas écraser une modification faite depuis l'iPhone.
            $table->string('etag')->nullable();
            $table->string('href')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->string('sync_status', 16)->default(CalendarSyncStatus::Pending->value);
            $table->text('last_error')->nullable();

            $table->timestamps();

            // Une tâche n'a qu'un événement par compte calendrier.
            $table->unique(['todo_id', 'calendar_account_id']);
            $table->index(['calendar_account_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
