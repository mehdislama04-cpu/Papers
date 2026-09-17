<?php

namespace Database\Factories;

use App\Enums\TodoStatus;
use App\Models\Document;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
{
    protected $model = Todo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'document_id' => null,
            'title' => fake()->randomElement([
                'Payer la facture',
                'Renvoyer le contrat signé',
                'Déclarer le sinistre',
                'Résilier avant la reconduction',
                'Transmettre la feuille de soins',
            ]),
            'details' => fake()->optional()->sentence(10),
            'due_at' => fake()->dateTimeBetween('now', '+3 months'),
            'all_day' => false,
            'priority' => 0,
            'status' => TodoStatus::Pending,
            'completed_at' => null,
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => TodoStatus::Done,
            'completed_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => ['status' => TodoStatus::Dismissed]);
    }

    /**
     * Échéance sur la journée entière. Le VEVENT correspondant est en
     * VALUE=DATE et son DTEND doit être EXCLUSIF (J+1), sinon iCloud
     * n'affiche rien.
     */
    public function allDay(): static
    {
        return $this->state(fn () => [
            'all_day' => true,
            'due_at' => fake()->dateTimeBetween('now', '+2 months')->setTime(0, 0),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_at' => fake()->dateTimeBetween('-2 months', '-1 day')]);
    }

    public function withoutDueDate(): static
    {
        return $this->state(fn () => ['due_at' => null]);
    }

    public function forDocument(Document $document): static
    {
        return $this->state(fn () => [
            'document_id' => $document->getKey(),
            'user_id' => $document->user_id,
        ]);
    }
}
