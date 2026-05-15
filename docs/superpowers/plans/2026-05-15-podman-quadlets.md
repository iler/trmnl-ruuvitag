# Podman Quadlets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a systemd-managed podman quadlets deploy path for prod, coexisting with the existing `docker-compose.prod.yml` as a first-class equal. Auto-update from GHCR. 1Password-resolved secrets.

**Architecture:** Five unit files in `deploy/` get installed to `/etc/containers/systemd/` and `/etc/systemd/system/` on the prod host. A oneshot `op-inject.service` resolves 1P references to a tmpfs `/run/trmnl-ruuvi/env`, which both `.container` quadlets `EnvironmentFile=`. Containers join a named `trmnl-ruuvi.network` so DNS by container name is preserved. `AutoUpdate=registry` plus `podman-auto-update.timer` handles low-touch image refresh.

**Tech Stack:** podman quadlets (`.network` / `.volume` / `.container`), systemd, 1Password `op inject`, Pest (parity test), GitHub Actions (CI validator), Docker (the validator harness — runs `quay.io/podman/stable` to invoke the quadlet generator).

**Spec:** `docs/superpowers/specs/2026-05-15-podman-quadlets-design.md`

---

### Task 1: Add `verify-quadlets` Makefile target

**Files:**
- Create: `deploy/quadlets/` (empty directory)
- Modify: `Makefile` — append a `verify-quadlets` target and add it to `.PHONY`

- [ ] **Step 1: Create the deploy quadlets directory**

```bash
mkdir -p deploy/quadlets
```

- [ ] **Step 2: Add the Makefile target**

In `Makefile`, change the `.PHONY` line (line 32) from:

```makefile
.PHONY: up down build logs shell ps test dev dev-down dev-build dev-logs dev-shell dev-ps
```

to:

```makefile
.PHONY: up down build logs shell ps test dev dev-down dev-build dev-logs dev-shell dev-ps verify-quadlets
```

Append at end of file:

```makefile

# ----- prod (quadlets) -----

# Validate quadlet unit files by running podman's quadlet generator in
# --dryrun mode against deploy/quadlets/. Exits non-zero on any parse error.
# Used by CI and by admins to sanity-check edits before copying files to
# /etc/containers/systemd/ on a server.
verify-quadlets:
	@echo "==> Validating quadlet unit files..."
	@docker run --rm \
		-v $(PWD)/deploy/quadlets:/etc/containers/systemd:ro \
		quay.io/podman/stable \
		/usr/libexec/podman/quadlet --dryrun /tmp/out >/dev/null
	@echo "    all quadlets parse cleanly"
```

- [ ] **Step 3: Run against empty directory**

```bash
make verify-quadlets
```

Expected output:
```
==> Validating quadlet unit files...
    all quadlets parse cleanly
```

Exits 0 (no files means no errors).

- [ ] **Step 4: Verify the validator detects broken units**

Drop in a known-bad quadlet to confirm the validator actually fails on bad input.

```bash
cat > deploy/quadlets/broken.container <<'EOF'
[Container]
Image=
EOF
make verify-quadlets ; echo "exit=$?"
```

Expected: non-zero exit (`exit=1` or `exit=2`), and the quadlet generator prints an error about empty `Image=`.

- [ ] **Step 5: Remove the broken file**

```bash
rm deploy/quadlets/broken.container
make verify-quadlets
```

Expected: clean run, exits 0.

- [ ] **Step 6: Commit**

```bash
git add Makefile
git commit -m "$(cat <<'EOF'
Add make verify-quadlets target for unit-file validation

Spins up quay.io/podman/stable, mounts deploy/quadlets read-only at
/etc/containers/systemd, and runs the podman quadlet generator in
--dryrun mode. Catches parse errors before unit files reach a real
host. Validator failure on a malformed file was sanity-checked before
landing.

Co-Authored-By: Claude
EOF
)"
```

