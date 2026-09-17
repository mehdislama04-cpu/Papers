<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DocumentAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Les deux echelles de priorite du projet sont INVERSEES l'une par rapport a
 * l'autre, ce qui est une source de bug silencieux :
 *
 *  - le modele renvoie 1 = haute, 2 = moyenne, 3 = basse (ExtractionSchema) ;
 *  - la base stocke un entier centre sur 0 ou le PLUS GRAND est le PLUS urgent,
 *    borne a [-2, 2] par UpdateTodoRequest.
 *
 * Sans conversion, une action urgente serait classee tout en bas, et la valeur
 * 3 sortirait de la plage acceptee par PATCH — rendant la tache non modifiable.
 */
final class TodoPriorityTest extends TestCase
{
    #[Test]
    public function la_priorite_haute_du_modele_devient_la_plus_urgente(): void
    {
        $this->assertSame(1, DocumentAnalyzer::modelPriorityToStored(1));
    }

    #[Test]
    public function la_priorite_basse_du_modele_devient_la_moins_urgente(): void
    {
        $this->assertSame(-1, DocumentAnalyzer::modelPriorityToStored(3));
    }

    #[Test]
    public function la_priorite_moyenne_est_neutre(): void
    {
        $this->assertSame(0, DocumentAnalyzer::modelPriorityToStored(2));
    }

    #[Test]
    public function l_ordre_est_bien_inverse_entre_les_deux_echelles(): void
    {
        $haute = DocumentAnalyzer::modelPriorityToStored(1);
        $moyenne = DocumentAnalyzer::modelPriorityToStored(2);
        $basse = DocumentAnalyzer::modelPriorityToStored(3);

        $this->assertGreaterThan($moyenne, $haute);
        $this->assertGreaterThan($basse, $moyenne);
    }

    #[Test]
    public function toute_valeur_reste_dans_la_plage_acceptee_par_l_api(): void
    {
        // -2..2 est la plage validee par UpdateTodoRequest. Une valeur hors
        // plage rendrait la tache impossible a modifier par son propre endpoint.
        foreach ([-5, 0, 1, 2, 3, 4, 99] as $input) {
            $stored = DocumentAnalyzer::modelPriorityToStored($input);

            $this->assertGreaterThanOrEqual(-2, $stored, "Entree $input hors plage.");
            $this->assertLessThanOrEqual(2, $stored, "Entree $input hors plage.");
        }
    }
}
