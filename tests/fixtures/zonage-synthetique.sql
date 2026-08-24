-- Zonage SYNTHÉTIQUE — ne remplace pas la donnée officielle.
--
-- Sert à deux choses (SPEC §10) :
--   · faire tourner les tests d'intégration sans les 600 Mo du shapefile ;
--   · donner un environnement de développement utilisable tout de suite.
--
-- TROIS carrés jointifs dans l'Essonne (faible, moyen, fort) et rien d'autre.
--
-- Volontairement calqué sur la donnée officielle : elle ne contient AUCUN
-- polygone de niveau 0, parce qu'elle ne dessine que les zones exposées.
-- L'exposition nulle se déduit de l'absence de polygone — c'est ce
-- comportement qu'il faut tester, pas une ligne de fixture qui n'existe nulle
-- part en vrai. Rien au-dessus de Paris non plus : la carte ne couvre pas la
-- ville, et ce trou-là est un vrai hors-périmètre.
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
    (ST_Multi(ST_MakeEnvelope(2.55, 48.60, 2.65, 48.70, 4326)), 1, 'Faible', 'demo');

CREATE INDEX idx_rga_zone_synthetique_geom ON rga_zone_synthetique USING GIST(geom);
ANALYZE rga_zone_synthetique;

-- Le code n'interroge QUE cette vue, jamais une table millésimée (SPEC §4.4).
CREATE VIEW rga_zone_courante AS SELECT * FROM rga_zone_synthetique;
