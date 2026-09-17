<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            // Par défaut une catégorie appartient à un utilisateur ; les
            // catégories système se demandent explicitement avec ->system().
            'user_id' => User::factory(),
            'slug' => Str::slug($name),
            'name' => Str::ucfirst($name),
            'color' => fake()->hexColor(),
            'icon' => fake()->randomElement([
                'file-text', 'receipt', 'heart-pulse', 'landmark', 'banknote',
                'shield-check', 'building-2', 'graduation-cap', 'home', 'car',
                'briefcase', 'folder',
            ]),
        ];
    }

    /**
     * Catégorie système : partagée par tous les comptes, non modifiable.
     */
    public function system(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
