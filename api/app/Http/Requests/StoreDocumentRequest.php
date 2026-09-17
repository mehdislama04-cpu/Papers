<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DocumentSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxPages = (int) config('papers.images.max_pages_per_document', 30);
        $maxKb = (int) config('papers.images.max_page_size_kb', 12288);

        return [
            'pages' => ['required', 'array', 'min:1', 'max:'.$maxPages],

            /*
             | mimetypes: et non mimes:.
             |
             | mimes: se fie à l'extension du fichier envoyé ; le raccourci iOS
             | et le sélecteur de photos envoient des noms arbitraires (voire
             | « image.jpg » pour du HEIC). mimetypes: fait deviner le type
             | réel par finfo à partir du contenu.
             |
             | Volontairement PAS de image/heic : depuis Safari 17, annoncer
             | HEIC dans accept= fait renvoyer du HEIC par Safari — l'inverse
             | de l'effet recherché (ARCHITECTURE.md §3). Le front n'accepte
             | que JPEG/PNG, le serveur applique la même règle.
             */
            'pages.*' => [
                'required',
                'file',
                'mimetypes:'.implode(',', (array) config('papers.images.accepted_mimes', ['image/jpeg', 'image/png'])),
                'max:'.$maxKb,
            ],

            'title' => ['sometimes', 'nullable', 'string', 'max:200'],

            // 'shortcut' n'est PAS acceptable ici : cette provenance est
            // réservée à /api/ingest/shortcut, qui s'authentifie autrement.
            'source' => ['sometimes', Rule::in([DocumentSource::Scanner->value, DocumentSource::Import->value])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pages.max' => 'Un document ne peut pas dépasser :max pages.',
            'pages.*.mimetypes' => 'Chaque page doit être une image JPEG ou PNG.',
            'pages.*.max' => 'Chaque page doit peser moins de :max Ko.',
        ];
    }

    public function source(): DocumentSource
    {
        return DocumentSource::tryFrom((string) $this->input('source', DocumentSource::Scanner->value))
            ?? DocumentSource::Scanner;
    }

    public function title(): ?string
    {
        $title = trim((string) $this->input('title', ''));

        return $title === '' ? null : $title;
    }
}