---

### Task 2: Create `trmnl-ruuvi.network` quadlet

**Files:**
- Create: `deploy/quadlets/trmnl-ruuvi.network`

- [ ] **Step 1: Create the file with this exact content**

```ini
[Network]
NetworkName=trmnl-ruuvi
```

- [ ] **Step 2: Run `make verify-quadlets`**

```bash
make verify-quadlets
```

Expected: exits 0; one `.network` unit converts cleanly.

- [ ] **Step 3: Commit**

```bash
git add deploy/quadlets/trmnl-ruuvi.network
git commit -m "$(cat <<'EOF'
Add trmnl-ruuvi.network quadlet

Named podman network so app and nightwatch-agent containers resolve
each other by container name via aardvark-dns. Preserves the DNS
posture of the compose default network so NIGHTWATCH_INGEST_URI=
nightwatch-agent:2407 keeps working unchanged.

Co-Authored-By: Claude
EOF
)"
```

---

### Task 3: Create `ruuvi-db.volume` quadlet

**Files:**
- Create: `deploy/quadlets/ruuvi-db.volume`

- [ ] **Step 1: Create the file**

```ini
[Volume]
VolumeName=ruuvi-db
```

- [ ] **Step 2: Run `make verify-quadlets`**

Expected: clean run; two units now parse.

- [ ] **Step 3: Commit**

```bash
git add deploy/quadlets/ruuvi-db.volume
git commit -m "$(cat <<'EOF'
Add ruuvi-db.volume quadlet for SQLite database persistence

Named podman volume, parity with compose's `volumes: ruuvi-db:`. The
app.container will mount it at /var/www/html/database with the :Z
flag (no-op on non-SELinux hosts; required for relabeling on SELinux).

Co-Authored-By: Claude
EOF
)"
```

---

### Task 4: Create `nightwatch-agent.container` quadlet

**Files:**
- Create: `deploy/quadlets/nightwatch-agent.container`

- [ ] **Step 1: Create the file**

```ini
[Unit]
Description=Nightwatch agent for trmnl-ruuvi
Requires=op-inject.service
After=op-inject.service

[Container]
Image=docker.io/laravelphp/nightwatch-agent:v1
ContainerName=nightwatch-agent
Network=trmnl-ruuvi.network
EnvironmentFile=/run/trmnl-ruuvi/env
AutoUpdate=registry
HealthCmd=php nightwatch-status
HealthInterval=30s
HealthTimeout=5s
HealthRetries=3
HealthStartPeriod=5s

[Service]
Restart=always

[Install]
WantedBy=multi-user.target
```

