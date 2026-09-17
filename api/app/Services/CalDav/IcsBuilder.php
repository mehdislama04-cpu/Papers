<?php

declare(strict_types=1);

namespace App\Services\CalDav;

use App\Models\Todo;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use Sabre\VObject\Component\VCalendar;

/**
 * Construction de l'ICS poussé dans iCloud (sabre/vobject 4.6.1).
 *
 * Rappel d'architecture : les Rappels iCloud (VTODO) ne sont plus accessibles
 * via CalDAV depuis iOS 13 (Apple a migré Rappels vers un store privé CloudKit).
 * Un Todo Papers est donc projeté en VEVENT + VALARM dans un calendrier dédié.
 * Postgres reste la source de vérité, iCloud n'est qu'une projection.
 */
class IcsBuilder
{
    /**
     * Fuseau de référence de l'application. Il sert à deux choses :
     *  1. décider de la DATE (civile) d'une échéance « journée entière » ;
     *  2. convertir une échéance horodatée en instant UTC.
     *
     * On sérialise ensuite en UTC (suffixe Z) plutôt qu'en TZID=Europe/Paris :
     * tout TZID référencé dans un DTSTART DOIT être accompagné du composant
     * VTIMEZONE correspondant dans le même VCALENDAR, sinon iCloud rejette (403)
     * ou décale l'heure — et vobject 4 ne génère pas les VTIMEZONE tout seul.
     * L'UTC donne le même instant, affiché par iOS dans le fuseau de l'appareil.
     * (TZID + VTIMEZONE ne serait nécessaire que pour du récurrent devant garder
     * l'heure murale à travers les changements d'heure : nos échéances ne le sont pas.)
     */
    public const TIMEZONE = 'Europe/Paris';

    public const PRODID = '-//BWA Agence//Papers 1.0//FR';

    /**
     * Namespace UUIDv5 figé une fois pour toutes : le changer casserait
     * l'idempotence de tous les événements déjà poussés.
     */
    private const UID_NAMESPACE = '6ba7b814-9dad-11d1-80b4-00c04fd430c8';

    /** Durée d'un événement horodaté sans durée explicite. */
    private const DEFAULT_DURATION_MINUTES = 30;

    /**
     * UID déterministe : papers-todo-{uuid}@papers.app
     *
     * C'est LA clé de l'idempotence. Le même Todo re-synchronisé (ou un document
     * ré-analysé qui regénère la même tâche) produit le même UID, donc le même
     * href .ics, donc un PUT idempotent au lieu d'un doublon dans le calendrier.
     *
     * On préfère la colonne uuid du Todo si elle existe ; sinon on dérive un
     * UUIDv5 stable de (user_id, document_id, id). Aucun caractère hors
     * hexadécimal + tirets n'entre dans l'UID : il sert aussi de nom de fichier.
     */
    public static function uidFor(Todo $todo): string
    {
        $uuid = strtolower(trim((string) ($todo->getAttribute('uuid') ?? '')));

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            $uuid = Uuid::uuid5(self::UID_NAMESPACE, sprintf(
                '%s:%s:%s',
                (string) ($todo->getAttribute('user_id') ?? '0'),
                (string) ($todo->getAttribute('document_id') ?? '0'),
                (string) ($todo->getKey() ?? '0'),
            ))->toString();
        }

