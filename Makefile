.PHONY: setup test test-unit test-integration test-acceptance demo validate lint analyse cs-check cs-fix check shell

setup:
	docker compose build
	docker compose run --rm php composer install --no-interaction

test:
	docker compose run --rm php composer test

test-unit:
	docker compose run --rm php composer test:unit

test-integration:
	docker compose run --rm php composer test:integration

test-acceptance:
	docker compose run --rm php composer test:acceptance

demo:
	docker compose run --rm php composer demo

validate:
	docker compose run --rm php composer validate --strict

lint:
	docker compose run --rm php composer lint

analyse:
	docker compose run --rm php composer analyse

cs-check:
	docker compose run --rm php composer cs-check

cs-fix:
	docker compose run --rm php composer cs-fix

check: validate lint analyse cs-check test

shell:
	docker compose run --rm php sh
