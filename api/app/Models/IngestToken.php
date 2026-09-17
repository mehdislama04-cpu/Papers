<?php

namespace App\Models;

use Database\Factories\IngestTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeton à usage unique du raccourci iOS.
 *
 * Le raccourci est PARTAGÉ entre tous les utilisateurs : il ne peut donc
 * contenir aucun secret. Tout passe par ce jeton, transmis en input du
 * raccourci puis renvoyé en `Authorization: Bearer`. D'où : usage unique,
 * TTL court, et hachage au repos (seul le hash est stocké, comme un mot de
 * passe — une fuite de la base ne donne aucun jeton utilisable).
 */
#[Fillable(['expires_at'])]
class IngestToken extends Model
{
    /** @use HasFactory<IngestTokenFactory> */
    use HasFactory;

    /**
     * Durée de vie : le temps d'ouvrir Raccourcis, scanner et revenir.
     */
    public const TTL_MINUTES = 15;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hash de stockage. SHA-256 et non bcrypt : le jeton est déjà un secret
     * aléatoire de 40 caractères, il n'a pas besoin d'être ralenti contre la
     * force brute, et la vérification doit pouvoir se faire par un simple
     * index unique (une recherche linéaire avec Hash::check serait à la fois
     * lente et vulnérable au timing).
     */
    public static function hashFor(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Jetons encore consommables : ni utilisés, ni expirés.
     *
     * @param  Builder<IngestToken>  $query
     * @return Builder<IngestToken>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
