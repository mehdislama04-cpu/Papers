<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'title' => $this->title,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_terminal' => $this->status->isTerminal(),

            'source' => $this->source->value,
            'source_label' => $this->source->label(),

            'page_count' => $this->page_count,
            'language' => $this->language,
            'summary' => $this->summary,

            'doc_date' => $this->doc_date?->toDateString(),
            'issuer' => $this->issuer,
            'recipient' => $this->recipient,

            // decimal:2 => chaîne côté Eloquent. On la laisse en chaîne : un
            // float JSON perdrait des centimes sur les gros montants, et le
            // front formate lui-même.
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'reference' => $this->reference,

            'analysis_error' => $this->analysis_error,
            'analyzed_at' => $this->analyzed_at?->toAtomString(),

            'category' => $this->when(
                $this->relationLoaded('category') && $this->category !== null,
                fn () => CategoryResource::make($this->category),
            ),

            // En index, `pages` est volontairement limité à la première page
            // (la couverture). En détail, toutes les pages sont chargées.
            'pages' => DocumentPageResource::collection($this->whenLoaded('pages')),
            'todos' => TodoResource::collection($this->whenLoaded('todos')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),

            'todos_count' => $this->whenCounted('todos'),

            // raw_text peut peser plusieurs centaines de kilo-octets sur un
            // document de 30 pages : il n'est servi que sur la fiche.
            'raw_text' => $this->when($request->routeIs('documents.show'), fn () => $this->raw_text),

            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
