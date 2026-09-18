<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une personne, c'est-à-dire aujourd'hui un destinataire de documents.
 *
 * Volontairement pauvre : la table ne porte que ce que l'utilisateur a décidé
 * lui-même (sa photo) et de quoi la retrouver (la clé de rapprochement et les
 * graphies rencontrées). Tout le reste — quels documents lui appartiennent,
 * comment se répartissent les catégories — reste calculé à partir des
 * documents, donc toujours juste, sans avoir à maintenir une copie.
 *
 * `photo_path` et `photo_updated_at` ne sont pas dans $fillable : ils ne
 * viennent jamais d'une requête, seulement de l'écriture du fichier.
 */
class Person extends Model
{
    use HasUuids;

    protected $table = 'people';

    /** @var list<string> */
    protected $fillable = [
        'match_key',
        'display_name',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'photo_updated_at' => 'datetime',
            'name_overridden' => 'boolean',
        ];
    }

    /**
     * Le nom suit-il encore les documents ?
     *
     * Tant qu'il n'a pas été choisi à la main, il vaut la graphie la plus
     * fréquente et se rafraîchit tout seul. Dès qu'il l'a été, il est figé :
     * le réécrire depuis un document reviendrait à défaire, en silence, ce que
     * l'utilisateur vient de décider.
     */
    public function followsDocuments(): bool
    {
        return $this->getAttribute('name_overridden') !== true;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(PersonAlias::class);
    }

    public function hasPhoto(): bool
    {
        return is_string($this->getAttribute('photo_path'))
            && $this->getAttribute('photo_path') !== '';
    }
}
