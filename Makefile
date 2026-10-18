.PHONY: key up down seed test test-api test-api-pg test-dashboard

PHP_IMAGE = composer:2
DOCKER_PHP = docker run --rm -m 700m -u $$(id -u):$$(id -g) -e COMPOSER_HOME=/tmp/composer -v "$$PWD/api":/app -w /app

# Create ./.env with a fresh APP_KEY (needed once before `make up`).
key:
	@test -f .env || cp .env.example .env
	@KEY=$$(docker run --rm php:8.4-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32));') && \
		sed -i "s|^APP_KEY=.*|APP_KEY=$$KEY|" .env && echo "APP_KEY written to .env"

up:
	docker compose up --build -d

down:
	docker compose down

# Demo tenants: manager@northwind.test / password and manager@harbour.test / password
seed:
	docker compose run --rm migrate php artisan db:seed --force

test: test-api test-dashboard

test-dashboard:
	cd dashboard && npm ci --silent && npm test && npm run build

# Fast loop on SQLite (the RLS suite is skipped there, with a reason).
test-api:
	$(DOCKER_PHP) $(PHP_IMAGE) sh -c 'composer install -q && cp -n .env.example .env; php artisan key:generate -q; vendor/bin/pint --test && php artisan test'

# Full suite on PostgreSQL 18, as CI runs it (needs `make up` for the postgres service).
test-api-pg:
	docker compose exec -T postgres psql -U scheduler -d scheduler -tc "select 1 from pg_database where datname='scheduler_test'" | grep -q 1 || \
		docker compose exec -T postgres createdb -U scheduler scheduler_test
	docker compose run --rm --no-deps -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer -e DB_DATABASE=scheduler_test -e DB_USERNAME=scheduler -e DB_PASSWORD=scheduler \
		-v "$$PWD/api":/app api sh -c 'composer install -q && php artisan test'
