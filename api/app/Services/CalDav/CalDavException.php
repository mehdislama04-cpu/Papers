<?php

declare(strict_types=1);

namespace App\Services\CalDav;

use RuntimeException;
use Throwable;

/**
 * Erreur CalDAV iCloud.
 *
 * Deux notions portées par l'exception :
 *  - $kind    : nature de l'erreur (auth / http / protocol / transport) ;
 *  - $terminal: l'erreur ne sera JAMAIS résolue par un retry.
 *
 * Le seul cas terminal aujourd'hui est le 401 : un mot de passe d'application
 * révoqué (ou le mot de passe Apple principal changé, ce qui révoque les 25
 * mots de passe d'application d'un coup) ne redeviendra pas valide tout seul.
 * Boucler en retry sur un 401 peut en plus déclencher un blocage temporaire
 * côté Apple.
 *
 * Toutes les fabriques passent par redact() : une exception CalDAV ne doit
 * jamais transporter l'en-tête Authorization (Basic base64(appleId:appPassword))
 * jusqu'aux logs ou à Sentry.
 */
final class CalDavException extends RuntimeException
{
    public const KIND_AUTH = 'auth';
    public const KIND_HTTP = 'http';
    public const KIND_PROTOCOL = 'protocol';
    public const KIND_TRANSPORT = 'transport';

    private function __construct(
        string $message,
        public readonly string $kind,
        public readonly int $status = 0,
        public readonly bool $terminal = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 401 : état TERMINAL. L'appelant doit basculer le compte en
     * invalid_credentials et arrêter tout job pour ce compte.
     */
    public static function auth(string $appleId, int $status = 401): self
    {
        return new self(
            sprintf('CalDAV 401 : mot de passe d\'application invalide ou révoqué pour %s.', $appleId),
            self::KIND_AUTH,
            $status,
            true,
        );
    }

    /** Code HTTP inattendu sur une requête DAV (403, 404, 412 non géré, 507...). */
    public static function http(string $method, string $url, int $status, string $body = ''): self
    {
        $extract = trim(self::redact($body));

        if (mb_strlen($extract) > 500) {
            $extract = mb_substr($extract, 0, 500).'…';
        }

        return new self(
            sprintf(
                'CalDAV %s %s : HTTP %d%s',
                $method,
                self::redact($url),
                $status,
                $extract !== '' ? ' — '.$extract : '',
            ),
            self::KIND_HTTP,
            $status,
            // 507 = quota iCloud dépassé (1 Go / 50 000 items) : retenter n'y changera rien.
            $status === 507,
        );
    }

    /** Réponse 207 syntaxiquement valide mais dont la propriété attendue est absente. */
    public static function protocol(string $message): self
    {
        return new self('CalDAV : '.self::redact($message), self::KIND_PROTOCOL);
    }

    /** Timeout, DNS, TLS... : transitoire, le job doit retenter. */
    public static function transport(string $message, ?Throwable $previous = null): self
    {
        return new self('CalDAV transport : '.self::redact($message), self::KIND_TRANSPORT, 0, false, $previous);
    }

    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    /**
     * Supprime toute trace de credentials d'un message avant log.
     * Guzzle recopie les en-têtes de la requête dans ses RequestException.
     */
    public static function redact(string $message): string
    {
        return (string) preg_replace(
            ['/Basic\s+[A-Za-z0-9+\/=]+/i', '/(Authorization["\':\s]+)[^\s"\',]+/i'],
            ['Basic [REDACTED]', '$1[REDACTED]'],
            $message,
        );
    }
}
