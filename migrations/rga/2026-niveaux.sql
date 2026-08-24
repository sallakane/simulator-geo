-- Mapping « valeur source → niveau_code » du millésime 2026 (arrêté du
-- 9 janvier 2026). Appliqué automatiquement par bin/charger-rga.sh.
--
-- Relevé du 2026-08-24 sur AleaRG_2025_Fxx_L93.shp (cf. docs/donnees-rga.md) :
--   · champ `niveau`, de type Real ;
--   · trois valeurs seulement : 1, 2, 3 ;
--   · 121 399 polygones ;
--   · aucun polygone de niveau 0.
--
-- ⚠️ L'ABSENCE de polygone vaut « exposition nulle », elle n'est pas un trou
-- dans la donnée. La carte ne dessine que les zones exposées : 395 000 km²
-- couverts sur ~552 000 km² de métropole. C'est le ZonageResolver qui traduit
-- cette absence, et c'est pour ça que le chargement CONTRÔLE le nombre de
-- polygones : une donnée partiellement chargée répondrait « pas de risque » sur
-- des régions entières, sans la moindre erreur.

UPDATE rga_zone_2026 SET niveau_code = CASE round(niveau)::int
    WHEN 1 THEN 1   -- faible
    WHEN 2 THEN 2   -- moyen
    WHEN 3 THEN 3   -- fort
END;

ALTER TABLE rga_zone_2026 ADD COLUMN IF NOT EXISTS niveau_libelle VARCHAR(50);
UPDATE rga_zone_2026 SET niveau_libelle = CASE round(niveau)::int
    WHEN 1 THEN 'Faible'
    WHEN 2 THEN 'Moyen'
    WHEN 3 THEN 'Fort'
END;

-- Garde-fou : une valeur non prévue (nouveau millésime, échelle modifiée) ne
-- doit pas passer inaperçue. Sans ça, la zone concernée répondrait « exposition
-- nulle » en production — un faux négatif silencieux sur une obligation légale.
DO $$
DECLARE orphelins bigint;
BEGIN
    SELECT count(*) INTO orphelins FROM rga_zone_2026 WHERE niveau_code IS NULL;
    IF orphelins > 0 THEN
        RAISE EXCEPTION '% polygones sans niveau_code : valeur source hors du mapping', orphelins;
    END IF;
END $$;
