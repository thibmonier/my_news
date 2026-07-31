# Briefly AI — Makefile
# Toutes les commandes passent par Docker pour isolation de l'environnement.
# Constitution §3 : conteneurisation obligatoire.

DOCKER_COMPOSE = docker compose
EXEC_APP = $(DOCKER_COMPOSE) exec app
PHP = $(EXEC_APP) php
COMPOSER = $(EXEC_APP) composer
CONSOLE = $(PHP) bin/console
VENDOR_BIN = $(EXEC_APP) vendor/bin

.PHONY: help up down build install sh \
        test phpstan deptrac cs cs-fix tokens \
        migrate migrate-diff migrate-rollback \
        cache-clear db-create db-drop

# ── Aide ─────────────────────────────────────────────────────────────────────

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help

# ── Docker ────────────────────────────────────────────────────────────────────

up: ## Démarre tous les services (build si nécessaire)
	$(DOCKER_COMPOSE) up -d --remove-orphans

down: ## Arrête tous les services
	$(DOCKER_COMPOSE) down --remove-orphans

build: ## (Re)construit les images Docker
	$(DOCKER_COMPOSE) build --pull --no-cache

restart: ## Redémarre l'application
	$(DOCKER_COMPOSE) restart app

logs: ## Affiche les logs en temps réel
	$(DOCKER_COMPOSE) logs -f

# ── Installation ──────────────────────────────────────────────────────────────

install: ## Installe les dépendances Composer
	$(COMPOSER) install

install-prod: ## Installe les dépendances Composer (sans dev)
	$(COMPOSER) install --no-dev --optimize-autoloader

update: ## Met à jour les dépendances Composer
	$(COMPOSER) update

# ── Accès shell ───────────────────────────────────────────────────────────────

sh: ## Ouvre un shell dans le container app
	$(EXEC_APP) sh

sh-db: ## Ouvre psql dans le container database
	$(DOCKER_COMPOSE) exec database psql -U briefly -d briefly

sh-redis: ## Ouvre redis-cli dans le container redis
	$(DOCKER_COMPOSE) exec redis redis-cli

# ── Qualité ───────────────────────────────────────────────────────────────────

test: ## Exécute la suite de tests Pest
	$(EXEC_APP) vendor/bin/pest --colors=always

test-coverage: ## Exécute les tests avec rapport de couverture
	$(EXEC_APP) vendor/bin/pest --coverage --min=80

phpstan: ## Analyse statique PHPStan (niveau max)
	$(VENDOR_BIN)/phpstan analyse --no-progress --memory-limit=512M

deptrac: ## Vérifie les dépendances entre couches hexagonales
	$(VENDOR_BIN)/deptrac analyse --no-progress

cs: ## Vérifie le code style (dry-run, sans modification)
	$(VENDOR_BIN)/php-cs-fixer fix --dry-run --diff

cs-fix: ## Corrige le code style
	$(VENDOR_BIN)/php-cs-fixer fix

audit: ## Audit de sécurité des dépendances Composer
	$(COMPOSER) audit

quality: cs phpstan deptrac audit ## Exécute tous les contrôles qualité

tokens: ## Publie les design tokens Stitch vers public/css (source: project-management/design)
	cp project-management/design/design-tokens.css public/css/tokens.css

# ── Base de données ───────────────────────────────────────────────────────────

db-create: ## Crée la base de données
	$(CONSOLE) doctrine:database:create --if-not-exists

db-drop: ## Supprime la base de données (DANGEREUX)
	$(CONSOLE) doctrine:database:drop --force --if-exists

migrate: ## Exécute les migrations Doctrine
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migrate-diff: ## Génère une migration depuis les changements d'entités
	$(CONSOLE) doctrine:migrations:diff

migrate-rollback: ## Annule la dernière migration
	$(CONSOLE) doctrine:migrations:migrate prev --no-interaction

# ── Cache ─────────────────────────────────────────────────────────────────────

cache-clear: ## Vide le cache Symfony
	$(CONSOLE) cache:clear

cache-warmup: ## Précauffe le cache Symfony
	$(CONSOLE) cache:warmup

# ── Workflow complet ──────────────────────────────────────────────────────────

setup: build up install db-create migrate ## Setup complet de l'environnement dev
	@echo "\033[32mBriefly AI est prêt ! http://localhost\033[0m"

ci: install cs phpstan deptrac test audit ## Simule le pipeline CI en local
