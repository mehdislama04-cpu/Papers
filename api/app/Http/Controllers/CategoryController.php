<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     *
     * Catégories système (user_id NULL, socle commun) + catégories
     * personnelles de l'utilisateur. Le slug est l'identifiant stable :
     * c'est lui que manipulent le front (filtre ?category=) et le modèle
     * d'extraction.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->visibleTo($request->user())
            ->withCount(['documents' => fn ($query) => $query->where('user_id', $request->user()->getKey())])
            // Les catégories personnelles d'abord : ce sont celles que
            // l'utilisateur a créées, donc celles qu'il cherche.
            ->orderByRaw('user_id is null')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }
}
