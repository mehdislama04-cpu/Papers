<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'slug' => $this->slug,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            // Une catégorie système (user_id NULL) est servie à tout le monde
            // et n'est pas modifiable : le front doit pouvoir le savoir.
            'is_system' => $this->user_id === null,
            'documents_count' => $this->whenCounted('documents'),
        ];
    }
}