        return 'papers-todo-'.$uuid.'@papers.app';
    }

    /**
     * Sérialise un Todo en VCALENDAR complet, prêt pour le PUT CalDAV.
     *
     * $sequence doit être incrémenté à chaque mise à jour : sans cela, certains
     * clients ignorent purement et simplement la nouvelle version.
     */
    public function buildTodoEvent(Todo $todo, int $sequence = 0): string
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');

        $due = $this->dueAt($todo);

        if ($due === null) {
            throw CalDavException::protocol(
                'Todo #'.((string) $todo->getKey()).' sans due_at : rien à projeter dans le calendrier.'
            );
        }

        $summary = $this->summary($todo);
        $description = $this->description($todo);

        $vcal = new VCalendar([
            'PRODID' => self::PRODID,
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
        ]);
        // Pas de METHOD:PUBLISH : c'est réservé à l'iTIP/email, iCloud le refuse
        // sur un PUT CalDAV. Pas d'ORGANIZER/ATTENDEE non plus : ils déclencheraient
        // le scheduling iCloud, donc l'envoi de vraies invitations.

        $event = $vcal->add('VEVENT', [
            'UID' => self::uidFor($todo),
            // DTSTAMP toujours en UTC, et distinct de LAST-MODIFIED.
            'DTSTAMP' => new \DateTimeImmutable('now', $utc),
            'SUMMARY' => $summary,
            'DESCRIPTION' => $description,
            'SEQUENCE' => max(0, $sequence),
            'STATUS' => 'CONFIRMED',
            // Une échéance ne rend pas occupé : elle ne doit pas bloquer la dispo.
            'TRANSP' => 'TRANSPARENT',
            'CATEGORIES' => 'Papers',
        ]);

        if (($priority = $this->priority($todo)) !== null) {
            $event->add('PRIORITY', $priority);
        }

        if (($url = $this->documentUrl($todo)) !== null) {
            $event->add('URL', $url, ['VALUE' => 'URI']);
        }

        $date = $this->allDayDate($todo, $due, $tz);

        if ($date !== null) {
            $event->add('DTSTART', $date->format('Ymd'), ['VALUE' => 'DATE']);
            // DTEND d'un all-day est EXCLUSIF (J+1). Mettre la même date que
            // DTSTART produit un événement de durée nulle, INVISIBLE dans iCloud.
            $event->add('DTEND', $date->addDay()->format('Ymd'), ['VALUE' => 'DATE']);

            // Sur un all-day, TRIGGER est relatif à minuit (heure locale de l'appareil).
            // -PT1H  => 23 h la veille ; -PT15H => 9 h la veille.
            $this->addAlarm($event, '-PT1H', $summary);
            $this->addAlarm($event, '-PT15H', 'Demain : '.$summary);
        } else {
            $start = $due->setTimezone($utc);
            $minutes = $this->durationMinutes($todo);

            // DTSTART:20261015T080000Z — UTC, donc aucun VTIMEZONE requis.
            $event->add('DTSTART', $start->toDateTimeImmutable());
            // DTEND ou DURATION, jamais les deux.
            $event->add('DTEND', $start->addMinutes($minutes)->toDateTimeImmutable());

            $this->addAlarm($event, '-PT1H', $summary);
        }

        // serialize() de vobject 4.6.1 : CRLF partout (y compris la dernière ligne)
        // et pliage à 75 OCTETS avec garde (?![\x80-\xbf]) qui empêche de couper
        // un caractère UTF-8 multi-octets (un « é » pèse 2 octets). Vérifié dans
        // vendor/sabre/vobject/lib/Property.php::serialize().
        return $vcal->serialize();
    }

    /**
     * ACTION:DISPLAY impose un DESCRIPTION non vide (RFC 5545), sans quoi
     * l'alarme est ignorée. RELATED=START est explicite pour lever toute
     * ambiguïté d'interprétation côté client.
     */
    private function addAlarm(object $event, string $trigger, string $text): void
    {
        $alarm = $event->add('VALARM', [
            'ACTION' => 'DISPLAY',
            'DESCRIPTION' => $this->clean($text, 200) ?: 'Échéance',
        ]);

        $alarm->add('TRIGGER', $trigger, ['RELATED' => 'START']);
    }

    private function dueAt(Todo $todo): ?CarbonImmutable
    {
        $raw = $todo->getAttribute('due_at');

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }

        try {
            return CarbonImmutable::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Décide si l'échéance est « journée entière » et, si oui, renvoie la DATE
     * CIVILE à écrire dans DTSTART.
     *
     * Une date sans heure (« à payer avant le 15/10 », le cas le plus fréquent
     * d'un document) peut arriver ici sous deux formes selon la façon dont elle
     * a été enregistrée : minuit dans le fuseau de stockage (UTC, convention
     * Laravel pour un cast date) ou minuit à Paris. Les deux doivent produire un
     * all-day, et la date civile retenue est celle du fuseau où il est minuit —
     * sinon un « 15/10 » stocké 00:00 UTC ressortirait au 14 ou au 16 octobre.
     *
     * Paris n'étant jamais à UTC+0, les deux cas s'excluent.
     */
    private function allDayDate(Todo $todo, CarbonImmutable $due, DateTimeZone $tz): ?CarbonImmutable
    {
        $utc = $due->setTimezone(new DateTimeZone('UTC'));
        $local = $due->setTimezone($tz);

        $atUtcMidnight = $utc->format('His') === '000000';
        $atLocalMidnight = $local->format('His') === '000000';

        $flag = $todo->getAttribute('all_day');

        if ($flag !== null && ! $flag) {
            return null;
        }

        if ($atUtcMidnight) {
            return $utc;
        }

        if ($atLocalMidnight || $flag) {
            return $local;
        }

        return null;
    }

    private function durationMinutes(Todo $todo): int
    {
        $minutes = (int) ($todo->getAttribute('duration_minutes') ?? 0);

        return $minutes > 0 ? $minutes : self::DEFAULT_DURATION_MINUTES;
    }

    private function summary(Todo $todo): string
    {
        $title = $this->clean((string) ($todo->getAttribute('title') ?? ''), 200);

        return $title !== '' ? $title : 'Échéance Papers';
    }

    private function description(Todo $todo): string
    {
        $parts = [];

        foreach (['notes', 'description', 'summary'] as $key) {
            $value = $this->clean((string) ($todo->getAttribute($key) ?? ''), 2000);

            if ($value !== '') {
                $parts[] = $value;
                break;
            }
        }

        if (($url = $this->documentUrl($todo)) !== null) {
            $parts[] = 'Document : '.$url;
        }

        return implode("\n\n", $parts);
    }

    /** 1 = haute, 5 = normale, 9 = basse (RFC 5545). null = non renseignée. */
    private function priority(Todo $todo): ?int
    {
        $raw = $todo->getAttribute('priority');

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof \BackedEnum) {
            $raw = $raw->value;
        }

        if (is_numeric($raw)) {
            return max(1, min(9, (int) $raw));
        }

        return match (strtolower((string) $raw)) {
            'high', 'haute', 'urgent', 'urgente' => 1,
            'normal', 'normale', 'medium', 'moyenne' => 5,
            'low', 'basse' => 9,
            default => null,
        };
    }

    private function documentUrl(Todo $todo): ?string
    {
        $documentId = $todo->getAttribute('document_id');

        if ($documentId === null || $documentId === '') {
            return null;
        }

        $base = rtrim((string) config('app.url', ''), '/');

        if ($base === '') {
            return null;
        }

        // Routage front par History API : /documents/{id} est une vraie URL.
        return $base.'/documents/'.rawurlencode((string) $documentId);
    }

    /**
     * Le texte vient d'une extraction faite par un modèle sur un document
     * scanné : c'est une entrée hostile (§10 de l'architecture). vobject se
     * charge de l'échappement RFC 5545 (\\ puis ; , puis \n), ici on se contente
     * de neutraliser les caractères de contrôle — qui, eux, casseraient le
     * parsing ICS — et de borner la longueur.
     */
    private function clean(string $value, int $max): string
    {
        $value = (string) preg_replace('/\R/u', "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = trim($value);

        if (mb_strlen($value) > $max) {
            $value = rtrim(mb_substr($value, 0, $max - 1)).'…';
        }

        return $value;
    }
}
