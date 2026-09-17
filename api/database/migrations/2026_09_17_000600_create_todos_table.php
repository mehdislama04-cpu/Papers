<?php

use App\Enums\TodoStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            // UUID : il alimente l'UID déterministe du VEVENT iCloud
            // (papers-todo-{uuid}@papers.app), qui doit survivre à une purge
            // locale et rester stable entre deux synchronisations.
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Une tâche peut exister sans document (ajout manuel), et survit à
            // la suppression de son document d'origine.
            $table->foreignUuid('document_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('details')->nullable();

            // timestamptz : l'échéance part vers iCloud, où elle sera relue
            // dans le fuseau de l'appareil. Un timestamp sans fuseau ferait
            // dériver l'événement au passage à l'heure d'hiver.
            $table->timestampTz('due_at')->nullable();

            // all_day : le VEVENT est alors en VALUE=DATE, et son DTEND doit
            // être EXCLUSIF (J+1) sinon iCloud n'affiche rien.
            $table->boolean('all_day')->default(false);

            $table->smallInteger('priority')->default(0);
            $table->string('status', 16)->default(TodoStatus::Pending->value);
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_at']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
