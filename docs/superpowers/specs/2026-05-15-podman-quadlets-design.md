# Podman quadlets for trmnl-ruuvi prod

**Status:** design, approved 2026-05-15
**Branch:** initial-version
**Topic:** add a systemd-managed prod deploy path using podman quadlets, coexisting with the existing `docker-compose.prod.yml`

## Problem

The current prod path is `op run -- podman-compose -f docker-compose.prod.yml up -d`. It works, but on a long-lived server it carries two costs:

1. `podman-compose` is a third-party reimplementation of compose semantics on podman. Behavior diverges from upstream docker-compose in places (volume labeling, healthcheck propagation, restart policy) and updates land on its own cadence.
2. Updates are manual: CI publishes `ghcr.io/iler/trmnl-ruuvitag:latest` on every merge to `main`, but the host's `pull_policy: missing` never re-pulls. An admin has to log in and run `podman pull && podman-compose up -d`.

Podman quadlets ship native systemd integration (`.container` / `.network` / `.volume` / `.pod` files generated into systemd services by `podman-system-generator`) and a `podman-auto-update.timer` that picks up new image digests on a schedule. Adopting quadlets removes the third-party compose layer and gets us closer-to-zero-touch updates without surrendering the auditability of a real systemd unit.

## Goals

- A complete quadlet-based prod deploy that achieves **strict feature parity** with `docker-compose.prod.yml` (same image, env, volume, port, restart, health, depends-on semantics).
- Systemd-managed lifecycle: `journalctl -u app.service`, `systemctl restart app.service`, etc., work as expected.
- Auto-update from GHCR on a daily timer with rollback on failed startup.
- Secrets resolved from 1Password using the existing service-account token bootstrap (`/etc/trmnl-ruuvi/bootstrap.env`) — no plaintext secrets in the repo, no `op://` references reaching the container.
- The compose path keeps working unchanged. README presents both as first-class options.

## Non-goals

- Replacing `docker-compose.prod.yml`. It stays for environments without systemd and as a familiar fallback.
- Multi-host orchestration (k3s, Nomad, etc.).
- Rootless podman under a dedicated user account. We chose rootful for operational simplicity given a single-operator host.
- A custom installer script or configuration management. Install is six lines copy-pasted from README.

## Architecture

Five files ship from the repo; the admin copies them into three system paths on the host.

| Repo path | Installed to | Purpose |
|---|---|---|
| `deploy/quadlets/trmnl-ruuvi.network` | `/etc/containers/systemd/` | Podman network with aardvark-dns |
| `deploy/quadlets/ruuvi-db.volume` | `/etc/containers/systemd/` | Named volume for SQLite database |
| `deploy/quadlets/app.container` | `/etc/containers/systemd/` | Laravel app from GHCR |
| `deploy/quadlets/nightwatch-agent.container` | `/etc/containers/systemd/` | Nightwatch sidecar |
| `deploy/systemd/op-inject.service` | `/etc/systemd/system/` | Oneshot that resolves 1P references to `/run/trmnl-ruuvi/env` |
| `deploy/secrets.env.tmpl` | `/etc/trmnl-ruuvi/secrets.env.tmpl` | Template with `op://...` references |

Host bootstrap (admin task, documented in README):

- `/etc/trmnl-ruuvi/bootstrap.env`, mode 0600, containing `OP_SERVICE_ACCOUNT_TOKEN` and `OP_ENVIRONMENT_ID`. Existing convention from the compose comment. The quadlet path only needs `OP_SERVICE_ACCOUNT_TOKEN` (that is all `op inject` reads to authenticate). `OP_ENVIRONMENT_ID` is preserved for the compose path's `op run --environment` invocation; sharing one bootstrap file across both paths keeps the trust setup unified.
- `op` CLI installed at `/usr/local/bin/op`.

### Generated service graph

```
podman-auto-update.timer  (system-shipped; admin runs `systemctl enable --now`)
  └─ podman-auto-update.service (daily)
       └─ pulls images with AutoUpdate=registry, restarts changed containers,
          rolls back if the new container fails its initial start

multi-user.target
  ├─ op-inject.service           (oneshot, RemainAfterExit=yes)
  ├─ trmnl-ruuvi-network.service (no dep on op-inject)
  └─ ruuvi-db-volume.service     (no dep on op-inject)
       │
       ▼  (each container Requires= op-inject, network, volume;
           generator adds implicit deps on Network=/Volume= references)
       │
       ├─ nightwatch-agent.service
       │
       └─ app.service             (Wants= + After= nightwatch-agent.service)
```

### Secret flow

Three artifacts in series. Goal: quadlets never see `op://` references, only resolved values.

**1. The template** — `/etc/trmnl-ruuvi/secrets.env.tmpl`, checked into the repo at `deploy/secrets.env.tmpl`, mode 0644:

