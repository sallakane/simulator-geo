# Raccourcis de développement — SPEC §12. Tout passe par Docker : PostGIS 3.4 et
# shp2pgsql ne s'installent pas proprement sur un poste, et c'est la même image
# qui part en production.
#
# Amorçage d'un dépôt neuf (le squelette Symfony n'est pas versionné ici) :
#   composer create-project symfony/skeleton /tmp/sk && cp -rn /tmp/sk/. . && rm -rf /tmp/sk
#   composer require symfony/messenger symfony/rate-limiter doctrine/doctrine-migrations-bundle
#   make up

# Compose n'interpole les ${...} que depuis .env par défaut ; les secrets sont
# dans .env.local. Le second l'emporte sur le premier.
# Les conteneurs de dev tournent sous l'UID du poste (cf. compose.yaml) : sans
# ça, var/cache et tout fichier écrit depuis un conteneur appartiendrait à root.
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

COMPOSE := docker compose --env-file .env --env-file .env.local
APP     := $(COMPOSE) exec app

.DEFAULT_GOAL := help
.PHONY: help up down fresh build logs sh psql migrate test composer rga zonage-demo verifier ogrinfo points

help: ## Liste les cibles
	@grep -hE '^[a-z.-]+:.*?## ' $(MAKEFILE_LIST) | sort | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

.env.local:
	@cp .env.local.example $@ && chmod 600 $@
	@echo "→ .env.local créé depuis le gabarit."
	@echo "  Renseigner APP_SECRET et POSTGRES_PASSWORD MAINTENANT : le mot de passe"
	@echo "  n'est lu qu'à l'initialisation du volume Postgres (SPEC §12)."
	@exit 1

up: .env.local ## Build + démarre la pile (migrations comprises)
	$(COMPOSE) up -d --build --remove-orphans
	@echo "→ API     http://localhost:8085/api/v1/health"
	@echo "→ Mailpit http://localhost:8028"

down: ## Arrête la pile (les données restent)
	$(COMPOSE) down

fresh: ## Arrête ET SUPPRIME le volume Postgres (perte des données locales)
	@printf 'Supprimer la base locale (zonage, partenaires, leads) ? [y/N] ' && read a && [ "$$a" = y ]
	$(COMPOSE) down -v

build: ## Reconstruit les images sans cache
	$(COMPOSE) build --no-cache

logs: ## Suit les logs (app + base)
	$(COMPOSE) logs -f

sh: ## Shell dans le conteneur app
	$(APP) sh

psql: ## Console PostgreSQL
	$(COMPOSE) exec database psql -U app -d zonage

migrate: ## Rejoue les migrations Doctrine
	$(APP) php bin/console doctrine:migrations:migrate --no-interaction

test: ## PHPUnit (crée et migre la base de test au besoin)
	$(APP) php bin/console doctrine:database:create --env=test --if-not-exists -q
	$(APP) php bin/console doctrine:migrations:migrate --env=test --no-interaction -q
	$(APP) php bin/phpunit

zonage-demo: ## Charge un zonage synthétique (3 carrés en Essonne) — dev sans les 400 Mo
	@n=$$($(COMPOSE) exec -T database psql -U app -d zonage -Atc "SELECT count(*) FROM information_schema.tables WHERE table_name ~ '^rga_zone_[0-9]{4}$$'" | tr -d '\r'); \
	if [ "$$n" != "0" ] && [ "$(FORCE)" != "1" ]; then \
	  echo "⚠ Un millésime officiel est chargé : le remplacer par 3 carrés se verrait mal en production."; \
	  echo "  Forcer     : make zonage-demo FORCE=1"; \
	  echo "  Revenir au réel : ./bin/charger-rga.sh --millesime <AAAA> --bascule"; \
	  exit 1; \
	fi
	$(COMPOSE) exec -T database psql -U app -d zonage -q -v ON_ERROR_STOP=1 < tests/fixtures/zonage-synthetique.sql
	@echo "→ zonage synthétique en place ; le vrai millésime se charge avec make rga"

verifier: ## Rejoue le jeu de points de référence contre le zonage en service (SPEC §10)
	$(APP) php bin/console app:zonage:verifier

composer: ## Composer dans le conteneur — ex. make composer c="require foo/bar"
	$(APP) composer $(c)

rga: ## Charge le shapefile RGA dans PostGIS (SPEC §4)
	./bin/charger-rga.sh

points: ## Régénère tests/fixtures/points-reference.json depuis la donnée (SPEC §10)
	./bin/charger-rga.sh --points

ogrinfo: ## Relève champs, valeurs et SRID du shapefile (SPEC §4.1)
	@shp=$$(find data -maxdepth 1 -name '*.shp' | head -1); \
	[ -n "$$shp" ] || { echo "Aucun .shp dans data/ — cf. docs/donnees-rga.md"; exit 1; }; \
	$(COMPOSE) run --rm gis ogrinfo -so -al "/data/$$(basename $$shp)"
