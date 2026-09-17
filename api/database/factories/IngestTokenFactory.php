<?php

namespace Database\Factories;

use App\Models\IngestToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IngestToken>
 */
class IngestTokenFactory extends Factory
{
    protected $model = IngestToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Le jeton en clair n'est jamais stocké : seul son SHA-256 l'est.
        $plain = Str::random(40);

        return [
            'user_id' => User::factory(),
            'token_hash' => IngestToken::hashFor($plain),
            'expires_at' => now()->addMinutes(IngestToken::TTL_MINUTES),
            'used_at' => null,
            'ip' => null,
        ];
    }

    /**
     * Fixe le jeton en clair, pour pouvoir le rejouer dans un test.
     */
    public function withPlainToken(string $plain): static
    {
        return $this->state(fn () => ['token_hash' => IngestToken::hashFor($plain)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn () => [
            'used_at' => now(),
            'ip' => fake()->ipv4(),
        ]);
    }
}