```
APP_KEY=op://Production/trmnl-ruuvi/APP_KEY
RUUVI_API_TOKEN=op://Production/trmnl-ruuvi/RUUVI_API_TOKEN
TRMNL_WEBHOOK_URL=op://Production/trmnl-ruuvi/TRMNL_WEBHOOK_URL
NIGHTWATCH_TOKEN=op://Production/trmnl-ruuvi/NIGHTWATCH_TOKEN
```

The shipped template uses placeholder `op://...` paths. The admin edits these to match their actual 1Password vault/item/field layout at install time.

**2. The oneshot** — `/etc/systemd/system/op-inject.service`:

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

Key properties:

- `RuntimeDirectory=trmnl-ruuvi` causes systemd to create `/run/trmnl-ruuvi/` (tmpfs, mode 0700, owner root) and remove it on stop. Secrets never touch disk.
- `UMask=0077` ensures `/run/trmnl-ruuvi/env` is mode 0600.
- `RemainAfterExit=yes` keeps the service in `active` state so the containers' `Requires=op-inject.service` does not trip on a "dead" dependency immediately after the oneshot finishes.
- `EnvironmentFile=/etc/trmnl-ruuvi/bootstrap.env` is how `op` learns `OP_SERVICE_ACCOUNT_TOKEN` to authenticate non-interactively.

**3. The quadlet consumers** — both `.container` files include:

```ini
[Unit]
Requires=op-inject.service
After=op-inject.service

[Container]
EnvironmentFile=/run/trmnl-ruuvi/env
```

**Failure model:**

- If `op inject` fails (bad token, missing 1P item, network gone), `op-inject.service` exits non-zero, and systemd refuses to start the containers (`Requires=` propagates failure). The app stays down rather than starting with empty secrets and serving 500s. This is the correct posture.
- On reboot, `op-inject.service` runs first, gets a fresh resolution, so rotated 1P secrets land automatically.
- `systemctl restart app.service` does **not** re-run `op-inject` (the service is `RemainAfterExit=yes` and already considered active). To force re-injection: `systemctl restart op-inject.service && systemctl restart app.service nightwatch-agent.service`. This is documented in README.

### Quadlet file contents

**`deploy/quadlets/trmnl-ruuvi.network`:**

```ini
[Network]
NetworkName=trmnl-ruuvi
```

A named network gives us aardvark-dns automatically: containers resolve each other by `ContainerName=`. Generates `trmnl-ruuvi-network.service`.

**`deploy/quadlets/ruuvi-db.volume`:**

```ini
[Volume]
VolumeName=ruuvi-db
```

Named volume, parity with compose's `volumes: ruuvi-db:`.

**`deploy/quadlets/nightwatch-agent.container`:**

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

**`deploy/quadlets/app.container`:**

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

Notes:

- The split between `Environment=` (non-secret literals, same as compose) and `EnvironmentFile=/run/trmnl-ruuvi/env` (the four secrets) keeps the secret surface small and reviewable.
- `Volume=ruuvi-db.volume:/var/www/html/database:Z` — the `:Z` flag is a no-op on non-SELinux hosts and required for correct relabeling on SELinux ones. Safe to include unconditionally.
- `Restart=always` is the closest systemd analog to compose's `restart: unless-stopped` (both restart on unexpected exit but not after an explicit `systemctl stop`).
- `ContainerName=` is set on both containers so DNS resolution hits stable names regardless of how the quadlet generator mangles unit names.

## Compose parity

| Compose construct | Quadlet equivalent | Note |
|---|---|---|
| `services.app.image` | `app.container` `Image=` | identical string + tag |
| `services.app.ports: 8080:8080` | `PublishPort=8080:8080` | |
| `services.app.environment.*` (literals) | `app.container` `Environment=*` | one-to-one, 11 vars |
| `services.app.environment.*` (secrets) | `EnvironmentFile=/run/trmnl-ruuvi/env` via op-inject | 4 vars: `APP_KEY`, `RUUVI_API_TOKEN`, `TRMNL_WEBHOOK_URL`, `NIGHTWATCH_TOKEN` |
| `services.app.volumes: ruuvi-db:...` | `Volume=ruuvi-db.volume:/var/www/html/database:Z` | named volume managed by `.volume` quadlet |
| `services.app.depends_on: nightwatch-agent` | `Wants=` + `After=nightwatch-agent.service` | start-order only (matches compose) |
| `services.app.restart: unless-stopped` | `[Service] Restart=always` | closest systemd equivalent |
| `services.nightwatch-agent.healthcheck` | `HealthCmd=` + `HealthInterval=` + `HealthTimeout=` + `HealthRetries=` + `HealthStartPeriod=` | one-to-one |
| `pull_policy: missing` | `AutoUpdate=registry` + `podman-auto-update.timer` | **new behavior**, intentional |
| default compose network (DNS by service name) | `trmnl-ruuvi.network` + `ContainerName=` | DNS by name preserved |

The `AutoUpdate=registry` row is the one place quadlets intentionally diverge from compose. The README calls this out so the operator is not surprised when a deploy ships without a manual pull.

