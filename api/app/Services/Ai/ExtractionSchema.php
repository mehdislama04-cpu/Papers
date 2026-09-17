<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * JSON Schema strict pour text.format des Structured Outputs (Responses API).
 *
 * Contraintes du sous-ensemble accepté (ARCHITECTURE.md §6, RECHERCHE.md research:openai) :
 *  - la racine est un object, jamais un anyOf ;
 *  - additionalProperties:false sur CHAQUE objet ;
 *  - TOUS les champs dans required — un champ optionnel s'émule avec type:["string","null"] ;
 *  - pattern / format / minimum / maximum / minItems / maxItems sont désormais supportés
 *    sur les modèles de base (pas sur les modèles fine-tunés) : c'est le levier
 *    anti-hallucination principal, on s'en sert.
 *
 * Rappel : le schéma est classé "system data" chez OpenAI et peut sortir de la région de
 * résidence — aucune donnée personnelle dans les `description`.
 */
class ExtractionSchema
{
    /** Nom du schéma envoyé dans text.format.name. */
    public const NAME = 'papers_document_extraction';

    /**
     * Les 12 catégories système, dans l'ordre exact de DatabaseSeeder::CATEGORIES.
     * Toute divergence ferait classer les documents dans une catégorie inexistante.
     * Si la config `papers.categories` est définie, elle prime.
     *
     * @var list<string>
     */
    public const CATEGORY_SLUGS = [
        'facture',
        'contrat',
        'sante',
        'impots',
        'banque',
        'assurance',
        'administratif',
        'scolaire',
        'immobilier',
        'vehicule',
        'emploi',
        'autre',
    ];

    /** @var list<string> */
    public const DOC_TYPES = [
        'facture',
        'devis',
        'contrat',
        'courrier_administratif',
        'releve_bancaire',
        'bulletin_paie',
        'avis_imposition',
        'ordonnance',
        'attestation',
        'convocation',
        'recu',
        'autre',
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'doc_type',
                'title',
                'language',
                'issuer',
                'recipient',
                'doc_date',
                'due_date',
                'reference',
                'total_amount',
                'currency',
                'summary',
                'key_facts',
                'category_slug',
                'actions',
            ],
            'properties' => [
                'doc_type' => [
                    'type' => 'string',
                    'enum' => self::DOC_TYPES,
                    'description' => 'Nature du document.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Titre court et factuel, en francais, tel qu identifiable sur le document.',
                ],
                'language' => [
                    'type' => 'string',
                    'enum' => ['fr', 'en', 'de', 'es', 'it', 'nl', 'pt', 'autre'],
                    'description' => 'Langue du document.',
                ],
                'issuer' => [
                    'type' => ['string', 'null'],
                    'description' => 'Emetteur (organisme ou personne) tel qu imprime. null si absent.',
                ],
                'recipient' => [
                    'type' => ['string', 'null'],
                    'description' => 'Destinataire tel qu imprime. null si absent.',
                ],
                'doc_date' => [
                    'type' => ['string', 'null'],
                    'format' => 'date',
                    'description' => 'Date d emission au format AAAA-MM-JJ. null si absente.',
                ],
                'due_date' => [
                    'type' => ['string', 'null'],
                    'format' => 'date',
                    'description' => 'Date limite explicite au format AAAA-MM-JJ. null si absente.',
                ],
                'reference' => [
                    'type' => ['string', 'null'],
                    'description' => 'Reference principale (numero de facture, de dossier, de client).',
                ],
                'total_amount' => [
                    'type' => ['number', 'null'],
                    'minimum' => 0,
                    'description' => 'Montant total a payer, en unite monetaire. null si le document n en comporte pas.',
                ],
                'currency' => [
                    'type' => ['string', 'null'],
                    'pattern' => '^[A-Z]{3}$',
                    'description' => 'Code ISO 4217 de la devise du montant total. null si aucun montant.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Resume factuel en francais, 3 a 5 phrases, sans interpretation ni conseil.',
                ],
                'key_facts' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'items' => [
                        'type' => 'string',
                        'description' => 'Fait saillant du document, une phrase courte en francais.',
                    ],
                ],
                'category_slug' => [
                    'type' => 'string',
                    'enum' => $this->categorySlugs(),
                    'description' => 'Categorie de classement. Utiliser autre si aucune ne convient.',
                ],
                'actions' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'description' => 'Actions concretes demandees au destinataire. Tableau vide si le document n en demande aucune.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'details', 'due_date', 'all_day', 'priority', 'confidence'],
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'Action a l infinitif, en francais, 80 caracteres maximum.',
                            ],
                            'details' => [
                                'type' => ['string', 'null'],
                                'description' => 'Precision utile citant le document. null si inutile.',
                            ],
                            'due_date' => [
                                'type' => ['string', 'null'],
                                'format' => 'date',
                                'description' => 'Echeance AAAA-MM-JJ lisible sur le document ou calculee depuis un delai explicite. null sinon.',
                            ],
                            'all_day' => [
                                'type' => 'boolean',
                                'description' => 'true si l echeance est une date sans heure precise.',
                            ],
                            'priority' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 3,
                                'description' => '1 haute, 2 moyenne, 3 basse.',
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 1,
                                'description' => 'Confiance dans l action et son echeance, de 0 a 1.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Bloc text.format complet — name/schema/strict sont FRÈRES de type
     * (piège classique : en Chat Completions ils sont imbriqués sous json_schema).
     *
     * @return array<string, mixed>
     */
    public function format(): array
    {
        return [
            'type' => 'json_schema',
            'name' => self::NAME,
            'strict' => true,
            'schema' => $this->schema(),
        ];
    }

    /**
     * @return list<string>
     */
    public function categorySlugs(): array
    {
        $configured = config('papers.categories');

        if (is_array($configured) && $configured !== []) {
            // Accepte aussi bien ['slug' => 'Libellé'] que ['slug', ...].
            $slugs = array_is_list($configured) ? $configured : array_keys($configured);

            return array_values(array_map(strval(...), $slugs));
        }

        return self::CATEGORY_SLUGS;
    }
}
