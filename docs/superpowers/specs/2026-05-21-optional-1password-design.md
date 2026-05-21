# Optional 1Password, plain `.env` as default

## Problem

`make up`, `make build`, `make logs`, etc. and the server deploy currently require 1Password: every `docker compose` invocation is wrapped with `op run --environment $OP_ENV_ID --`. A user who doesn't use 1Password (or just wants to kick the tires) can't run the prod-like local stack, and self-hosters who'd rather manage secrets via a plain file are forced through `op` too.

Goal: make 1Password optional for all three flows (`make up`, `make dev`, server). Plain `.env` becomes the default; 1Password is an opt-in upgrade.

## Non-goals

- Reviving the `.env` FIFO/named-pipe mount. Separate issue, unchanged.
- New abstraction over `docker compose`. The selection mechanism is one conditional Make variable.
- Touching `iler/selfhost-configs` in this PR. A plain-env systemd unit there is a follow-up.
- `.env.testing` changes. Tests run inside the container; the file stays as-is.
- In-app env validator. Laravel errors clearly on missing required env at boot — adding our own validator duplicates that for no gain.

## Selection mechanism

The Makefile picks between 1Password and plain `.env` by inspecting `OP_ENV_ID`. The wrapper prefix is conditional:

```make
OP_ENV_ID ?=
DC := $(if $(OP_ENV_ID),op run --environment $(OP_ENV_ID) --no-masking --,) docker compose
```

- `OP_ENV_ID` non-empty → `op run` wraps every compose call (current behavior).
- `OP_ENV_ID` empty → `docker compose` runs directly and reads `./.env` via its built-in `${VAR}` substitution.

**Change from today:** the Makefile no longer hardcodes `OP_ENV_ID ?= einqhwbbevqifrwwxl66hvitpm`. Developers who use 1Password export `OP_ENV_ID` from their shell, direnv, or equivalent. The Makefile itself is identity-neutral so the same file works for a 1P-equipped developer and a plain-`.env` developer.

### Preflight

If `OP_ENV_ID` is set but `op` isn't on `PATH`, fail with a clear message before invoking compose:

```
OP_ENV_ID is set but 'op' is not on PATH. Install the 1Password CLI or unset OP_ENV_ID.
```

A single `command -v op` check at the top of the targets that use `$(DC)` covers this. No silent fallback — if the user opted into 1P, an unreachable `op` is an error.

## Env file lifecycle

The existing `.env` target that today only `dev` depends on becomes a prerequisite for all local targets:

```make
up build down logs shell ps: .env
```

Recipe is unchanged:

```make
.env: .env.example
	@test -f .env || cp .env.example .env
	@touch .env
```

First `make up` on a fresh checkout copies `.env.example` → `.env`. On a freshly-seeded `.env` with `OP_ENV_ID` empty, the recipe prints an "edit .env before re-running" hint and exits 0 — friendlier than letting compose start with blank vars and crash mysteriously later. (Implementation detail: a small marker, e.g. checking whether `cp` actually ran this invocation, gates the hint.)

## `make key` helper

A new Makefile target generates an `APP_KEY` without the user needing PHP installed locally:

```make
key:
	@docker run --rm --entrypoint php trmnl-ruuvi:dev -r \
		'echo "APP_KEY=base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Output is printed to stdout for the user to paste into `.env`. Uses the `dev` image (already built by `make dev` / `make test`) so no extra image is needed. If neither image is built yet, the user gets a clear `docker run` error pointing at the missing image — acceptable for a one-shot helper.

## Server deploy

`docker-compose.prod.yml` is unchanged — compose substitutes `${VAR}` from process env regardless of where that env came from.

The README documents two paths, both first-class:

### Path A — plain env file (the new default in docs)

```
/etc/trmnl-ruuvi/app.env        # 0600, root:root; APP_KEY, RUUVI_API_TOKEN, TRMNL_WEBHOOK_URL, …
/etc/trmnl-ruuvi/bootstrap.env  # 0600; TAG=<image tag>
```

Systemd unit uses two `EnvironmentFile=` lines and runs `podman-compose -f docker-compose.prod.yml up -d` directly. No `op` on the host. The actual unit file lives in `iler/selfhost-configs/trmnl-ruuvi/` (follow-up to add there).

### Path B — 1Password (existing)

`bootstrap.env` adds `OP_SERVICE_ACCOUNT_TOKEN` and `OP_ENVIRONMENT_ID`; the unit wraps compose with `op run --environment "$OP_ENVIRONMENT_ID"`. Unchanged from today.

The two paths are mutually exclusive per host — operators pick one when provisioning.

## README rewrite

Reorder so the plain-`.env` path is the default narrative:

1. **Configuration** — unchanged (the Ruuvi-side story).
2. **Local development** — leads with `cp .env.example .env`, `make key` to fill in `APP_KEY`, fill in `RUUVI_API_TOKEN` and `TRMNL_WEBHOOK_URL`, `make up`.
3. **Optional: 1Password Environment** — current content, reframed as "for shared/long-lived setups; export `OP_ENV_ID` and `make up` picks it up automatically."
4. **Server deploy** — Path A (plain env) first, Path B (1Password) second.
5. **Why not the local-`.env` FIFO mount** — unchanged.

## Failure modes

| Condition | Behavior |
| --- | --- |
| `OP_ENV_ID` set, `op` missing | Preflight error, exit non-zero, no fallback. |
| `OP_ENV_ID` set, `op run` exits non-zero | Exit code surfaces; no fallback. |
| `OP_ENV_ID` empty, `.env` missing | Makefile target creates `.env` from `.env.example`, prints edit hint, exits 0. |
| `OP_ENV_ID` empty, `.env` present, vars blank | Compose substitutes empty strings; the app fails at boot with the Laravel/Ruuvi-side error. |

## Testing

Manual matrix for `make up`:

- `OP_ENV_ID=…` exported (1P path) → unchanged behavior; `op run` invoked.
- `OP_ENV_ID=` with populated `.env` → app starts, fetches from Ruuvi, pushes to TRMNL.
- `OP_ENV_ID=` on a fresh checkout (no `.env`) → `.env` seeded, edit-hint printed, exits 0.
- `OP_ENV_ID=foo` with `op` uninstalled → preflight error.

`make dev` / `make test` unchanged; CI already exercises them.

No new automated tests. The Makefile/compose layer isn't unit-tested today and adding test infra for it is disproportionate to the change.

## Out-of-scope follow-ups

- Add a plain-env systemd unit to `iler/selfhost-configs/trmnl-ruuvi/` (Path A) so operators can copy a working unit file rather than write one from the README description.
- Revisit the FIFO repro when 1Password ships fixes upstream.
