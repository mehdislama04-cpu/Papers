<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colonne tsvector GÉNÉRÉE STORED + index GIN pour la recherche plein
     * texte française.
     *
     * POURQUOI 'fr_unaccent' ET PAS whereFullText(..., ['language' => 'french']) :
     *
     * 1. La grammaire PostgreSQL de Laravel remplace SILENCIEUSEMENT la langue
     *    demandée par 'english' si elle n'appartient pas à sa liste blanche
     *    validFullTextLanguages() (PostgresGrammar::whereFullText). Aucune
     *    exception n'est levée. 'french' y figure, mais une configuration
     *    personnalisée comme 'fr_unaccent' non : elle serait donc silencieusement
     *    dégradée en anglais.
     *
     * 2. Même avec 'french', la configuration livrée par PostgreSQL ne retire
     *    pas les accents. « echeance » ne trouverait alors jamais « échéance »,
     *    alors que c'est exactement ce qu'un utilisateur tape sur un clavier
     *    iOS pressé. Il faut une configuration qui enchaîne le dictionnaire
     *    unaccent avant french_stem — c'est ce que crée
     *    docker/postgres-init/01-extensions.sql sous le nom 'fr_unaccent'.
     *
     * 3. unaccent() seul n'est pas IMMUTABLE et ne peut donc pas figurer dans
     *    l'expression d'une colonne générée. En revanche to_tsvector(regconfig,
     *    text) EST immutable, y compris quand la configuration chaîne le
     *    dictionnaire unaccent : passer par la configuration au lieu d'un appel
     *    direct à unaccent() est ce qui rend la colonne générée possible.
     *
     * Le Blueprint de Laravel ne sait pas produire de colonne générée STORED,
     * d'où le DB::statement().
     *
     * CONSÉQUENCE POUR LES REQUÊTES — et c'est le piège suivant :
     * whereFullText('search_vector', $q, ['vector' => true]) utilise bien la
     * colonne telle quelle à gauche de @@, mais construit le côté droit avec
     * plainto_tsquery('english', ?) — la langue de la requête subit la même
     * liste blanche, et n'est donc PAS alignable sur 'fr_unaccent'. Les lexèmes
     * ne correspondraient pas. Document::scopeSearch() écrit donc la clause en
     * whereRaw() avec plainto_tsquery('fr_unaccent', ?), le seul moyen d'avoir
     * la même configuration des deux côtés de l'opérateur.
     *
     * Les poids A/B/C permettent à ts_rank de faire remonter une correspondance
     * dans le titre avant une correspondance noyée dans l'OCR brut.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE documents
                ADD COLUMN search_vector tsvector
                GENERATED ALWAYS AS (
                    setweight(to_tsvector('fr_unaccent', coalesce(title, '')), 'A') ||
                    setweight(to_tsvector('fr_unaccent', coalesce(summary, '')), 'B') ||
                    setweight(to_tsvector('fr_unaccent', coalesce(raw_text, '')), 'C')
                ) STORED
        SQL);

        DB::statement('CREATE INDEX documents_search_vector_gin ON documents USING gin (search_vector)');

        // Recherche floue sur les émetteurs (« EDF » vs « E.D.F. ») : pg_trgm
        // est déjà activé par docker/postgres-init/01-extensions.sql.
        DB::statement('CREATE INDEX documents_issuer_trgm ON documents USING gin (issuer gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_issuer_trgm');
        DB::statement('DROP INDEX IF EXISTS documents_search_vector_gin');

        if (Schema::hasColumn('documents', 'search_vector')) {
            DB::statement('ALTER TABLE documents DROP COLUMN search_vector');
        }
    }
};
