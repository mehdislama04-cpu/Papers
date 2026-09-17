<?php

namespace App\Models;

use App\Enums\CalendarAccountStatus;
use Database\Factories\CalendarAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compte iCloud CalDAV d'un utilisateur.
 *
 * Le mot de passe d'application est chiffré au repos (cast `encrypted`, donc
 * lié à APP_KEY : perdre la clé rend ces valeurs définitivement illisibles —
 * d'où APP_PREVIOUS_KEYS pour toute rotation). Une colonne chiffrée n'est ni
 * indexable ni interrogeable : elle n'alimente aucun filtre.
 *
 * Il n'est jamais sérialisé vers le client, et aucune requête CalDAV ne part
 * du navigateur — Apple n'envoie pas d'en-têtes CORS, et cela exposerait le
 * mot de passe.
 */
#[Fillable([
    'apple_id',
    'app_password',
    'dsid',
    'principal_url',
    'calendar_home_url',
    'papers_calendar_url',
    'status',
    'last_sync_at',
    'last_error',
])]
#[Hidden(['app_password'])]
class CalendarAccount extends Model
{
    /** @use HasFactory<CalendarAccountFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'app_password' => 'encrypted',
            'status' => CalendarAccountStatus::class,
            'last_sync_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CalendarEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * La découverte en trois PROPFIND a abouti et le calendrier dédié existe.
     */
    public function isReady(): bool
    {
        return $this->status === CalendarAccountStatus::Connected
            && filled($this->papers_calendar_url);
    }

    /**
     * Un 401 iCloud est TERMINAL (mot de passe d'application révoqué, ou mot
     * de passe principal Apple changé, ce qui les révoque tous) : on marque le
     * compte et on cesse de réessayer.
     */
    public function markInvalidCredentials(?string $error = null): void
    {
        $this->forceFill([
            'status' => CalendarAccountStatus::InvalidCredentials,
            'last_error' => $error,
        ])->save();
    }
}
