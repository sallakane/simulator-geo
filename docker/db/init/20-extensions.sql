-- Exécuté une seule fois, à l'initialisation du volume, APRÈS le script de
-- l'image PostGIS (ordre alphabétique : 10_postgis.sh puis celui-ci).
--
-- L'image crée quatre extensions ; ce produit n'en utilise qu'une. Les trois
-- autres apportent des schémas entiers (tiger, topology) que Doctrine
-- introspecte : `doctrine:migrations:diff` génère alors des DROP sur des objets
-- de PostGIS lui-même. Les retirer, c'est à la fois moins de surface et un
-- diff exploitable.
DROP EXTENSION IF EXISTS postgis_tiger_geocoder CASCADE;
DROP EXTENSION IF EXISTS postgis_topology CASCADE;
DROP EXTENSION IF EXISTS fuzzystrmatch CASCADE;
DROP SCHEMA IF EXISTS tiger CASCADE;
DROP SCHEMA IF EXISTS tiger_data CASCADE;
DROP SCHEMA IF EXISTS topology CASCADE;
