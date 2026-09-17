<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<Todo, $this> */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    /**
     * Catégories PERSONNELLES uniquement. Les catégories système
     * (user_id NULL) sont exposées par Category::visibleTo().
     *
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Un seul compte iCloud par utilisateur (contrainte unique sur user_id).
     *
     * @return HasOne<CalendarAccount, $this>
     */
    public function calendarAccount(): HasOne
    {
        return $this->hasOne(CalendarAccount::class);
    }

    /** @return HasMany<IngestToken, $this> */
    public function ingestTokens(): HasMany
    {
        return $this->hasMany(IngestToken::class);
    }
}