Notes baked into this file:
- `EnvironmentFile=/run/trmnl-ruuvi/env` references a file that op-inject.service writes at boot. The quadlet generator does **not** validate that path exists at generation time (it's resolved by systemd at start time), so `verify-quadlets` is happy even though the file won't exist on the dev box.
- `AutoUpdate=registry` makes `podman-auto-update` pull new `:v1` digests as Laravel ships them.
- The healthcheck mirrors the compose values 1:1.

- [ ] **Step 2: Run `make verify-quadlets`**

Expected: clean run; three units parse.

- [ ] **Step 3: Commit**

```bash
git add deploy/quadlets/nightwatch-agent.container
git commit -m "$(cat <<'EOF'
Add nightwatch-agent.container quadlet

Tracks docker.io/laravelphp/nightwatch-agent:v1 with AutoUpdate=registry
so patch releases on the v1 floating tag land via the daily podman-
auto-update timer. Healthcheck values match compose 1:1. Joins the
named trmnl-ruuvi network so DNS by container name works for the app.

Co-Authored-By: Claude
EOF
)"
```

---

### Task 5: Create `app.container` quadlet

**Files:**
- Create: `deploy/quadlets/app.container`

- [ ] **Step 1: Create the file**

```ini
[Unit]
Description=trmnl-ruuvi app
Requires=op-inject.service trmnl-ruuvi-network.service
After=op-inject.service trmnl-ruuvi-network.service
Wants=nightwatch-agent.service
After=nightwatch-agent.service

[Container]
Image=ghcr.io/iler/trmnl-ruuvitag:latest
ContainerName=app
Network=trmnl-ruuvi.network
PublishPort=8080:8080
Volume=ruuvi-db.volume:/var/www/html/database:Z
EnvironmentFile=/run/trmnl-ruuvi/env
AutoUpdate=registry
Environment=SSL_MODE=off
Environment=AUTORUN_ENABLED=true
Environment=PHP_OPCACHE_ENABLE=1
Environment=APP_ENV=production
Environment=APP_TIMEZONE=UTC
Environment=DB_CONNECTION=sqlite
Environment=DB_DATABASE=/var/www/html/database/database.sqlite
Environment=RUUVI_DISPLAY_TZ=Europe/Helsinki
Environment=TRMNL_PLUGIN_TYPE=private
Environment=TRMNL_DATA_STRATEGY=webhook
Environment=NIGHTWATCH_INGEST_URI=nightwatch-agent:2407

[Service]
Restart=always

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 2: Run `make verify-quadlets`**

Expected: clean run; all four units parse.

- [ ] **Step 3: Commit**

```bash
git add deploy/quadlets/app.container
git commit -m "$(cat <<'EOF'
Add app.container quadlet

Pulls ghcr.io/iler/trmnl-ruuvitag:latest with AutoUpdate=registry so
new images published by CI on merge to main get picked up by the daily
podman-auto-update timer (with rollback on startup failure). All 11
non-secret Environment= entries match compose literally; the 4 secrets
(APP_KEY, RUUVI_API_TOKEN, TRMNL_WEBHOOK_URL, NIGHTWATCH_TOKEN) come
from EnvironmentFile=/run/trmnl-ruuvi/env, written by op-inject.service
at boot. Volume mount uses :Z so it's correct on SELinux and a no-op
elsewhere.

Co-Authored-By: Claude
EOF
)"
```

---

### Task 6: Create `op-inject.service` + `secrets.env.tmpl`

**Files:**
- Create: `deploy/secrets.env.tmpl`
- Create: `deploy/systemd/op-inject.service`

- [ ] **Step 1: Create the secrets template**

```bash
mkdir -p deploy/systemd
```

`deploy/secrets.env.tmpl`:
```
APP_KEY=op://Production/trmnl-ruuvi/APP_KEY
RUUVI_API_TOKEN=op://Production/trmnl-ruuvi/RUUVI_API_TOKEN
TRMNL_WEBHOOK_URL=op://Production/trmnl-ruuvi/TRMNL_WEBHOOK_URL
NIGHTWATCH_TOKEN=op://Production/trmnl-ruuvi/NIGHTWATCH_TOKEN
```

Note: `Production/trmnl-ruuvi/<field>` is a placeholder layout. The admin edits this file on the host to match their real 1Password vault/item paths before enabling the service. This is documented in the README install steps (Task 9).

- [ ] **Step 2: Create the oneshot systemd service**

`deploy/systemd/op-inject.service`:
```ini
[Unit]
Description=Resolve 1Password references for trmnl-ruuvi
ConditionPathExists=/etc/trmnl-ruuvi/bootstrap.env
ConditionPathExists=/etc/trmnl-ruuvi/secrets.env.tmpl

[Service]
Type=oneshot
RemainAfterExit=yes
EnvironmentFile=/etc/trmnl-ruuvi/bootstrap.env
RuntimeDirectory=trmnl-ruuvi
RuntimeDirectoryMode=0700
UMask=0077
ExecStart=/usr/local/bin/op inject -i /etc/trmnl-ruuvi/secrets.env.tmpl -o /run/trmnl-ruuvi/env

