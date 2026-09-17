<?php

namespace Database\Factories;

use App\Enums\CalendarAccountStatus;
use App\Models\CalendarAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarAccount>
 */
class CalendarAccountFactory extends Factory
{
    protected $model = CalendarAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'apple_id' => fake()->unique()->safeEmail(),
            // Format d'un mot de passe d'application Apple : 4 groupes de 4
            // minuscules séparés par des tirets. Valeur factice, chiffrée par
            // le cast `encrypted` à l'écriture.
            'app_password' => implode('-', [
                fake()->lexify('????'), fake()->lexify('????'),
                fake()->lexify('????'), fake()->lexify('????'),
            ]),
            'dsid' => null,
            'principal_url' => null,
            'calendar_home_url' => null,
            'papers_calendar_url' => null,
            'status' => CalendarAccountStatus::Pending,
            'last_sync_at' => null,
            'last_error' => null,
        ];
    }

    /**
     * Compte dont la découverte CalDAV a abouti. Le calendar-home-set pointe
     * vers une partition numérotée pNN, propre au compte : c'est bien cette
     * URL-là qu'il faut persister, pas https://caldav.icloud.com.
     */
    public function connected(): static
    {
        return $this->state(function () {
            $dsid = (string) fake()->numberBetween(100_000_000, 999_999_999);
            $partition = str_pad((string) fake()->numberBetween(1, 99), 2, '0', STR_PAD_LEFT);
            $home = "https://p{$partition}-caldav.icloud.com/{$dsid}/calendars/";

            return [
                'dsid' => $dsid,
                'principal_url' => "https://caldav.icloud.com/{$dsid}/principal/",
                'calendar_home_url' => $home,
                'papers_calendar_url' => $home.fake()->uuid().'/',
                'status' => CalendarAccountStatus::Connected,
                'last_sync_at' => now(),
            ];
        });
    }

    /**
     * État terminal : 401 iCloud, mot de passe d'application révoqué.
     */
    public function invalidCredentials(): static
    {
        return $this->state(fn () => [
            'status' => CalendarAccountStatus::InvalidCredentials,
            'last_error' => 'HTTP 401 renvoyé par iCloud lors du PROPFIND initial.',
        ]);
    }
}
