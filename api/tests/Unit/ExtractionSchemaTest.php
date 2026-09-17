<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\ExtractionSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le mode strict des Structured Outputs d'OpenAI impose des contraintes qui,
 * si elles sont violees, font echouer TOUS les appels par une 400 — et on ne
 * s'en apercevrait qu'en production, sur le premier document scanne.
 *
 * Ces tests verifient le schema recursivement, y compris dans $defs.
 */
final class ExtractionSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return (new ExtractionSchema())->schema();
    }

    #[Test]
    public function la_racine_est_un_objet_et_jamais_un_anyof(): void
    {
        $schema = $this->schema();

        $this->assertSame('object', $schema['type'] ?? null, 'La racine doit etre un object.');
        $this->assertArrayNotHasKey(
            'anyOf',
            $schema,
            'La racine ne peut jamais etre un anyOf : une union discriminee a la racine provoque une 400.',
        );
    }

    #[Test]
    public function chaque_objet_porte_additional_properties_false(): void
    {
        $offenders = [];
        $this->walk($this->schema(), '$', function (array $node, string $path) use (&$offenders): void {
            if (($node['type'] ?? null) !== 'object') {
                return;
            }

            if (($node['additionalProperties'] ?? null) !== false) {
                $offenders[] = $path;
            }
        });

        $this->assertSame(
            [],
            $offenders,
            "additionalProperties:false est obligatoire sur CHAQUE objet, pas seulement a la racine.\n"
            .'Manquant en : '.implode(', ', $offenders),
        );
    }

    #[Test]
    public function chaque_objet_liste_toutes_ses_proprietes_dans_required(): void
    {
        $offenders = [];
        $this->walk($this->schema(), '$', function (array $node, string $path) use (&$offenders): void {
            if (($node['type'] ?? null) !== 'object' || ! isset($node['properties'])) {
                return;
            }

            $declared = array_keys($node['properties']);
            $required = $node['required'] ?? [];
            $missing = array_diff($declared, $required);

            if ($missing !== []) {
                $offenders[] = $path.' -> '.implode(', ', $missing);
            }
        });

        $this->assertSame(
            [],
            $offenders,
            "En mode strict il n'existe pas de champ optionnel : TOUTES les proprietes doivent figurer dans "
            ."required (un champ optionnel s'emule avec type:['x','null']).\n"
            .'Manquants : '.implode(' | ', $offenders),
        );
    }

    #[Test]
    public function aucun_mot_cle_non_supporte_n_est_utilise(): void
    {
        // allOf, not, if/then/else, dependentRequired et dependentSchemas ne
        // sont pas supportes par les Structured Outputs.
        $forbidden = ['allOf', 'not', 'if', 'then', 'else', 'dependentRequired', 'dependentSchemas'];
        $offenders = [];

        $this->walk($this->schema(), '$', function (array $node, string $path) use ($forbidden, &$offenders): void {
            foreach ($forbidden as $keyword) {
                if (array_key_exists($keyword, $node)) {
                    $offenders[] = "$path.$keyword";
                }
            }
        });

        $this->assertSame([], $offenders, 'Mots-cles non supportes : '.implode(', ', $offenders));
    }

    #[Test]
    public function le_schema_declare_les_champs_metier_attendus(): void
    {
        $properties = $this->schema()['properties'] ?? [];

        foreach (['doc_type', 'title', 'summary', 'category_slug', 'actions'] as $field) {
            $this->assertArrayHasKey($field, $properties, "Le champ $field manque au schema.");
        }

        $this->assertSame(
            'array',
            $properties['actions']['type'] ?? null,
            'actions doit etre un tableau : c est lui qui alimente les taches et les echeances.',
        );
    }

    #[Test]
    public function le_schema_est_serialisable_en_json(): void
    {
        $json = json_encode($this->schema(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertIsString($json);
        $this->assertNotSame('', $json);
    }

    /**
     * Parcourt recursivement le schema : properties, items, $defs.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>, string): void  $visitor
     */
    private function walk(array $node, string $path, callable $visitor): void
    {
        $visitor($node, $path);

        foreach (['properties', '$defs'] as $container) {
            if (! isset($node[$container]) || ! is_array($node[$container])) {
                continue;
            }

            foreach ($node[$container] as $key => $child) {
                if (is_array($child)) {
                    $this->walk($child, "$path.$container.$key", $visitor);
                }
            }
        }

        if (isset($node['items']) && is_array($node['items'])) {
            $this->walk($node['items'], "$path.items", $visitor);
        }
    }
}