[Install]
WantedBy=multi-user.target
```

Key properties baked in:
- `RuntimeDirectory=trmnl-ruuvi` makes systemd create `/run/trmnl-ruuvi/` (tmpfs, 0700, root) and remove it on stop. Secrets never touch disk.
- `UMask=0077` ensures the written `env` file is mode 0600.
- `RemainAfterExit=yes` keeps the service `active` so containers' `Requires=op-inject.service` doesn't trip immediately after the oneshot finishes.

- [ ] **Step 3: Sanity-check the systemd unit syntax**

The `verify-quadlets` target uses the quadlet generator which only inspects `.container` / `.network` / `.volume` files — it ignores `.service` files. A regular systemd unit is best verified by `systemd-analyze verify`, which is not available in the validator container. For now we rely on careful authoring + the *First-deploy checklist* in the spec. Do a manual eyeball pass: confirm each `[Section]` header is present, each directive is in the right section, no typos in directive names.

- [ ] **Step 4: Commit**

```bash
git add deploy/secrets.env.tmpl deploy/systemd/op-inject.service
git commit -m "$(cat <<'EOF'
Add op-inject.service and secrets template for the quadlet path

deploy/systemd/op-inject.service is a oneshot that runs `op inject` at
boot to materialize /run/trmnl-ruuvi/env from a template, then keeps
RemainAfterExit=yes so dependent containers see it as active. Secrets
stay on tmpfs (RuntimeDirectory=) with mode 0600 (UMask=0077). On
failure the service stays in "failed" state and containers refuse to
start — better than booting with empty secrets.

deploy/secrets.env.tmpl ships placeholder op:// paths; admins adjust
on the host to match their real 1P vault/item layout.

Co-Authored-By: Claude
EOF
)"
```

---

### Task 7: Add Pest test for compose ↔ quadlet env parity

**Files:**
- Create: `tests/Unit/QuadletParityTest.php`

- [ ] **Step 1: Verify `symfony/yaml` is installed**

```bash
docker compose -f docker-compose.dev.yml exec -T app composer show symfony/yaml | head -5
```

Expected: shows version info. `symfony/yaml` is a transitive dependency of `laravel/framework` so it should be present. If the command exits non-zero, run:

```bash
docker compose -f docker-compose.dev.yml exec -T app composer require --dev symfony/yaml
```

…then commit the resulting `composer.json` + `composer.lock` change separately before continuing.

- [ ] **Step 2: Write the failing test**

`tests/Unit/QuadletParityTest.php`:
```php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

function extractEnvKeysFromQuadlet(string $path): array
{
    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^Environment=([A-Z_]+)=/', $line, $m)) {
            $keys[] = $m[1];
        }
    }
    return $keys;
}

function extractEnvKeysFromTemplate(string $path): array
{
    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z_]+)=/', $line, $m)) {
            $keys[] = $m[1];
        }
    }
    return $keys;
}

function quadletEffectiveEnv(string $serviceName): array
{
    $root = __DIR__ . '/../..';
    $quadletKeys = extractEnvKeysFromQuadlet("{$root}/deploy/quadlets/{$serviceName}.container");
    $secretKeys = extractEnvKeysFromTemplate("{$root}/deploy/secrets.env.tmpl");
    return array_values(array_unique(array_merge($quadletKeys, $secretKeys)));
}

function composeEnvKeys(string $serviceName): array
{
    $compose = Yaml::parseFile(__DIR__ . '/../../docker-compose.prod.yml');
    return array_keys($compose['services'][$serviceName]['environment']);
}

it('passes every compose app env key through to the app quadlet', function () {
    $compose = composeEnvKeys('app');
    $quadlet = quadletEffectiveEnv('app');
    $missing = array_values(array_diff($compose, $quadlet));
    expect($missing)->toBe(
        [],
        'app.container is missing keys present in compose: ' . implode(', ', $missing)
    );
});

