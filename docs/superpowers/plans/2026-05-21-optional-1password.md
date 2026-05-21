# Optional 1Password / Plain `.env` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make 1Password optional for `make up`, `make dev`, and the server compose flow. Plain project-root `.env` is the default; 1Password is opt-in via `OP_ENV_ID`.

**Architecture:** A single conditional in the `Makefile` switches the `docker compose` wrapper between `op run …` (when `OP_ENV_ID` is set) and a bare invocation (otherwise). Compose's built-in `${VAR}` substitution reads `./.env` automatically in the bare case. No app code changes; `docker-compose.prod.yml` is untouched.

**Tech Stack:** GNU Make, Docker Compose v2, 1Password CLI (`op`), Laravel 12 / FrankenPHP image.

**Spec:** `docs/superpowers/specs/2026-05-21-optional-1password-design.md`

---

## File Structure

- **Modify:** `Makefile` — remove hardcoded `OP_ENV_ID` default, conditional `DC` wrapper, `op` preflight, widen `.env` dependency, edit-hint guard, new `key` target.
- **Modify:** `README.md` — reorder so plain `.env` is the default narrative; reframe 1Password section as opt-in; document Path A / Path B for server deploy.
- **No changes:** `docker-compose.yml`, `docker-compose.dev.yml`, `docker-compose.prod.yml`, `.env.example`, `Dockerfile`, `docker/init.d/`. The compose-layer `${VAR}` substitution already does the right thing in both modes, and the container entrypoint already generates `APP_KEY` when blank.

Two responsibilities. Two files. No new modules.

## Note on TDD / testing

There is no automated test surface for the Makefile or README. Each task has explicit shell-level verification commands with expected output instead of unit tests. Treat these as the red/green steps — run the command, confirm the output matches, then commit. The existing app-level test suite (Pest) is untouched.

---

### Task 1: Remove hardcoded `OP_ENV_ID` default and add conditional `DC` wrapper

**Files:**
- Modify: `Makefile`

- [ ] **Step 1: Read current `Makefile` state**

The relevant lines (currently 28–30):

```make
OP_ENV_ID ?= einqhwbbevqifrwwxl66hvitpm
DC := op run --environment $(OP_ENV_ID) --no-masking -- docker compose
DC_DEV := docker compose -f docker-compose.yml -f docker-compose.dev.yml
```

- [ ] **Step 2: Replace the `OP_ENV_ID` default and `DC` definition**

Change lines 28–29 to:

```make
# Empty default: developers who use 1Password export OP_ENV_ID from their
# shell / direnv. With it set, every docker compose call is wrapped with
# `op run --environment`. Without it, compose reads ./.env directly via
# its built-in ${VAR} substitution.
OP_ENV_ID ?=
DC := $(if $(OP_ENV_ID),op run --environment $(OP_ENV_ID) --no-masking --,) docker compose
```

Leave `DC_DEV` (line 30) and everything else unchanged.

- [ ] **Step 3: Verify the bare-compose expansion**

Run:

```bash
make -n up
```

