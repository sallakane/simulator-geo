-- Zonage SYNTHÉTIQUE — ne remplace pas la donnée officielle.
--
-- Sert à deux choses (SPEC §10) :
--   · faire tourner les tests d'intégration sans les 600 Mo du shapefile ;
--   · donner un environnement de développement utilisable tout de suite.
--
-- Quatre carrés jointifs dans l'Essonne, un par niveau d'exposition, et rien
-- au-dessus de Paris — la carte officielle ne couvre pas la ville de Paris, et
-- ce trou doit être testable.
--
-- Le jeu de test de RÉFÉRENCE, lui, s'extrait de la donnée réelle
-- (`make points`) : on ne code jamais en dur une coordonnée supposée être dans
-- telle zone.

-- La base de test est créée depuis template1, qui n'a pas PostGIS : la fixture
-- l'installe elle-même plutôt que de dépendre d'une préparation externe.
CREATE EXTENSION IF NOT EXISTS postgis;

DROP VIEW IF EXISTS rga_zone_courante;
DROP TABLE IF EXISTS rga_zone_synthetique;

CREATE TABLE rga_zone_synthetique (
    id             bigserial PRIMARY KEY,
    geom           geometry(MultiPolygon, 4326),
    niveau_code    smallint,
    niveau_libelle varchar(50),
    millesime      varchar(10)
);

INSERT INTO rga_zone_synthetique (geom, niveau_code, niveau_libelle, millesime) VALUES
    (ST_Multi(ST_MakeEnvelope(2.35, 48.60, 2.45, 48.70, 4326)), 3, 'Fort',   'demo'),
    (ST_Multi(ST_MakeEnvelope(2.45, 48.60, 2.55, 48.70, 4326)), 2, 'Moyen',  'demo'),
    (ST_Multi(ST_MakeEnvelope(2.55, 48.60, 2.65, 48.70, 4326)), 1, 'Faible', 'demo'),
    (ST_Multi(ST_MakeEnvelope(2.65, 48.60, 2.75, 48.70, 4326)), 0, 'Nul',    'demo');

CREATE INDEX idx_rga_zone_synthetique_geom ON rga_zone_synthetique USING GIST(geom);
ANALYZE rga_zone_synthetique;

-- Le code n'interroge QUE cette vue, jamais une table millésimée (SPEC §4.4).
CREATE VIEW rga_zone_courante AS SELECT * FROM rga_zone_synthetique;