it('passes every compose nightwatch-agent env key through to the nightwatch-agent quadlet', function () {
    $compose = composeEnvKeys('nightwatch-agent');
    $quadlet = quadletEffectiveEnv('nightwatch-agent');
    $missing = array_values(array_diff($compose, $quadlet));
    expect($missing)->toBe(
        [],
        'nightwatch-agent.container is missing keys present in compose: ' . implode(', ', $missing)
    );
});
```

Semantic: every key in compose must appear in the quadlet's effective env (either as `Environment=KEY=...` literal or via `secrets.env.tmpl` flowing through `EnvironmentFile=`). Quadlet may have **extras** — e.g., nightwatch-agent's `EnvironmentFile=/run/trmnl-ruuvi/env` flows all four secrets to it even though compose only sets `NIGHTWATCH_TOKEN`. That's an intentional over-share (one secret file is simpler than per-container splits) and the subset semantic accepts it.

- [ ] **Step 3: Run the test and verify it passes**

```bash
docker compose -f docker-compose.dev.yml exec -T app vendor/bin/pest tests/Unit/QuadletParityTest.php
```

Expected: both tests pass.

- [ ] **Step 4: Verify the test actually catches drift**

Synthetically break parity to confirm the test detects it. Edit `deploy/quadlets/app.container` and delete the `Environment=RUUVI_DISPLAY_TZ=Europe/Helsinki` line. Re-run pest:

```bash
docker compose -f docker-compose.dev.yml exec -T app vendor/bin/pest tests/Unit/QuadletParityTest.php
```

Expected: the app test fails with `app.container is missing keys present in compose: RUUVI_DISPLAY_TZ`.

- [ ] **Step 5: Restore the line**

Re-add `Environment=RUUVI_DISPLAY_TZ=Europe/Helsinki` to `app.container` in the same place it was. Run pest again:

```bash
docker compose -f docker-compose.dev.yml exec -T app vendor/bin/pest tests/Unit/QuadletParityTest.php
```

Expected: green.

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/QuadletParityTest.php
git commit -m "$(cat <<'EOF'
Add Pest test for compose ↔ quadlet env-key parity

Asserts every key in services.<service>.environment in
docker-compose.prod.yml is present in the corresponding .container
quadlet's effective env (Environment= literals plus keys flowing
through EnvironmentFile=/run/trmnl-ruuvi/env via secrets.env.tmpl).

Subset semantic: quadlet may have extras (nightwatch-agent gets all 4
secrets via the shared env file even though compose only sets
NIGHTWATCH_TOKEN — intentional over-share, harmless). Catches the
realistic drift case: someone adds a var to compose and forgets the
quadlet, or vice versa.

Sanity-checked: deleting RUUVI_DISPLAY_TZ from app.container makes the
test fail with the expected diff.

Co-Authored-By: Claude
EOF
)"
```

---

### Task 8: Wire `verify-quadlets` into CI

**Files:**
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Add the new job**

Open `.github/workflows/ci.yml`. After the `decoder-spec-vectors:` job block (line 64-81, ending with the `Run decoder tests only` step), and **before** the `docker-build:` job block (line 83-onward), insert this block:

```yaml
  quadlet-validation:
    name: Podman quadlet structural validation
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Validate quadlet unit files
        run: |
          docker run --rm \
            -v $PWD/deploy/quadlets:/etc/containers/systemd:ro \
            quay.io/podman/stable \
            /usr/libexec/podman/quadlet --dryrun /tmp/out
```

Indentation: two-space, matching the rest of the jobs in this file.

- [ ] **Step 2: Verify the YAML parses by listing jobs**

```bash
docker compose -f docker-compose.dev.yml exec -T app php -r "
\$y = yaml_parse_file('.github/workflows/ci.yml');
print_r(array_keys(\$y['jobs']));
"
```

