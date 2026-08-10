# Use Docker by default. Run `DOCKER=0 make <target>` to invoke the host
# toolchain instead (requires PHP 8.4+, Composer, and ext-curl on the host).
DOCKER ?= 1

ifeq ($(DOCKER),1)
DC := docker compose run --rm php
else
DC :=
endif

.PHONY: build install test lint fix stan ci shell

build:
ifeq ($(DOCKER),1)
	docker compose build
else
	@echo "DOCKER=0: nothing to build (using host toolchain)."
endif

install:
	$(DC) composer install

test:
	$(DC) vendor/bin/phpunit

lint:
	$(DC) vendor/bin/php-cs-fixer fix --dry-run --diff

fix:
	$(DC) vendor/bin/php-cs-fixer fix

stan:
	$(DC) vendor/bin/phpstan analyse --memory-limit=1G

ci: lint stan test

shell:
ifeq ($(DOCKER),1)
	$(DC) sh
else
	@echo "DOCKER=0: no container shell. Use your normal shell."
endif
