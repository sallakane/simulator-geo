#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# Le squelette Symfony peut ne pas être encore là (amorçage du dépôt) :
	# on démarre quand même, plutôt que d'échouer de façon obscure.
	if [ -f bin/console ]; then

		if [ -n "$DATABASE_URL" ]; then
			echo 'En attente de la base de données...'
			ATTEMPTS=0
			until php bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1 || [ "$ATTEMPTS" -ge 30 ]; do
				ATTEMPTS=$((ATTEMPTS + 1))
				sleep 1
			done
		fi

		# Migrations sur le SEUL service portant RUN_MIGRATIONS=1 (l'app, jamais
		# le worker : deux conteneurs migrant le même schéma en parallèle
		# finiraient mal).
		#
		# Un échec de migration empêche le conteneur de démarrer — c'est voulu :
		# mieux vaut une API down qu'une API servant un schéma qu'elle ne
		# comprend pas.
		if [ "${RUN_MIGRATIONS:-0}" = '1' ]; then
			echo 'Migrations Doctrine...'
			php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
			# Transport en doctrine://default?auto_setup=0 : la table
			# messenger_messages doit être créée explicitement, sinon le premier
			# relais de lead échoue (SPEC §6).
			php bin/console messenger:setup-transports --no-interaction || true
		fi
	fi
fi

exec docker-php-entrypoint "$@"
