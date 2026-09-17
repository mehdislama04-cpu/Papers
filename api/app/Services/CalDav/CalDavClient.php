<?php

declare(strict_types=1);

namespace App\Services\CalDav;

use App\Models\CalendarAccount;
use App\Models\CalendarEvent;
use App\Models\Todo;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/**
 * Client CalDAV iCloud.
 *
 * Volontairement écrit sur Guzzle brut plutôt que sur Sabre\DAV\Client : il faut
 * un contrôle total des redirections, du 421 et des ETags (voir dav()).
 * Aucune requête CalDAV ne doit partir du navigateur : Apple ne renvoie aucun
 * en-tête CORS et cela exposerait le mot de passe d'application au client.
 */
class CalDavClient
{
    public const ROOT = 'https://caldav.icloud.com/';

    /** Nom affiché du calendrier dédié, créé par MKCALENDAR s'il n'existe pas. */
    public const CALENDAR_NAME = 'Papers — Échéances';

    public const CALENDAR_SLUG = 'papers-echeances';

    /** iCloud renvoie/attend #RRGGBBAA dans le namespace http://apple.com/ns/ical/. */
    public const CALENDAR_COLOR = '#FF9500FF';

    /** Valeurs persistées dans CalendarAccount::status. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_OK = 'ok';
    public const STATUS_INVALID_CREDENTIALS = 'invalid_credentials';
    public const STATUS_ERROR = 'error';

    /** Valeurs persistées dans CalendarEvent::sync_status. */
    public const SYNC_PENDING = 'pending';
    public const SYNC_SYNCED = 'synced';
    public const SYNC_CONFLICT = 'conflict';
    public const SYNC_DELETED = 'deleted';
    public const SYNC_FAILED = 'failed';

    private ?Client $http = null;

    /**
     * URL qui a effectivement servi la dernière réponse (après redirections).
     * Les href d'un multistatus sont relatifs à CETTE URL, pas à celle demandée :
     * après une bascule vers la partition pNN, résoudre contre l'URL d'origine
     * renverrait les requêtes suivantes sur caldav.icloud.com pour rien.
     */
    private string $lastEffectiveUrl = '';

    public function __construct(private readonly IcsBuilder $ics)
    {
    }

    // -----------------------------------------------------------------------
    // Découverte
    // -----------------------------------------------------------------------

    /**
     * Découverte en 3 étapes, persistée sur le compte.
     *
     * 1. PROPFIND /                    -> current-user-principal  => /{dsid}/principal/
     * 2. PROPFIND /{dsid}/principal/   -> calendar-home-set       => https://pNN-caldav.icloud.com/{dsid}/calendars/
     * 3. PROPFIND Depth:1 sur la home  -> liste des calendriers
     *
     * Le DSID et le numéro de partition pNN sont PROPRES À CHAQUE COMPTE : ils ne
     * doivent jamais être codés en dur, et doivent être persistés pour ne pas
     * refaire la découverte à chaque job.
     */
    public function discover(CalendarAccount $account): void
    {
        $principalUrl = $this->discoverPrincipal($account);
        $homeUrl = $this->discoverCalendarHome($account, $principalUrl);

        // /200385701/principal/ -> 200385701
        $dsid = null;
        if (preg_match('#/(\d+)/#', (string) parse_url($principalUrl, PHP_URL_PATH), $m) === 1) {
            $dsid = $m[1];
        }

        $account->forceFill([
            'principal_url' => $principalUrl,
            'calendar_home_url' => $homeUrl,
            'dsid' => $dsid,
        ])->save();
    }

    /** Étape 1 : current-user-principal. */
    private function discoverPrincipal(CalendarAccount $account): string
    {
        $body = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:">
          <d:prop><d:current-user-principal/></d:prop>
        </d:propfind>
        XML;

        $response = $this->dav($account, 'PROPFIND', self::ROOT, $body, ['Depth' => '0']);
        $this->assertStatus($response, [207], 'PROPFIND', self::ROOT);

        $xpath = $this->xpath((string) $response->getBody());
        $href = $xpath->evaluate('string(//d:current-user-principal/d:href)');

        if (! is_string($href) || trim($href) === '') {
            throw CalDavException::protocol('current-user-principal absent de la réponse.');
        }

        return $this->absolute($this->lastEffectiveUrl, $href);
    }

