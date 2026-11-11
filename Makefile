# kurrentdb-php — developer harness shortcuts.
# The docker-compose stack is kurrentdb (insecure mode) + php (8.4, composer).
# Run `make up` once before any container-exec target in a fresh session.

COMPOSER := docker compose exec -T php composer

.DEFAULT_GOAL := help

.PHONY: help up install test down lock validate tap tap-test render

help:
	@echo "──────────────────────────────────────────────────────────"
	@echo " 🐘 kurrentdb-php · developer harness"
	@echo "──────────────────────────────────────────────────────────"
	@echo "  🚀 make up        build + start the kurrentdb & php containers"
	@echo "  📦 make install   composer install inside the php container"
	@echo "  🧪 make test      run the acceptance suite against the real server"
	@echo "  ─── wire debugging (see .opencode/skills/wire-debug) ───"
	@echo "  🕵️ make tap       start the wire-tap (netdump) on the compose network"
	@echo "  📡 make tap-test  run the suite through the wire-tap"
	@echo "  📜 make render    render captured raw HTTP/2 frames with h2dump"
	@echo "  ─── housekeeping ───"
	@echo "  🧹 make down      stop the stack"
	@echo "  🔒 make lock      refresh composer.lock content-hash after composer.json edits"
	@echo "  ✅ make validate  validate composer.json"
	@echo "──────────────────────────────────────────────────────────"

up:
	docker compose up -d --build

install:
	$(COMPOSER) install

test:
	docker compose exec -T php vendor/bin/phpunit

down:
	docker compose down

lock:
	$(COMPOSER) update --lock

validate:
	$(COMPOSER) validate --no-check-publish

## tap: netdump listens on :2114 inside the compose network, forwards to
## kurrentdb:2113, mirrors both directions into spike/dump/*.bin.
tap:
	@net=$$(docker compose ps -q kurrentdb | xargs docker inspect -f '{{range $$k,$$_ := .NetworkSettings.Networks}}{{$$k}}{{end}}'); \
	docker rm -f netdump >/dev/null 2>&1; \
	docker run -d --name netdump --network $$net \
	  -v "$$PWD/spike/dump":/dump -v "$$PWD/spike/netdump":/app \
	  golang:1.25-alpine /app/netdump

tap-test: tap
	docker compose exec -T -e KURRENTDB_URI=kurrentdb://netdump:2114?Tls=false php vendor/bin/phpunit

render:
	python3 spike/dump/h2dump.py spike/dump/client2server-conn*.bin
	@echo "-- response side:"
	python3 spike/dump/h2dump.py spike/dump/server2client-conn*.bin
