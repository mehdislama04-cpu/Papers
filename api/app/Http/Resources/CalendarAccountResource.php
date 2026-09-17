<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CalendarAccountStatus;
use App\Models\CalendarAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CalendarAccount
 *
 * NE SÉRIALISE JAMAIS app_password. Le modèle le masque déjà (#[Hidden]),
 * mais une ressource est une liste blanche : le champ n'y est même pas
 * nommable par erreur.
 */
class CalendarAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->statusValue();

        return [
            'id' => $this->getKey(),
            'apple_id' => $this->maskedAppleId(),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'is_ready' => $status === CalendarAccountStatus::Connected->value
                && filled($this->getAttribute('papers_calendar_url')),

            // Utile au support : dit si la découverte en 3 PROPFIND a abouti,
            // sans exposer le DSID ni la partition pNN du compte.
            'discovered' => filled($this->getAttribute('calendar_home_url')),
            'calendar_name' => config('papers.caldav.calendar_name'),

            'last_sync_at' => $this->last_sync_at?->toAtomString(),
            'last_error' => $this->getAttribute('last_error'),

            'created_at' => $this->created_at?->toAtomString(),
        ];
    }

    /**
     * Lecture DÉFENSIVE du statut.
     *
     * La colonne est castée vers App\Enums\CalendarAccountStatus, mais
     * App\Services\CalDav\CalDavClient y écrit ses propres constantes
     * (STATUS_OK = 'ok'), qui ne font pas partie de l'enum : un accès direct à
     * $this->status lèverait alors un ValueError et renverrait un 500 sur une
     * simple lecture de compte. On lit donc la valeur brute et on la
     * normalise. (Divergence signalée au lot CalDAV.)
     */
    private function statusValue(): string
    {
        $raw = $this->getRawOriginal('status') ?? $this->getAttributes()['status'] ?? null;

        if ($raw instanceof CalendarAccountStatus) {
            return $raw->value;
        }

        return match ((string) $raw) {
            'ok', 'connected' => CalendarAccountStatus::Connected->value,
            'invalid_credentials' => CalendarAccountStatus::InvalidCredentials->value,
            '' => CalendarAccountStatus::Pending->value,
            default => (string) $raw,
        };
    }

    private function statusLabel(string $status): string
    {
        return CalendarAccountStatus::tryFrom($status)?->label() ?? 'Erreur de synchronisation';
    }

    /**
     * L'identifiant Apple est une donnée personnelle : la PWA n'a besoin que
     * de le reconnaître, pas de l'afficher en entier.
     */
    private function maskedAppleId(): ?string
    {
        $appleId = (string) ($this->getAttribute('apple_id') ?? '');

        if ($appleId === '' || ! str_contains($appleId, '@')) {
            return $appleId === '' ? null : $appleId;
        }

        [$local, $domain] = explode('@', $appleId, 2);

        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