## Installation

Documented in README under `## Server deploy` → `### Option A — Podman quadlets (systemd-managed)`. The six commands the admin runs:

```bash
# Bootstrap directory + template
sudo install -d -m 0700 /etc/trmnl-ruuvi
sudo install -m 0644 deploy/secrets.env.tmpl /etc/trmnl-ruuvi/secrets.env.tmpl
sudo $EDITOR /etc/trmnl-ruuvi/secrets.env.tmpl       # adjust op:// paths to local 1P layout
sudo $EDITOR /etc/trmnl-ruuvi/bootstrap.env          # OP_SERVICE_ACCOUNT_TOKEN, OP_ENVIRONMENT_ID
sudo chmod 0600 /etc/trmnl-ruuvi/bootstrap.env

# Install unit files
sudo cp deploy/quadlets/*.network deploy/quadlets/*.volume deploy/quadlets/*.container \
        /etc/containers/systemd/
sudo cp deploy/systemd/op-inject.service /etc/systemd/system/

# Activate
sudo systemctl daemon-reload
sudo systemctl enable --now podman-auto-update.timer
sudo systemctl start app.service                     # pulls everything in the dependency tree
```

No installer script for v1. Six lines pasted into a server shell is a feature: the admin sees what is happening. A future `make install-quadlets` target is acceptable but not Day 1.

## Testing strategy

### What we verify in CI and locally

**Structural validation** — a new `make verify-quadlets` Makefile target spins up a throwaway `quay.io/podman/stable` container, mounts the `deploy/` tree, and runs:

```
/usr/lib/systemd/system-generators/podman-system-generator --dryrun --user
```

The target asserts each `.container` / `.network` / `.volume` produces a parseable `.service` without errors or warnings. Fast (~5s), no secrets required, no live podman daemon needed. This becomes a new CI job alongside the existing test/lint/decoder-spec jobs.

**Parity drift guard** — a small shell or PHP script in `tests/` greps every `Environment=` and `EnvironmentFile` referent out of the quadlets and asserts the set equals `services.app.environment` keys in `docker-compose.prod.yml`. Catches the case where someone edits one path and forgets the other. Runs in CI.

### What we cannot verify without a real host

- `op inject` actually resolving against a live 1P Environment (needs a real service-account token).
- `podman-auto-update.timer` actually pulling and restarting cleanly under load.
- SELinux relabeling working in practice (`:Z` only matters when SELinux is enforcing).
- Reboot ordering working under real-world `multi-user.target` activation.

These are documented as the *First-deploy checklist* below.

## First-deploy checklist

After the install commands above, the admin runs:

```bash
# 1. Confirm the secret-injection oneshot succeeded
systemctl status op-inject.service
sudo ls -la /run/trmnl-ruuvi/env                     # expect mode 0600, non-zero size

# 2. Confirm both containers came up
systemctl status app.service nightwatch-agent.service
podman ps                                            # expect two running containers on trmnl-ruuvi network

# 3. Confirm the app serves traffic
curl -fsS http://localhost:8080/up                   # Laravel health endpoint

# 4. Confirm the named volume persists data
podman volume ls | grep ruuvi-db
podman exec app ls -la /var/www/html/database/database.sqlite

# 5. Confirm auto-update timer is armed
systemctl list-timers podman-auto-update.timer
podman auto-update --dry-run                         # lists what *would* update on next run
```

## Open questions / known risks

- **Auto-update of `nightwatch-agent`:** The agent is pinned to `:v1` (a major-version floating tag). `AutoUpdate=registry` will pull new `v1.x.y` digests as they ship. This is the intended behavior (we want patch updates) but worth noting because compose did not auto-update at all.
- **`/run/trmnl-ruuvi/env` lifetime:** A `systemctl stop op-inject.service` removes `/run/trmnl-ruuvi/` (via `RuntimeDirectory=`). Containers already running with `EnvironmentFile=` reference values that have been read into memory at start time, so they keep running until restarted. Admins should know that restarting `op-inject` alone does not push new secret values into running containers — they must restart the consuming services too. README documents this.
- **`op` binary location:** Hardcoded to `/usr/local/bin/op` in the oneshot. Different distros may install it elsewhere. If we hit this, switch to `ExecStart=/bin/sh -lc 'op inject ...'` so PATH applies. Defer until we have a host that proves it matters.
- **Quadlet `:Z` flag on non-SELinux hosts:** Documented in podman as safe (no-op), but worth a smoke test on Debian/Ubuntu where SELinux is not the default.

## Rollout

1. Implement the six files plus `make verify-quadlets` and the parity-drift test.
2. Land via a PR off `initial-version` (or whichever branch is current after PR #1 merges).
3. Smoke-test on a single host using the *First-deploy checklist*.
4. Update README's `## Server deploy` section with the two parallel paths.
5. No deprecation of the compose path; both remain first-class.
