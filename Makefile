.PHONY: setup test demo validate shell

setup:
	docker compose build
	docker compose run --rm php composer install --no-interaction

test:
	docker compose run --rm php composer test

demo:
	docker compose run --rm php composer demo

validate:
	docker compose run --rm php composer validate --strict

shell:
	docker compose run --rm php sh