    /** Étape 2 : calendar-home-set (href ABSOLU vers la partition pNN). */
    private function discoverCalendarHome(CalendarAccount $account, string $principalUrl): string
    {
        $body = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
          <d:prop>
            <c:calendar-home-set/>
            <d:displayname/>
          </d:prop>
        </d:propfind>
        XML;

        $response = $this->dav($account, 'PROPFIND', $principalUrl, $body, ['Depth' => '0']);
        $this->assertStatus($response, [207], 'PROPFIND', $principalUrl);

        $xpath = $this->xpath((string) $response->getBody());
        $href = $xpath->evaluate('string(//c:calendar-home-set/d:href)');

        if (! is_string($href) || trim($href) === '') {
            throw CalDavException::protocol('calendar-home-set absent de la réponse.');
        }

        // ex. https://p34-caldav.icloud.com:443/200385701/calendars/ (le port par
        // defaut est normalise par Uri, ce qui evite deux formes du meme hote).
        return rtrim($this->absolute($this->lastEffectiveUrl, $href), '/').'/';
    }

    /**
     * Étape 3 : calendriers de la home, filtrés.
     *
     * @return array<int, array{url:string,displayname:string,components:array<int,string>,color:?string,ctag:?string,sync_token:?string,writable:bool}>
     */
    public function listCalendars(CalendarAccount $account): array
    {
        $homeUrl = (string) $account->getAttribute('calendar_home_url');

        if ($homeUrl === '') {
            $this->discover($account);
            $homeUrl = (string) $account->getAttribute('calendar_home_url');
        }

        $body = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:"
                    xmlns:c="urn:ietf:params:xml:ns:caldav"
                    xmlns:cs="http://calendarserver.org/ns/"
                    xmlns:a="http://apple.com/ns/ical/">
          <d:prop>
            <d:resourcetype/>
            <d:displayname/>
            <d:current-user-privilege-set/>
            <d:sync-token/>
            <cs:getctag/>
            <a:calendar-color/>
            <c:supported-calendar-component-set/>
          </d:prop>
        </d:propfind>
        XML;

        $response = $this->dav($account, 'PROPFIND', $homeUrl, $body, ['Depth' => '1']);
        $this->assertStatus($response, [207], 'PROPFIND', $homeUrl);

        $base = $this->lastEffectiveUrl;

        $xpath = $this->xpath((string) $response->getBody());
        $calendars = [];

        foreach ($xpath->query('//d:response') ?: [] as $node) {
            $href = trim((string) $xpath->evaluate('string(d:href)', $node));

            if ($href === '' || rtrim($this->absolute($base, $href), '/') === rtrim($base, '/')) {
                continue; // la home collection elle-même
            }

            // Doit être une collection CalDAV...
            if ((int) $xpath->evaluate('count(.//d:resourcetype/c:calendar)', $node) === 0) {
                continue;
            }

            // ...et surtout pas inbox / outbox / notifications.
            if ((int) $xpath->evaluate('count(.//d:resourcetype/c:schedule-inbox)', $node) > 0
                || (int) $xpath->evaluate('count(.//d:resourcetype/c:schedule-outbox)', $node) > 0
                || (int) $xpath->evaluate('count(.//d:resourcetype/cs:notification)', $node) > 0) {
                continue;
            }

            $components = [];
            foreach ($xpath->query('.//c:supported-calendar-component-set/c:comp', $node) ?: [] as $comp) {
                $components[] = strtoupper($comp->getAttribute('name'));
            }

            // Une collection qui ne supporte que VTODO est une liste de Rappels
            // legacy : inutilisable depuis iOS 13. Aucun composant déclaré = tout
            // supporté (rare chez iCloud).
            if ($components !== [] && ! in_array('VEVENT', $components, true)) {
                continue;
            }

            // Un calendrier abonné ou partagé en lecture seule échoue en PUT (403) :
            // on le repère ici plutôt qu'au moment d'écrire.
            $writable = (int) $xpath->evaluate('count(.//d:current-user-privilege-set/d:privilege/d:write-content)', $node) > 0
                && (int) $xpath->evaluate('count(.//d:current-user-privilege-set/d:privilege/d:bind)', $node) > 0;

            $color = trim((string) $xpath->evaluate('string(.//a:calendar-color)', $node));
            $ctag = trim((string) $xpath->evaluate('string(.//cs:getctag)', $node));
            $syncToken = trim((string) $xpath->evaluate('string(.//d:sync-token)', $node));

            $calendars[] = [
                'url' => rtrim($this->absolute($base, $href), '/').'/',
                'displayname' => trim((string) $xpath->evaluate('string(.//d:displayname)', $node)),
                'components' => $components,
                'color' => $color !== '' ? substr($color, 0, 7) : null, // #RRGGBBAA -> #RRGGBB
                'ctag' => $ctag !== '' ? $ctag : null,
                'sync_token' => $syncToken !== '' ? $syncToken : null,
                'writable' => $writable,
            ];
        }

        return $calendars;
    }

