<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'user_id' => User::factory(),
            'slug' => Str::slug($name),
            'name' => Str::ucfirst($name),
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn () => [
            'name' => $name,
            'slug' => Tag::slugFor($name),
        ]);
    }
}
