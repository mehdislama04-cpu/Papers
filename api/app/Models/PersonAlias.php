<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une graphie sous laquelle une personne a été rencontrée, telle qu'imprimée.
 *
 * Sert à rejouer un changement de normalisation sans perdre les photos
 * (cf. la migration correspondante).
 */
class PersonAlias extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'raw',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