If `php-yaml` extension isn't installed in the image, use the host's Python instead:
```bash
python3 -c "import yaml,sys; print(list(yaml.safe_load(open('.github/workflows/ci.yml'))['jobs'].keys()))"
```

Expected: includes `'quadlet-validation'` in the list of jobs alongside `test`, `lint`, `decoder-spec-vectors`, `docker-build`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "$(cat <<'EOF'
Wire verify-quadlets into CI

New job runs the podman quadlet generator in --dryrun mode against
deploy/quadlets/ via quay.io/podman/stable. Catches any malformed
.container / .network / .volume unit before it can reach a real host.

Parity between compose and quadlet env keys is already covered by the
QuadletParityTest in the existing Tests job, so no second job for that.

Co-Authored-By: Claude
EOF
)"
```

- [ ] **Step 4: Push and watch the new job**

```bash
git push
```

Then identify and watch the new run:
```bash
sleep 8 && gh run list --branch initial-version --limit 1
```

Grab the run ID from the output and watch:
```bash
gh run watch <RUN_ID> --exit-status
```

Expected: all jobs green (Tests includes the new QuadletParityTest; the new `quadlet-validation` job parses all four quadlet files cleanly).

- [ ] **Step 5: If CI fails, fix and recommit**

Common failure modes and fixes:
- `quay.io/podman/stable: image not found` → registry hiccup, re-run the workflow (`gh run rerun <RUN_ID>`).
- Quadlet generator complains about a unit → run `make verify-quadlets` locally to reproduce; the dev box is the same Docker image so behavior matches.
- Parity test fails → `vendor/bin/pest tests/Unit/QuadletParityTest.php` locally; restore whichever env var drifted.

---

### Task 9: Restructure README's "Server deploy" section

**Files:**
- Modify: `README.md` (lines 36-60)

- [ ] **Step 1: Replace the section**

Open `README.md`. Replace the entire current `## Server deploy` section (everything from `## Server deploy` on line 36 through to the blank line before `## Why not the local-\`.env\` FIFO mount` on line 62) with:

