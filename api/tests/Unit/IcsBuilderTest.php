<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TodoStatus;
use App\Models\Todo;
use App\Services\CalDav\IcsBuilder;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verrouille la generation ICS envoyee a iCloud.
 *
 * Les erreurs visees ici ne provoquent aucune exception : elles produisent un
 * evenement accepte par le serveur puis INVISIBLE dans l'app Calendrier, ce qui
 * est le pire des echecs pour une application dont la promesse est « ne jamais
 * rien rater ».
 */
final class IcsBuilderTest extends TestCase
{
    private function todo(array $attributes = []): Todo
    {
        $todo = new Todo();
        $todo->forceFill(array_merge([
            'id' => '0199c0de-1234-7abc-8def-0123456789ab',
            'user_id' => 1,
            'document_id' => null,
            'title' => 'Payer la facture d électricité',
            'details' => 'Reference FR-2026-8842',
            'due_at' => Carbon::parse('2026-10-15 08:00:00', 'Europe/Paris'),
            'all_day' => false,
            'priority' => 0,
            'status' => TodoStatus::Pending,
        ], $attributes));

        return $todo;
    }

    private function build(Todo $todo): string
    {
        return app(IcsBuilder::class)->buildTodoEvent($todo);
    }

    #[Test]
    public function un_evenement_sur_la_journee_a_un_dtend_exclusif(): void
    {
        $ics = $this->build($this->todo([
            'due_at' => Carbon::parse('2026-10-15 00:00:00', 'Europe/Paris'),
            'all_day' => true,
        ]));

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261015', $ics);

        // Le piege : DTEND identique a DTSTART donne une duree nulle et
        // l'evenement n'apparait jamais dans iCloud. Il doit valoir J+1.
        $this->assertStringContainsString(
            'DTEND;VALUE=DATE:20261016',
            $ics,
            'DTEND d un evenement all-day est EXCLUSIF : il doit pointer le lendemain.',
        );
        $this->assertStringNotContainsString('DTEND;VALUE=DATE:20261015', $ics);
    }

    #[Test]
    public function le_passage_au_mois_suivant_est_correct(): void
    {
        $ics = $this->build($this->todo([
            'due_at' => Carbon::parse('2026-10-31 00:00:00', 'Europe/Paris'),
            'all_day' => true,
        ]));

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261031', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261101', $ics);
    }

    #[Test]
    public function un_evenement_horaire_part_en_utc_sans_vtimezone(): void
    {
        // Une valeur relue de PostgreSQL arrive TOUJOURS en UTC : c'est cet
        // etat-la qu'il faut simuler. 06:00 UTC = 08:00 a Paris le 15 octobre.
        $ics = $this->build($this->todo([
            'due_at' => Carbon::parse('2026-10-15 06:00:00', 'UTC'),
            'all_day' => false,
        ]));

        $this->assertStringContainsString('DTSTART:20261015T060000Z', $ics);
        $this->assertStringNotContainsString(
            'BEGIN:VTIMEZONE',
            $ics,
            'Un DTSTART en UTC rend tout VTIMEZONE inutile.',
        );
    }

    #[Test]
    public function chaque_evenement_porte_au_moins_une_alarme(): void
    {
        $ics = $this->build($this->todo());

        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('ACTION:DISPLAY', $ics);
        $this->assertStringContainsString('TRIGGER', $ics);
    }

    #[Test]
    public function un_evenement_sur_la_journee_porte_deux_rappels(): void
    {
        $ics = $this->build($this->todo([
            'due_at' => Carbon::parse('2026-10-15 00:00:00', 'Europe/Paris'),
            'all_day' => true,
        ]));

        $this->assertSame(
            2,
            substr_count($ics, 'BEGIN:VALARM'),
            'Une echeance sans heure merite un rappel la veille ET un le jour meme.',
        );
    }

    #[Test]
    public function l_uid_est_deterministe_donc_idempotent(): void
    {
        $todo = $this->todo();

        $first = $this->build($todo);
        $second = $this->build($todo);

        preg_match('/UID:(.+)/', $first, $a);
        preg_match('/UID:(.+)/', $second, $b);

        $this->assertNotEmpty($a[1] ?? '');
        $this->assertSame(
            trim($a[1]),
            trim($b[1]),
            'Un UID stable est ce qui empeche de creer un doublon a chaque synchronisation.',
        );

        // L'UID est un UUIDv5 derive de l'identifiant, pas une concatenation :
        // seules la forme et la stabilite comptent.
        $this->assertMatchesRegularExpression('/^papers-todo-[0-9a-f-]{36}@papers\.app$/', trim($a[1]));

        // Deux taches differentes ne doivent jamais partager un UID, sinon
        // l'une ecraserait l'evenement de l'autre dans iCloud.
        $other = $this->todo(['id' => '0199c0de-1234-7abc-8def-ffffffffffff']);
        preg_match('/UID:(.+)/', $this->build($other), $c);

        $this->assertNotSame(trim($a[1]), trim($c[1]));
    }

    #[Test]
    public function les_lignes_sont_terminees_par_crlf(): void
    {
        $ics = $this->build($this->todo());

        $this->assertStringContainsString("\r\n", $ics);
        // Aucun LF orphelin : la RFC 5545 impose CRLF, et iCloud est strict.
        $this->assertSame(
            0,
            preg_match_all('/(?<!\r)\n/', $ics),
            'Un LF sans CR precedent viole la RFC 5545.',
        );
    }

    #[Test]
    public function les_lignes_sont_pliees_a_75_octets(): void
    {
        $ics = $this->build($this->todo([
            'title' => str_repeat('Rappel tres long pour forcer le pliage de ligne ', 6),
        ]));

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(
                75,
                strlen($line),
                'Toute ligne ICS doit etre pliee a 75 octets : '.substr($line, 0, 40).'…',
            );
        }
    }

    #[Test]
    public function les_accents_survivent_a_l_encodage(): void
    {
        $ics = $this->build($this->todo(['title' => 'Résiliation prélèvement']));

        // Le titre peut etre plie sur plusieurs lignes : on deplie avant de verifier.
        $unfolded = str_replace("\r\n ", '', $ics);

        $this->assertStringContainsString('Résiliation', $unfolded);
        $this->assertStringContainsString('prélèvement', $unfolded);
    }

    #[Test]
    public function une_echeance_ne_rend_pas_occupe(): void
    {
        $ics = $this->build($this->todo());

        // Sans TRANSPARENT, chaque facture bloquerait la disponibilite de
        // l utilisateur dans les invitations recues.
        $this->assertStringContainsString('TRANSP:TRANSPARENT', $ics);
    }

    #[Test]
    public function aucun_organizer_ni_attendee_n_est_emis(): void
    {
        $ics = $this->build($this->todo());

        // Leur presence declencherait le scheduling iCloud, donc l envoi de
        // vraies invitations par email.
        $this->assertStringNotContainsString('ORGANIZER', $ics);
        $this->assertStringNotContainsString('ATTENDEE', $ics);
        $this->assertStringNotContainsString('METHOD:', $ics);
    }
}
