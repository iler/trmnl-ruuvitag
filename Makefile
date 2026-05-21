# Two parallel workflows:
#
# 1. PROD-LIKE LOCAL — `make up` / `make down` / etc.
#    Builds the `prod` Dockerfile stage. Wraps `docker compose` with
#    `op run --environment` so the 1Password Environment is the single
#    source of truth for app secrets in both local and server contexts.
#    On macOS, `op` authenticates via the 1Password desktop app
#    (biometric / system auth), so no service-account token is needed
#    locally.
#
#    Server-side, the equivalent `op run --environment` call is made by
#    the systemd unit / launcher around `podman-compose -f
#    docker-compose.prod.yml` using a service-account token from
#    /etc/trmnl-ruuvi/bootstrap.env.
#
# 2. DEV — `make dev` / `make test` / etc.
#    Builds the `dev` Dockerfile stage and overlays
#    docker-compose.dev.yml. Source is bind-mounted from the host so
#    edits don't need `docker compose cp`. Does NOT require 1Password —
#    runs from a plain `.env` (auto-created from .env.example on first
#    `make dev`).
#
# Historical context: an earlier iteration mounted the 1Password
# Environment as a named-pipe at ./.env. Compose's multi-open access
# pattern triggered two distinct FIFO bugs on 1Password's side that made
# the mount unusable. Filed upstream: <link when available>.

# Empty default: developers who use 1Password export OP_ENV_ID from their
# shell / direnv. With it set, every docker compose call is wrapped with
# `op run --environment`. Without it, compose reads ./.env directly via
# its built-in ${VAR} substitution.
OP_ENV_ID ?=
DC := $(if $(OP_ENV_ID),op run --environment $(OP_ENV_ID) --no-masking --,) docker compose
DC_DEV := docker compose -f docker-compose.yml -f docker-compose.dev.yml

# Preflight: if the user opted into 1Password by exporting OP_ENV_ID,
# `op` must be reachable. Don't silently fall back to bare compose —
# stale or wrong secrets would slip through unnoticed.
.PHONY: _check-op
_check-op:
ifneq ($(strip $(OP_ENV_ID)),)
	@command -v op >/dev/null 2>&1 || { \
		echo "Error: OP_ENV_ID is set ($(OP_ENV_ID)) but 'op' is not on PATH."; \
		echo "Install the 1Password CLI or unset OP_ENV_ID to use plain .env."; \
		exit 1; \
	}
endif

.PHONY: up down build logs shell ps test dev dev-down dev-build dev-logs dev-shell dev-ps _check-op

# ----- prod-like local -----

up: _check-op .env
	@if [ -z "$(strip $(OP_ENV_ID))" ] && ! grep -qE '^RUUVI_API_TOKEN=.+' .env; then \
		echo "RUUVI_API_TOKEN is empty in .env."; \
		echo "Edit .env (set RUUVI_API_TOKEN and TRMNL_WEBHOOK_URL), then re-run 'make up'."; \
		echo "Or export OP_ENV_ID to pull secrets from a 1Password Environment."; \
	else \
		$(DC) up -d; \
	fi

down: _check-op
	@$(DC) down

build: _check-op .env
	@$(DC) build

logs: _check-op
	@$(DC) logs -f

shell: _check-op
	@$(DC) exec app sh

ps: _check-op
	@$(DC) ps

# ----- dev (no op run needed) -----

dev: .env
	@$(DC_DEV) up -d --build

dev-down:
	@$(DC_DEV) down

dev-build:
	@$(DC_DEV) build

dev-logs:
	@$(DC_DEV) logs -f

dev-shell:
	@$(DC_DEV) exec app sh

dev-ps:
	@$(DC_DEV) ps

# Test target runs in the dev compose (where Pest is installed). Auto-starts
# the dev stack if it isn't already up, then waits until the entrypoint
# scripts have populated .env with an APP_KEY — the docker healthcheck is
# HTTP-only and goes "healthy" before key:generate finishes writing the
# bind-mounted .env file on a cold start.
test: dev
	@until grep -q '^APP_KEY=base64:' .env 2>/dev/null; do sleep 1; done
	@until [ "$$(docker inspect -f '{{.State.Health.Status}}' initial-version-app-1 2>/dev/null)" = healthy ]; do sleep 1; done
	@$(DC_DEV) exec -T app php artisan test

# Seed .env from .env.example on first `make dev`. Idempotent.
.env: .env.example
	@test -f .env || cp .env.example .env
	@touch .env
