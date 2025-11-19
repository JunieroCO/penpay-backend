.PHONY: up down build logs shell test format worker

up:
	docker-compose up -d

down:
	docker-compose down

build:
	docker-compose build --no-cache

logs:
	docker-compose logs -f app

shell:
	docker exec -it penpay_app sh

worker:
	docker exec -it penpay_workers sh

test:
	vendor/bin/phpunit --testdox

format:
	vendor/bin/php-cs-fixer fix