<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Étiquette libre proposée par le modèle, propre à un utilisateur.
 */
#[Fillable(['slug', 'name'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Document, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class);
    }

    /**
     * Slug normalisé servant de clé de déduplication : « Impôts 2026 » et
     * « impots 2026 » doivent retomber sur le même tag.
     */
    public static function slugFor(string $name): string
    {
        return Str::limit(Str::slug($name), 64, '');
    }
}
