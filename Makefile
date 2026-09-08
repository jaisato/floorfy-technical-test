# Shortcuts for the local stack and the quality gates.
#
# Everything under "development" runs on the host, against the sources in
# app/symfony; everything under "stack" drives docker compose.

SYMFONY_DIR := app/symfony
COMPOSE     := docker compose
PHP         := $(COMPOSE) exec -T php php

# ext-amqp is only needed at runtime by the AMQP transport, and is present in
# the Docker image; a host PHP without it can still install and run the suite.
COMPOSER_FLAGS := --ignore-platform-req=ext-amqp

.DEFAULT_GOAL := help
.PHONY: help up down build logs sh migrate worker prune ready install test test-unit test-func test-mysql test-ffmpeg stan cs cs-fix rector lint check

help: ## List the available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

## ---- stack -----------------------------------------------------------------

up: ## Build and start the stack (API on http://localhost:8080)
	$(COMPOSE) up -d --build

down: ## Stop the stack and remove its volumes
	$(COMPOSE) down -v

build: ## Rebuild the application image
	$(COMPOSE) build

logs: ## Follow the logs of every service
	$(COMPOSE) logs -f

sh: ## Open a shell in the php container
	$(COMPOSE) exec php sh

migrate: ## Apply pending migrations inside the stack
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction

worker: ## Follow the logs of both workers (video renders and webhooks)
	$(COMPOSE) logs -f worker callback-worker

ready: ## Run the readiness checks inside the stack (0 = ready)
	$(PHP) bin/console app:health:ready

prune: ## Delete the videos of tasks settled more than RETENTION ago (default 30d)
	$(PHP) bin/console app:videos:prune --older-than=$(or $(RETENTION),30d)

## ---- development -----------------------------------------------------------

install: ## Install the PHP dependencies
	cd $(SYMFONY_DIR) && composer install $(COMPOSER_FLAGS)

test: ## Run the test suite (the mysql and ffmpeg groups are excluded)
	cd $(SYMFONY_DIR) && php bin/phpunit

test-mysql: ## Run the tests that need a MySQL server
	cd $(SYMFONY_DIR) && php bin/phpunit --group mysql

test-ffmpeg: ## Run the tests that need the ffmpeg binary
	cd $(SYMFONY_DIR) && php bin/phpunit --group ffmpeg

stan: ## Static analysis (PHPStan, level 8)
	cd $(SYMFONY_DIR) && php bin/console cache:warmup --env=test --quiet && php vendor/bin/phpstan analyse --no-progress

cs: ## Report coding-standard violations
	cd $(SYMFONY_DIR) && php vendor/bin/php-cs-fixer check --diff

cs-fix: ## Apply the coding standard
	cd $(SYMFONY_DIR) && php vendor/bin/php-cs-fixer fix

rector: ## Report the automated refactorings Rector would apply
	cd $(SYMFONY_DIR) && php vendor/bin/rector process --dry-run --no-progress-bar

lint: ## Lint the container and the YAML configuration
	cd $(SYMFONY_DIR) && php bin/console lint:container --env=test && php bin/console lint:yaml config --env=test

check: cs stan lint test ## Everything CI runs, minus the service-backed groups
