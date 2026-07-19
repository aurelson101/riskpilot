COMPOSE := docker compose

.PHONY: install start stop restart logs migrate fixtures test lint shell-backend shell-frontend reset

install:
	@test -f .env || cp .env.example .env
	$(COMPOSE) build
	$(COMPOSE) run --rm backend composer install --no-interaction
	$(COMPOSE) run --rm frontend npm install

start:
	$(COMPOSE) up -d

stop:
	$(COMPOSE) down

restart: stop start

logs:
	$(COMPOSE) logs -f --tail=200

migrate:
	$(COMPOSE) exec backend php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
	$(COMPOSE) exec backend php bin/console doctrine:fixtures:load --no-interaction

test:
	$(COMPOSE) exec -e APP_ENV=test backend php bin/phpunit
	$(COMPOSE) exec frontend npm test

lint:
	$(COMPOSE) exec backend vendor/bin/phpstan analyse src tests
	$(COMPOSE) exec backend vendor/bin/php-cs-fixer check --diff
	$(COMPOSE) exec frontend npm run lint

shell-backend:
	$(COMPOSE) exec backend sh

shell-frontend:
	$(COMPOSE) exec frontend sh

reset:
	$(COMPOSE) down --volumes --remove-orphans
	$(COMPOSE) build --no-cache
	$(COMPOSE) up -d
