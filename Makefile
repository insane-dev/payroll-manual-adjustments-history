.PHONY: setup test demo validate lint check shell

setup:
	docker compose build
	docker compose run --rm php composer install --no-interaction

test:
	docker compose run --rm php composer test

demo:
	docker compose run --rm php composer demo

validate:
	docker compose run --rm php composer validate --strict

lint:
	docker compose run --rm php composer lint

check: validate lint test

shell:
	docker compose run --rm php sh