```markdown
## Server deploy

Two parallel deploy paths, both first-class. They share the same secret bootstrap (`/etc/trmnl-ruuvi/bootstrap.env`); pick the orchestrator that fits the target host.

### Option A — Podman quadlets (systemd-managed, recommended for long-lived servers)

Native systemd integration plus a daily auto-update timer that picks up new images published by CI to GHCR. Requires podman 4.4+ (which ships the quadlet generator) and the `op` CLI at `/usr/local/bin/op`.

1. Clone this repo onto the VM (the deploy files ship inside it).

2. On the VM, create `/etc/trmnl-ruuvi/bootstrap.env` (root:root 0600):

    ```
    OP_SERVICE_ACCOUNT_TOKEN=ops_eyJ...
    OP_ENVIRONMENT_ID=einqhwbbevqifrwwxl66hvitpm
    ```

    The quadlet path uses only `OP_SERVICE_ACCOUNT_TOKEN`; `OP_ENVIRONMENT_ID` is retained for Option B (sharing one bootstrap.env across both paths).

3. Copy the unit files and the secret template into place:

    ```sh
    sudo install -d -m 0700 /etc/trmnl-ruuvi
    sudo install -m 0644 deploy/secrets.env.tmpl /etc/trmnl-ruuvi/secrets.env.tmpl
    sudo $EDITOR /etc/trmnl-ruuvi/secrets.env.tmpl      # adjust op:// paths to your 1P layout

    sudo cp deploy/quadlets/*.network deploy/quadlets/*.volume deploy/quadlets/*.container \
            /etc/containers/systemd/
    sudo cp deploy/systemd/op-inject.service /etc/systemd/system/

    sudo systemctl daemon-reload
    sudo systemctl enable --now podman-auto-update.timer
    sudo systemctl start app.service
    ```

4. Smoke test:

    ```sh
    systemctl status op-inject.service app.service nightwatch-agent.service
    curl -fsS http://localhost:8080/up
    ```

To force re-injection of secrets after rotating in 1Password: `sudo systemctl restart op-inject.service && sudo systemctl restart app.service nightwatch-agent.service`. A plain `systemctl restart app.service` does **not** re-run op-inject — `RemainAfterExit=yes` keeps the oneshot active until explicitly restarted.

`AutoUpdate=registry` is set on both containers and `podman-auto-update.timer` runs daily — new `:latest` images published by CI on merge to `main` get pulled and restarted automatically, with rollback if the new container fails to start.

### Option B — podman-compose with `op run`

The original path, kept for environments without systemd or for quick prod-mimicking.

1. Build the image (semver tag, e.g. `trmnl-ruuvi:0.1.0`) and either push to a registry the VM can pull from, or side-load it via `podman save | ssh vm 'podman load'`.

2. On the VM, create `/etc/trmnl-ruuvi/bootstrap.env` (root:root 0600):

    ```
    OP_SERVICE_ACCOUNT_TOKEN=ops_eyJ...
    OP_ENVIRONMENT_ID=einqhwbbevqifrwwxl66hvitpm
    TAG=0.1.0
    ```

3. Invoke compose with the host's `op` resolving secrets:

    ```sh
    set -a; . /etc/trmnl-ruuvi/bootstrap.env; set +a
    op run --environment "$OP_ENVIRONMENT_ID" -- \
        podman-compose -f docker-compose.prod.yml up -d
    ```

   Wrap that in a systemd unit with `EnvironmentFile=/etc/trmnl-ruuvi/bootstrap.env` for restart-on-boot.

Rotating the SA token: edit `bootstrap.env`, rerun. Rotating any app secret: edit the 1P Environment Variables tab, rerun — secrets are fetched fresh on every `compose up`.
```

- [ ] **Step 2: Verify the section renders correctly**

```bash
grep -n "^##" README.md
```

Expected: same heading list as before, except the `## Server deploy` section is now longer (covers both options). Confirm the headings list still has `## Why not the local-\`.env\` FIFO mount` directly after the new section.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
Document both quadlets and podman-compose as first-class prod paths

Restructures "Server deploy" into two parallel options sharing the
same /etc/trmnl-ruuvi/bootstrap.env trust setup. Option A (quadlets)
documents the six install commands, the smoke test, and how to force
secret re-injection after a 1P rotation. Option B (compose) preserves
the existing podman-compose + op run flow verbatim.

Co-Authored-By: Claude
EOF
)"
```

---

## Self-Review Checklist (for the engineer executing the plan)

After all nine tasks are committed, run this end-to-end sanity check before merging:

```bash
# 1. All quadlet files parse
make verify-quadlets

# 2. Parity test green (covers the env-drift case)
docker compose -f docker-compose.dev.yml exec -T app vendor/bin/pest tests/Unit/QuadletParityTest.php

# 3. Full test suite green
make test

# 4. CI green on the latest pushed commit
gh pr view 1 --json statusCheckRollup -q '.statusCheckRollup[] | "\(.conclusion // .status)\t\(.name)"'
```

The PR is ready when all four come back clean.

## Out of Scope

These are deliberate non-goals for this PR. Don't expand into them:

- `make install-quadlets` target (admin runs `cp` lines from README; deferred until we have evidence the manual flow is cumbersome).
- Rootless podman under a dedicated user (rootful was chosen in the spec; revisit only if multi-tenancy emerges).
- Replacing or deprecating `docker-compose.prod.yml`.
- Switching nightwatch-agent's image source from `docker.io/laravelphp/nightwatch-agent:v1` to something more controlled.
- Per-container secret splitting (single `secrets.env.tmpl` is intentional; over-share to nightwatch-agent is harmless).
- Validating `op-inject.service` programmatically (no `systemd-analyze` available in the validator container; relying on first-deploy smoke test from the spec).
