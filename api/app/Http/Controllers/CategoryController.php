<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
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

    /**
     * POST /api/categories
     *
     * Crée une catégorie PERSONNELLE (user_id renseigné). Les douze catégories
     * système restent intouchables : elles sont le socle commun et le
     * vocabulaire fermé du modèle d'extraction.
     *
     * Attention : une catégorie créée ici ne reçoit pas encore de classement
     * automatique. L'énumération soumise au modèle est celle des catégories
     * système ; il ne peut donc pas y ranger un document de lui-même. Le
     * déplacement manuel (PATCH /api/documents/{document}) est la voie.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $request->user()->categories()->create([
            'slug' => $request->slug(),
            'name' => $request->categoryName(),
            'color' => $request->input('color') ?: '#64748B',
            'icon' => $request->input('icon') ?: 'folder',
        ]);

        // documents_count : la collection en sert un, la ressource le lit.
        // Une catégorie neuve en a zéro, mais l'absence de clé et la valeur
        // zéro ne se distinguent pas côté front.
        $category->setAttribute('documents_count', 0);

        return CategoryResource::make($category)->response()->setStatusCode(201);
    }
}
