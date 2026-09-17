-- Extensions requises par Papers.
CREATE EXTENSION IF NOT EXISTS vector;      -- pgvector : recherche semantique
CREATE EXTENSION IF NOT EXISTS unaccent;    -- normalisation des accents
CREATE EXTENSION IF NOT EXISTS pg_trgm;     -- recherche floue / similarite

-- Configuration de recherche plein texte francaise insensible aux accents.
-- unaccent() n'est pas IMMUTABLE et ne peut donc pas etre appele dans une
-- colonne generee ; on chaine le dictionnaire unaccent AVANT french_stem,
-- ce qui rend to_tsvector('fr_unaccent', ...) immutable et indexable.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'fr_unaccent') THEN
        CREATE TEXT SEARCH CONFIGURATION fr_unaccent (COPY = french);
        ALTER TEXT SEARCH CONFIGURATION fr_unaccent
            ALTER MAPPING FOR hword, hword_part, word
            WITH unaccent, french_stem;
    END IF;
END
$$;
