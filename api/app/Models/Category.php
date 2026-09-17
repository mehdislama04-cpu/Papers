<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie de classement.
 *
 * user_id NULL = catégorie système, servie à tout le monde et non modifiable.
 * user_id renseigné = catégorie créée par l'utilisateur.
 */
#[Fillable(['slug', 'name', 'color', 'icon'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function isSystem(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Catégories système + catégories personnelles de l'utilisateur.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeVisibleTo(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $id));
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }
}