Expected: the printed command starts with `docker compose up -d` (no `op run` prefix, possibly preceded by a leading space — that's harmless).

- [ ] **Step 4: Verify the 1Password expansion**

Run:

```bash
OP_ENV_ID=einqhwbbevqifrwwxl66hvitpm make -n up
```

Expected: the printed command starts with `op run --environment einqhwbbevqifrwwxl66hvitpm --no-masking -- docker compose up -d`.

- [ ] **Step 5: Commit**

```bash
git add Makefile
git commit -m "Make OP_ENV_ID default empty; switch DC wrapper on its presence

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 2: Add `op` preflight check

**Files:**
- Modify: `Makefile`

- [ ] **Step 1: Add a `_check-op` phony target**

Insert after the `DC_DEV` line and before the existing `.PHONY` declaration. The block:

```make
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
```

- [ ] **Step 2: Add `_check-op` as a prerequisite to every prod-like target**

Update the existing target lines:

```make
up: _check-op .env
	@$(DC) up -d

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
```

`down`, `logs`, `shell`, `ps` get `_check-op` only — they don't need `.env`. `up` and `build` get both because compose will read `${VAR}` substitutions from `.env`.

Also extend the `.PHONY` line on the next line so it includes `_check-op`:

```make
.PHONY: up down build logs shell ps test dev dev-down dev-build dev-logs dev-shell dev-ps _check-op
```

- [ ] **Step 3: Verify preflight passes when `op` is installed**

```bash
OP_ENV_ID=foo make -n _check-op
```

Expected: prints nothing (or just the make echo of the recipe lines, which are silenced by `@`); exit 0. If you actually have `op` installed, this is a no-op.

- [ ] **Step 4: Verify preflight fails when `OP_ENV_ID` set but `op` missing**

Simulate missing `op` by overriding `PATH`:

```bash
OP_ENV_ID=foo PATH=/usr/bin:/bin make _check-op
```

Expected (only valid if `op` is not in `/usr/bin` or `/bin` — it almost never is):

```
Error: OP_ENV_ID is set (foo) but 'op' is not on PATH.
Install the 1Password CLI or unset OP_ENV_ID to use plain .env.
```

Exit code: 1.

If `op` happens to live in `/usr/bin` or `/bin` on the test machine, instead test by renaming the binary temporarily, or skip this verification and trust the `command -v` logic.

- [ ] **Step 5: Verify preflight is a no-op when `OP_ENV_ID` empty**

```bash
make _check-op
```

Expected: prints nothing, exit 0.

- [ ] **Step 6: Commit**

```bash
git add Makefile
git commit -m "Add op preflight: fail loudly when OP_ENV_ID set but op missing

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 3: Make `.env` a dependency of `up` and `build`, with an edit-hint guard

**Files:**
- Modify: `Makefile`

- [ ] **Step 1: Confirm `.env` target already exists**

Lines around 84–87 of the current `Makefile`:

```make
# Seed .env from .env.example on first `make dev`. Idempotent.
.env: .env.example
	@test -f .env || cp .env.example .env
	@touch .env
```

This is fine as-is — it's idempotent, so adding it as a prereq to more targets is safe.

- [ ] **Step 2: Confirm `up` and `build` already depend on `.env` (added in Task 2)**

`up` and `build` should now both list `.env` as a prerequisite. If not, fix that now.

- [ ] **Step 3: Add the edit-hint guard for `make up`**

Replace the current `up` recipe:

```make
up: _check-op .env
	@$(DC) up -d
```

with:

```make
up: _check-op .env
	@if [ -z "$(strip $(OP_ENV_ID))" ] && ! grep -qE '^RUUVI_API_TOKEN=.+' .env; then \
		echo "RUUVI_API_TOKEN is empty in .env."; \
		echo "Edit .env (set RUUVI_API_TOKEN and TRMNL_WEBHOOK_URL), then re-run 'make up'."; \
		echo "Or export OP_ENV_ID to pull secrets from a 1Password Environment."; \
		exit 0; \
	fi
	@$(DC) up -d
```

Rationale: APP_KEY is auto-generated by the container entrypoint (`docker/init.d/00-app-key.sh`), so we don't need to guard on it. RUUVI_API_TOKEN has no sensible default and is the var most likely to be blank on a fresh checkout. TRMNL_WEBHOOK_URL is in the same boat but checking one is enough for the hint to fire — the message names both.

- [ ] **Step 4: Verify the guard fires on a freshly seeded `.env`**

```bash
rm -f .env
unset OP_ENV_ID
make up
```

Expected:

```
RUUVI_API_TOKEN is empty in .env.
Edit .env (set RUUVI_API_TOKEN and TRMNL_WEBHOOK_URL), then re-run 'make up'.
Or export OP_ENV_ID to pull secrets from a 1Password Environment.
```

Exit code: 0. `docker compose up` was **not** invoked. `.env` now exists at the project root.

- [ ] **Step 5: Verify the guard does not fire when `RUUVI_API_TOKEN` has a value**

```bash
sed -i.bak 's/^RUUVI_API_TOKEN=$/RUUVI_API_TOKEN=test-token/' .env && rm .env.bak
make -n up
```

Expected: the dry-run prints `docker compose up -d` (the guard short-circuit isn't visible in `-n` for the `@if` recipe, but the final `$(DC) up -d` line is). Then revert:

```bash
sed -i.bak 's/^RUUVI_API_TOKEN=test-token/RUUVI_API_TOKEN=/' .env && rm .env.bak
```

- [ ] **Step 6: Verify the guard does not fire when `OP_ENV_ID` is set (even with blank `.env`)**

```bash
OP_ENV_ID=foo make -n up
```

Expected: dry-run output includes `op run --environment foo … docker compose up -d`. No edit-hint.

- [ ] **Step 7: Commit**

```bash
git add Makefile
git commit -m "Print edit-hint when .env lacks RUUVI_API_TOKEN and no 1P fallback

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 4: Add `make key` helper

**Files:**
- Modify: `Makefile`

- [ ] **Step 1: Add the `key` target**

Insert after the `ps` target and before the `# ----- dev (no op run needed) -----` separator:

```make
# Generate a Laravel APP_KEY without needing PHP installed locally.
# Prints `APP_KEY=base64:<32-random-bytes>` for the user to paste into .env.
# Uses the same FrankenPHP base image the prod/dev stages build on; pulled
# on first run, cached thereafter.
key:
	@docker run --rm --entrypoint php \
		ghcr.io/serversideup/php:8.5-frankenphp-alpine -r \
		'echo "APP_KEY=base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Add `key` to the `.PHONY` line:

```make
.PHONY: up down build logs shell ps test dev dev-down dev-build dev-logs dev-shell dev-ps _check-op key
```

- [ ] **Step 2: Verify output format**

```bash
make key
```

Expected: a single line of the form `APP_KEY=base64:<44-char-base64>`, e.g. `APP_KEY=base64:7Hc8VfP5q…=`.

(First run pulls the image; subsequent runs are near-instant.)

- [ ] **Step 3: Commit**

```bash
git add Makefile
git commit -m "Add 'make key' helper to generate APP_KEY without local PHP

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 5: Rewrite `README.md` to lead with plain `.env`

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Replace the "Local development" and (new) "Optional: 1Password" sections**

Current `README.md` lines 22–34 are the "Local development" section. Replace lines 22–34 with the following block:

````markdown
## Local development

Quick start from a fresh checkout:

```sh
make up        # seeds .env from .env.example, then prints an edit hint because RUUVI_API_TOKEN is blank
make key       # prints APP_KEY=base64:… (paste into .env)
# edit .env:
#   APP_KEY=base64:…        (from `make key`)
#   RUUVI_API_TOKEN=…       (from Ruuvi Cloud)
#   TRMNL_WEBHOOK_URL=…     (from your TRMNL plugin)
make up        # starts the stack
```

If `RUUVI_API_TOKEN` is blank in `.env` and no 1Password Environment is configured, `make up` prints an edit-hint and exits 0 instead of starting compose with empty vars. Once the token is set, `make up` brings the stack up. `APP_KEY` is generated by `make key` (which runs `php` in a throwaway container, so no local PHP is required).

Other targets:

```sh
make build      # build the app image
make down       # stop
make logs       # tail app logs
make shell      # shell into the app container
make test       # run Pest tests inside the dev container
```

## Optional: 1Password Environment

For shared or long-lived setups (and the server), keep secrets in a 1Password Environment instead of `.env`. Export the Environment ID once:

```sh
export OP_ENV_ID=einqhwbbevqifrwwxl66hvitpm   # in ~/.zshrc, direnv, etc.
```

With `OP_ENV_ID` set, every `make` target above wraps `docker compose` with `op run --environment $OP_ENV_ID --`. The 1Password desktop app + `op` CLI authenticate via system biometrics — no service-account token is needed locally.

If `OP_ENV_ID` is set but `op` isn't on `PATH`, `make` fails with a clear preflight error rather than silently falling back to `.env`.
````

- [ ] **Step 2: Replace the "Server deploy" section**

Current `README.md` lines 36–74 are the "Server deploy" + "Why not the local-`.env` FIFO mount" sections. Replace lines 36–70 (everything up to but not including the `## Why not …` heading) with:

````markdown
## Server deploy

Quadlet unit files and the install guide live in **[iler/selfhost-configs/trmnl-ruuvi/](https://github.com/iler/selfhost-configs/tree/main/trmnl-ruuvi)**. CI publishes images to GHCR on every merge to `main`; a daily auto-update timer picks up new tags.

Why a separate repo: server configs (host paths, systemd choices, which secrets backend) belong with the operator, not the app source.

Two secrets-backend paths, both first-class. Pick one when provisioning:

### Path A — plain env file (no 1Password on the host)

Operator writes two root-only files:

```
/etc/trmnl-ruuvi/app.env         # APP_KEY, RUUVI_API_TOKEN, TRMNL_WEBHOOK_URL, NIGHTWATCH_TOKEN, …
/etc/trmnl-ruuvi/bootstrap.env   # TAG=<image tag>
```

Both `0600 root:root`. The systemd unit loads them via two `EnvironmentFile=` lines and runs:

```sh
podman-compose -f docker-compose.prod.yml up -d
```

No `op` on the host. Rotation = edit the file, `systemctl restart`.

### Path B — 1Password Environment

`bootstrap.env` instead carries:

```
OP_SERVICE_ACCOUNT_TOKEN=ops_eyJ...
OP_ENVIRONMENT_ID=einqhwbbevqifrwwxl66hvitpm
TAG=0.1.0
```

The systemd unit wraps compose with `op run --environment "$OP_ENVIRONMENT_ID" --`. Rotating the SA token: edit `bootstrap.env`, restart. Rotating any app secret: edit the 1P Environment Variables tab, restart — secrets are fetched fresh on every `compose up`.

For environments without systemd (or for quick prod-mimicking on a dev box), either path works manually:

```sh
# Path A
set -a; . /etc/trmnl-ruuvi/app.env; . /etc/trmnl-ruuvi/bootstrap.env; set +a
podman-compose -f docker-compose.prod.yml up -d

# Path B
set -a; . /etc/trmnl-ruuvi/bootstrap.env; set +a
op run --environment "$OP_ENVIRONMENT_ID" -- \
    podman-compose -f docker-compose.prod.yml up -d
```
````

The "Why not the local-`.env` FIFO mount" section (lines 71+) stays as-is — don't touch it.

- [ ] **Step 3: Verify the README renders cleanly**

```bash
gh markdown-preview README.md 2>/dev/null || cat README.md | head -100
```

Expected (visual): "Configuration" section, then new "Local development", then "Optional: 1Password Environment", then new "Server deploy" with Path A + Path B, then unchanged "Why not the local-`.env` FIFO mount" section.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "Lead README with plain .env workflow; 1Password and Path A/B documented

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 6: Verification matrix (manual)

**Files:** none modified.

Run the four matrix cases from the spec end-to-end. Each is a smoke test; if any fails, fix and re-verify before continuing.

- [ ] **Case 1: `OP_ENV_ID` set, normal 1Password path**

```bash
export OP_ENV_ID=einqhwbbevqifrwwxl66hvitpm
make up
make ps     # confirm app + nightwatch-agent are running
make logs   # ctrl-C after seeing the scheduler tick
make down
```

Expected: `op run` wraps each call (visible as a 1Password Touch ID / auth prompt the first time); containers come up; logs show Ruuvi fetch hitting the API; clean shutdown.

- [ ] **Case 2: `OP_ENV_ID` empty, populated `.env`**

```bash
unset OP_ENV_ID
# .env should already have real values from your local setup; if not, edit it.
make up
make ps
make logs   # ctrl-C after seeing the scheduler tick
make down
```

Expected: no `op` invocation; compose reads `.env` directly; app fetches from Ruuvi and pushes to TRMNL.

- [ ] **Case 3: `OP_ENV_ID` empty, fresh checkout**

```bash
unset OP_ENV_ID
rm -f .env
make up
```

Expected:

```
RUUVI_API_TOKEN is empty in .env.
Edit .env (set RUUVI_API_TOKEN and TRMNL_WEBHOOK_URL), then re-run 'make up'.
Or export OP_ENV_ID to pull secrets from a 1Password Environment.
```

Exit code 0; no containers started; `.env` now exists at the project root (seeded from `.env.example`). Restore your real `.env` after this test.

- [ ] **Case 4: `OP_ENV_ID` set, `op` missing**

```bash
export OP_ENV_ID=foo
PATH=/usr/bin:/bin make up
```

(Adjust `PATH` to exclude wherever your `op` lives.) Expected:

```
Error: OP_ENV_ID is set (foo) but 'op' is not on PATH.
Install the 1Password CLI or unset OP_ENV_ID to use plain .env.
```

Exit code 1.

- [ ] **Case 5: `make dev` and `make test` unchanged**

```bash
unset OP_ENV_ID
make dev
make test     # may take a while on a cold start
make dev-down
```

Expected: dev stack comes up, Pest tests run inside the container, results print. No `op` invocation anywhere.

- [ ] **No commit for this task** — verification only. Any fixes you make along the way should land in the relevant earlier task's commit (amend if it was the immediately previous commit; otherwise a new fix-up commit is fine).

---

## Done criteria

- All six tasks above completed; verification matrix passes.
- `Makefile` and `README.md` reflect the spec.
- `docs/superpowers/specs/2026-05-21-optional-1password-design.md` is unchanged (it was committed during brainstorming).
- No changes to `docker-compose*.yml`, `Dockerfile`, `.env.example`, `docker/init.d/`.

Plan complete.
