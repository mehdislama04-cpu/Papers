<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CalendarAccountStatus;
use App\Models\CalendarAccount;
use App\Services\CalDav\CalDavClient;
use App\Services\CalDav\CalDavException;
use App\Services\CalDav\IcsBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Transport CalDAV vers iCloud.
 *
 * Les deux comportements testes ici sont ceux qui cassent silencieusement toute
 * implementation naive : la perte de l'en-tete Authorization au changement
 * d'hote (CVE-2022-31090) et le 401 traite comme une erreur transitoire.
 */
final class CalDavClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private function client(Response ...$responses): CalDavClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $guzzle = new Client(['handler' => $stack, 'http_errors' => false, 'allow_redirects' => false]);

        return new class(app(IcsBuilder::class), $guzzle) extends CalDavClient
        {
            public function __construct(IcsBuilder $ics, private readonly Client $mockClient)
            {
                parent::__construct($ics);
            }

            protected function makeClient(): Client
            {
                return $this->mockClient;
            }
        };
    }

    private function account(): CalendarAccount
    {
        $account = new CalendarAccount();
        $account->forceFill([
            'id' => 1,
            'user_id' => 1,
            'apple_id' => 'test@icloud.com',
            'app_password' => 'abcd-efgh-ijkl-mnop',
            'status' => CalendarAccountStatus::Pending,
        ]);

        return $account;
    }

    private function principalXml(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <multistatus xmlns="DAV:">
          <response>
            <href>/</href>
            <propstat>
              <prop><current-user-principal><href>/200385701/principal/</href></current-user-principal></prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;
    }

    #[Test]
    public function l_en_tete_authorization_est_reinjecte_apres_une_redirection_de_partition(): void
    {
        $client = $this->client(
            // Bascule vers la partition pNN : changement d'hote.
            new Response(302, ['Location' => 'https://p34-caldav.icloud.com/']),
            new Response(207, ['Content-Type' => 'application/xml'], $this->principalXml()),
        );

        try {
            $client->discover($this->account());
        } catch (\Throwable) {
            // La suite de la decouverte n'est pas l'objet de ce test.
        }

        $this->assertGreaterThanOrEqual(2, count($this->history));

        $redirected = $this->history[1]['request'];

        $this->assertSame(
            'p34-caldav.icloud.com',
            $redirected->getUri()->getHost(),
            'La requete doit bien avoir suivi la redirection vers la partition.',
        );

        $this->assertNotSame(
            '',
            $redirected->getHeaderLine('Authorization'),
            'Guzzle supprime Authorization au changement d hote depuis CVE-2022-31090 : '
            .'la requete rejouee doit le reinjecter, sinon iCloud renvoie 401 avec de bons identifiants.',
        );
    }

    #[Test]
    public function la_methode_et_le_corps_survivent_a_la_redirection(): void
    {
        $client = $this->client(
            new Response(302, ['Location' => 'https://p34-caldav.icloud.com/']),
            new Response(207, ['Content-Type' => 'application/xml'], $this->principalXml()),
        );

        try {
            $client->discover($this->account());
        } catch (\Throwable) {
        }

        $redirected = $this->history[1]['request'];

        // Sans 'strict', Guzzle transformerait le PROPFIND en GET et jetterait
        // le corps : iCloud repondrait 400.
        $this->assertSame('PROPFIND', $redirected->getMethod());
        $this->assertStringContainsString('current-user-principal', (string) $redirected->getBody());
    }

    #[Test]
    public function un_401_est_terminal_et_ne_declenche_aucun_retry(): void
    {
        $client = $this->client(new Response(401, [], 'Unauthorized'));

        $this->expectException(CalDavException::class);

        try {
            $client->discover($this->account());
        } finally {
            $this->assertCount(
                1,
                $this->history,
                'Un 401 signifie mot de passe d application revoque : retenter ne peut pas aider '
                .'et risque de faire blacklister le compte.',
            );
        }
    }

    #[Test]
    public function le_mot_de_passe_d_application_n_apparait_pas_dans_le_message_d_erreur(): void
    {
        $client = $this->client(new Response(401, [], 'Unauthorized'));

        try {
            $client->discover($this->account());
            $this->fail('Une exception etait attendue.');
        } catch (CalDavException $e) {
            $this->assertStringNotContainsString('abcd-efgh-ijkl-mnop', $e->getMessage());
            $this->assertStringNotContainsString(
                base64_encode('test@icloud.com:abcd-efgh-ijkl-mnop'),
                $e->getMessage(),
            );
        }
    }

    #[Test]
    public function un_421_est_rejoue_une_fois_sur_une_connexion_neuve(): void
    {
        $client = $this->client(
            // 421 Misdirected Request : coalescing de connexion HTTP/2.
            new Response(421, [], ''),
            new Response(207, ['Content-Type' => 'application/xml'], $this->principalXml()),
        );

        try {
            $client->discover($this->account());
        } catch (\Throwable) {
        }

        $this->assertGreaterThanOrEqual(2, count($this->history), 'Le 421 doit etre rejoue une fois.');
        $this->assertSame('PROPFIND', $this->history[1]['request']->getMethod());
    }

    #[Test]
    public function la_decouverte_extrait_le_dsid_et_l_url_du_principal(): void
    {
        $home = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <multistatus xmlns="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
          <response>
            <href>/200385701/principal/</href>
            <propstat>
              <prop><c:calendar-home-set><href>https://p34-caldav.icloud.com:443/200385701/calendars/</href></c:calendar-home-set></prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;

        $client = $this->client(
            new Response(207, ['Content-Type' => 'application/xml'], $this->principalXml()),
            new Response(207, ['Content-Type' => 'application/xml'], $home),
        );

        $account = $this->account();
        $account->exists = true;
        $account->syncOriginal();

        try {
            $client->discover($account);
        } catch (\Throwable) {
            // La sauvegarde en base n'est pas l'objet de ce test.
        }

        $this->assertSame('200385701', $account->getAttribute('dsid'));
        $this->assertStringContainsString('/200385701/principal/', (string) $account->getAttribute('principal_url'));
        $this->assertStringContainsString(
            'p34-caldav.icloud.com',
            (string) $account->getAttribute('calendar_home_url'),
            'La partition pNN doit etre persistee : elle est propre a chaque compte.',
        );
    }
}