    /**
     * Garantit l'existence du calendrier « Papers — Échéances » et renvoie son URL.
     *
     * Idempotent : on réutilise l'URL persistée, sinon on cherche le calendrier
     * dans la home (cas d'un compte re-connecté), sinon seulement on le crée.
     * On ne touche jamais aux calendriers existants de l'utilisateur.
     */
    public function ensurePapersCalendar(CalendarAccount $account): string
    {
        $existing = (string) ($account->getAttribute('calendar_url') ?? '');

        if ($existing !== '') {
            return $existing;
        }

        if ((string) ($account->getAttribute('calendar_home_url') ?? '') === '') {
            $this->discover($account);
        }

        $homeUrl = (string) $account->getAttribute('calendar_home_url');

        foreach ($this->listCalendars($account) as $calendar) {
            $isPapers = str_ends_with(rtrim((string) parse_url($calendar['url'], PHP_URL_PATH), '/'), '/'.self::CALENDAR_SLUG)
                || $this->sameName($calendar['displayname'], self::CALENDAR_NAME);

            if ($isPapers && $calendar['writable']) {
                return $this->persistCalendarUrl($account, $calendar['url']);
            }
        }

        $url = rtrim($homeUrl, '/').'/'.rawurlencode(self::CALENDAR_SLUG).'/';
        $name = htmlspecialchars(self::CALENDAR_NAME, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $body = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">
          <d:set>
            <d:prop>
              <d:displayname>{$name}</d:displayname>
              <c:calendar-description xml:lang="fr">Échéances extraites de vos documents Papers</c:calendar-description>
              <a:calendar-color symbolic-color="orange">{$this->color()}</a:calendar-color>
              <c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>
            </d:prop>
          </d:set>
        </c:mkcalendar>
        XML;

        $response = $this->dav($account, 'MKCALENDAR', $url, $body);
        $status = $response->getStatusCode();

        // 405 = le chemin existe déjà : c'est le cas nominal d'une 2e exécution.
        if (! in_array($status, [201, 301, 405], true)) {
            throw CalDavException::http('MKCALENDAR', $url, $status, (string) $response->getBody());
        }

        return $this->persistCalendarUrl($account, $url);
    }

    // -----------------------------------------------------------------------
    // CRUD événement
    // -----------------------------------------------------------------------

    /**
     * Crée ou met à jour l'événement iCloud correspondant au Todo.
     *
     * Idempotence :
     *  - UID déterministe => même href .ics à chaque passage ;
     *  - création avec If-None-Match: * => jamais de doublon (412 si déjà là) ;
     *  - mise à jour avec If-Match: <etag> => on n'écrase pas une modification
     *    faite sur l'iPhone (412 => conflit, l'appareil gagne).
     *
     * L'ETag renvoyé est persisté ; iCloud ne le renvoie pas toujours sur le PUT,
     * d'où le repli par PROPFIND getetag.
     */
    public function putEvent(CalendarAccount $account, CalendarEvent $event): void
    {
        $todo = $this->todoOf($event);

        if ($todo === null) {
            throw CalDavException::protocol(
                'CalendarEvent #'.((string) $event->getKey()).' sans Todo : rien à pousser.'
            );
        }

        $calendarUrl = $this->ensurePapersCalendar($account);

        $uid = (string) ($event->getAttribute('uid') ?? '');
        if ($uid === '') {
            $uid = IcsBuilder::uidFor($todo);
        }

        $href = (string) ($event->getAttribute('href') ?? '');
        if ($href === '') {
            // Le nom de fichier n'a pas à être égal à l'UID, mais c'est la
            // convention la plus robuste. rawurlencode : l'UID contient un '@'.
            $href = rtrim($calendarUrl, '/').'/'.rawurlencode($uid).'.ics';
        }

        $etag = (string) ($event->getAttribute('etag') ?? '');
        $sequence = (int) ($event->getAttribute('sequence') ?? 0);
        $isUpdate = $etag !== '';

        if ($isUpdate) {
            // SEQUENCE doit être incrémenté à chaque mise à jour, sinon les
            // clients peuvent ignorer la nouvelle version.
            $sequence++;
        }

        $ics = $this->ics->buildTodoEvent($todo, $sequence);

        if (! $isUpdate) {
            $newEtag = $this->putIcs($account, $href, $ics, ['If-None-Match' => '*']);

            if ($newEtag === false) {
                // 412 : la ressource existe déjà côté iCloud (UID déterministe,
                // re-synchronisation après perte de l'ETag en base). On récupère
                // l'ETag courant et on repasse en mise à jour conditionnelle.
                $etag = (string) ($this->fetchEtag($account, $href) ?? '');
                $isUpdate = $etag !== '';

                if (! $isUpdate) {
                    throw CalDavException::protocol('PUT 412 sans ETag récupérable sur '.$href);
                }

                $sequence++;
                $ics = $this->ics->buildTodoEvent($todo, $sequence);
                $newEtag = $this->putIcs($account, $href, $ics, ['If-Match' => $etag]);
            }
        } else {
            $newEtag = $this->putIcs($account, $href, $ics, ['If-Match' => $etag]);
        }

        if ($newEtag === false) {
            // 412 sur une mise à jour = l'événement a été modifié sur l'iPhone.
            // Politique : l'appareil gagne, on n'écrase pas, on signale.
            $event->forceFill([
                'uid' => $uid,
                'href' => $href,
                'etag' => $this->fetchEtag($account, $href),
                'sync_status' => self::SYNC_CONFLICT,
                'last_error' => 'Événement modifié dans iCloud (412) : la version de l\'appareil est conservée.',
            ])->save();

            return;
        }

        $event->forceFill([
            'uid' => $uid,
            'href' => $href,
            'etag' => $newEtag,
            'sequence' => $sequence,
            'sync_status' => self::SYNC_SYNCED,
            'last_error' => null,
            'last_synced_at' => now(),
        ])->save();
    }

    /**
     * Supprime l'événement iCloud. DELETE conditionnel par If-Match pour ne pas
     * effacer une version plus récente sans le savoir.
     */
    public function deleteEvent(CalendarAccount $account, CalendarEvent $event): void
    {
        $href = (string) ($event->getAttribute('href') ?? '');

        if ($href === '') {
            // Jamais poussé : rien à supprimer côté iCloud.
            $event->forceFill([
                'sync_status' => self::SYNC_DELETED,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();

            return;
        }

        $etag = (string) ($event->getAttribute('etag') ?? '');
        $status = $this->sendDelete($account, $href, $etag);

        if ($status === 412) {
            // Modifié sur l'iPhone entre-temps. La suppression a été demandée
            // explicitement dans Papers : on rejoue UNE fois avec l'ETag frais.
            $fresh = (string) ($this->fetchEtag($account, $href) ?? '');
            $status = $fresh !== '' ? $this->sendDelete($account, $href, $fresh) : 412;

            if ($status === 412) {
                $event->forceFill([
                    'etag' => $fresh !== '' ? $fresh : $etag,
                    'sync_status' => self::SYNC_CONFLICT,
                    'last_error' => 'Suppression refusée (412) : l\'événement change plus vite que la synchronisation.',
                ])->save();

                return;
            }
        }

        // 404 = déjà supprimé côté iCloud (suppression depuis l'iPhone) : c'est
        // le résultat voulu, surtout pas une erreur à retenter en boucle.
        if (! in_array($status, [200, 202, 204, 404], true)) {
            throw CalDavException::http('DELETE', $href, $status);
        }

        $event->forceFill([
            'etag' => null,
            'sync_status' => self::SYNC_DELETED,
            'last_error' => null,
            'last_synced_at' => now(),
        ])->save();
    }

    // -----------------------------------------------------------------------
    // Transport
    // -----------------------------------------------------------------------

    /**
     * Envoie une requête DAV.
     *
     * Deux comportements par défaut de Guzzle ruineraient chaque requête, d'où
     * allow_redirects => false (voir makeClient()) et la redirection rejouée
     * À LA MAIN ici :
     *
     *  1. sans 'strict', Guzzle suit un 301/302 en transformant la méthode en GET
     *     et en jetant le corps : le PROPFIND devient un GET sans corps -> 400 ;
     *  2. depuis CVE-2022-31043 / CVE-2022-31090 (Guzzle >= 7.4.5), Guzzle
     *     SUPPRIME l'en-tête Authorization dès que l'hôte, le schéma ou le port
     *     change : la bascule vers la partition pNN-caldav.icloud.com renverrait
     *     donc 401 alors que les identifiants sont bons.
     *
     * Ici on réémet la requête complète — même méthode, même corps, et surtout
     * le même en-tête Authorization — vers la nouvelle URL.
     */
    private function dav(
        CalendarAccount $account,
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
    ): ResponseInterface {
        $headers = array_merge([
            'Authorization' => $this->authorization($account),
            'Accept' => 'application/xml, text/xml, text/calendar',
            'User-Agent' => 'Papers/1.0 (CalDAV)',
        ], $headers);

        if ($body !== null && ! isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/xml; charset=utf-8';
        }

        $hops = 0;
        $retried421 = false;

        while (true) {
            try {
                $response = $this->http()->send(new Request($method, $url, $headers, $body));
            } catch (TransferException $e) {
                // Le message d'une exception Guzzle peut contenir les en-têtes
                // de la requête, donc le Authorization en clair : redact() est
                // appliqué par CalDavException.
                throw CalDavException::transport($e->getMessage(), $e);
            }

            $status = $response->getStatusCode();

            if (in_array($status, [301, 302, 307, 308], true) && $response->hasHeader('Location') && $hops < 5) {
                $url = (string) UriResolver::resolve(
                    new Uri($url),
                    new Uri(trim($response->getHeaderLine('Location'))),
                );
                $hops++;

                continue;
            }

            if ($status === 421 && ! $retried421) {
                // 421 Misdirected Request : les partitions pNN partagent IP et
                // certificat, le coalescing de connexion HTTP/2 envoie la requête
                // sur la mauvaise connexion TLS. On force déjà HTTP/1.1, mais si
                // ça arrive quand même il faut une connexion NEUVE, pas un retry
                // sur le même handle cURL.
                $this->http = null;
                $retried421 = true;

                continue;
            }

            if ($status === 401) {
                // État TERMINAL : mot de passe d'application révoqué (ou mot de
                // passe Apple principal changé, ce qui révoque les 25 d'un coup).
                // Retenter ne peut pas aider et peut faire blacklister le compte.
                throw CalDavException::auth((string) $account->getAttribute('apple_id'));
            }

            $this->lastEffectiveUrl = $url;

            return $response;
        }
    }

    private function http(): Client
    {
        return $this->http ??= $this->makeClient();
    }

    /** protected : permet de substituer un handler de test (MockHandler). */
    protected function makeClient(): Client
    {
        return new Client([
            'http_errors' => false,
            // iCloud est lent sur les gros PROPFIND : 10 s provoque des
            // ReadTimeout réguliers. Ce timeout est AUSSI la seule protection
            // réelle du worker sous Windows, où pcntl_alarm n'existe pas et où
            // le timeout de job Laravel n'est donc pas appliqué.
            'timeout' => 30,
            'connect_timeout' => 10,
            'version' => '1.1',
            'curl' => [
                // Force HTTP/1.1 : évite les 421 Misdirected Request dus au
                // coalescing de connexion HTTP/2 entre partitions pNN.
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ],
            // Voir dav() : Guzzle casserait la redirection de partition
            // (méthode transformée en GET + Authorization supprimé).
            'allow_redirects' => false,
        ]);
    }

    /**
     * Apple ne documente pas officiellement le format xxxx-xxxx-xxxx-xxxx du
     * mot de passe d'application : on normalise (espaces), on ne valide pas.
     */
    private function authorization(CalendarAccount $account): string
    {
        $appleId = trim((string) $account->getAttribute('apple_id'));
        $password = (string) preg_replace('/\s+/u', '', (string) $account->getAttribute('app_password'));

        return 'Basic '.base64_encode($appleId.':'.$password);
    }

    /** @return string|false|null ETag, ou false si 412 (précondition échouée). */
    private function putIcs(CalendarAccount $account, string $href, string $ics, array $conditional): string|false|null
    {
        $response = $this->dav($account, 'PUT', $href, $ics, array_merge([
            // Le charset est obligatoire : l'ICS est de l'UTF-8 brut (accents).
            'Content-Type' => 'text/calendar; charset=utf-8',
        ], $conditional));

        $status = $response->getStatusCode();

        if ($status === 412) {
            return false;
        }

        if (! in_array($status, [200, 201, 204], true)) {
            throw CalDavException::http('PUT', $href, $status, (string) $response->getBody());
        }

        // iCloud ne renvoie PAS systématiquement l'ETag sur un PUT réussi.
        $etag = trim($response->getHeaderLine('ETag'));

        return $etag !== '' ? $etag : $this->fetchEtag($account, $href);
    }

    private function sendDelete(CalendarAccount $account, string $href, string $etag): int
    {
        return $this->dav(
            $account,
            'DELETE',
            $href,
            null,
            $etag !== '' ? ['If-Match' => $etag] : [],
        )->getStatusCode();
    }

    /** Repli quand le PUT ne renvoie pas d'ETag, et rafraîchissement après 412. */
    private function fetchEtag(CalendarAccount $account, string $href): ?string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>';

        $response = $this->dav($account, 'PROPFIND', $href, $body, ['Depth' => '0']);

        if ($response->getStatusCode() !== 207) {
            return null;
        }

        $etag = trim((string) $this->xpath((string) $response->getBody())->evaluate('string(//d:getetag)'));

        return $etag !== '' ? $etag : null;
    }

    // -----------------------------------------------------------------------
    // Outils
    // -----------------------------------------------------------------------

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET : jamais de résolution d'entité externe sur une réponse réseau.
        $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $xpath->registerNamespace('cs', 'http://calendarserver.org/ns/');
        $xpath->registerNamespace('a', 'http://apple.com/ns/ical/');

        return $xpath;
    }

    private function absolute(string $base, string $href): string
    {
        return (string) UriResolver::resolve(new Uri($base), new Uri(trim($href)));
    }

    /** @param  array<int, int>  $allowed */
    private function assertStatus(ResponseInterface $response, array $allowed, string $method, string $url): void
    {
        if (! in_array($response->getStatusCode(), $allowed, true)) {
            throw CalDavException::http($method, $url, $response->getStatusCode(), (string) $response->getBody());
        }
    }

    private function persistCalendarUrl(CalendarAccount $account, string $url): string
    {
        $account->forceFill(['calendar_url' => $url])->save();

        return $url;
    }

    /** Le tiret du nom a pu être saisi en tiret cadratin, demi-cadratin ou simple. */
    private function sameName(string $a, string $b): bool
    {
        $normalize = static fn (string $v): string => trim((string) preg_replace(
            ['/[\x{2010}-\x{2015}]/u', '/\s+/u'],
            ['-', ' '],
            mb_strtolower($v),
        ));

        return $normalize($a) === $normalize($b);
    }

    private function color(): string
    {
        return self::CALENDAR_COLOR;
    }

    private function todoOf(CalendarEvent $event): ?Todo
    {
        $todo = $event->getAttribute('todo');

        if ($todo instanceof Todo) {
            return $todo;
        }

        $todoId = $event->getAttribute('todo_id');

        return $todoId !== null ? Todo::find($todoId) : null;
    }
}
