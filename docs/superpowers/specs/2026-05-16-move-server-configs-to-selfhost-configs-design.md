# Move server configs to iler/selfhost-configs

**Status:** design, approved 2026-05-16
**Source repo:** `iler/trmnl-ruuvitag` (branch `move-quadlets-to-selfhost-configs`, opened after PR #1 lands)
**Target repo:** `iler/selfhost-configs` (branch `add-trmnl-ruuvi-quadlets`)
**Import source SHA:** `f0c117f` on `iler/trmnl-ruuvitag@initial-version`

## Problem

PR #1 on `iler/trmnl-ruuvitag` will land a complete podman-quadlets prod deploy under `deploy/` plus an `op-inject.service` oneshot, secret template, validator Makefile target, CI job, and Pest parity test. All of it is **server-operator configuration** — which 1Password Environment to read, which host paths to install to, which systemd unit names to use. None of it is the app.

When the trmnl-ruuvitag repo is eventually made public, that operator config makes the repo look bigger and more opinionated than it is — and ties the published app source to a single operator's deploy choices. A reader who just wants the Laravel app shouldn't have to skim past two repos' worth of systemd unit conventions to find it.

Splitting the server configs into a separate repo (`iler/selfhost-configs`) restores the right boundary: trmnl-ruuvitag publishes an app + an image build + a `docker-compose.prod.yml` that anyone can adapt; selfhost-configs documents how *one specific operator* deploys it on their box.

## Goals

- Move all podman-quadlet/systemd artifacts out of trmnl-ruuvitag into a per-project subdirectory of `iler/selfhost-configs`.
- Preserve the validator quality bar: same `quay.io/podman/stable` dry-run, in both a `Makefile` target and a CI job, just in the new repo.
- Trmnl-ruuvitag's `## Server deploy` still works as documentation — Option A becomes a one-paragraph pointer to selfhost-configs; Option B (compose) is unchanged.
- Selfhost-configs is laid out for future projects (per-project subdirectories) without a breaking move when a second app joins.

## Non-goals

- Preserving git history of the 13 quadlets-stack commits inside selfhost-configs (`git filter-repo` was considered and rejected — plain copy + single-import commit is simpler; trmnl-ruuvitag's history retains the original commits forever).
- Cross-repo parity testing. The Pest `QuadletParityTest` is deleted — after the split the two repos are independent products with independent truth.
- Auto-syncing future env-var additions between compose (trmnl-ruuvitag) and quadlets (selfhost-configs). When trmnl-ruuvitag adds a new env var to compose, the operator updates their selfhost-configs deploy. Operator notices, manual sync — same rigor as today, just one more step.
- An `install.sh` helper script for selfhost-configs. Six commands copy-pasted from the README stay the default (matches the deferral already baked into the original quadlets spec).
- Multi-project tooling abstraction (per-target `verify-trmnl-ruuvi`, etc.). Single `verify-quadlets` target points at the one project for now; promote to a pattern only when a second project actually lands.

## Architecture

### Inventory: what moves, stays, dies

**Moves to `iler/selfhost-configs` (single import commit, byte-identical):**

| Source path (trmnl-ruuvitag@f0c117f) | Destination (selfhost-configs) |
|---|---|
| `deploy/quadlets/trmnl-ruuvi.network` | `trmnl-ruuvi/quadlets/trmnl-ruuvi.network` |
| `deploy/quadlets/ruuvi-db.volume` | `trmnl-ruuvi/quadlets/ruuvi-db.volume` |
| `deploy/quadlets/app.container` | `trmnl-ruuvi/quadlets/app.container` |
| `deploy/quadlets/nightwatch-agent.container` | `trmnl-ruuvi/quadlets/nightwatch-agent.container` |
| `deploy/systemd/op-inject.service` | `trmnl-ruuvi/systemd/op-inject.service` |
| `deploy/secrets.env.tmpl` | `trmnl-ruuvi/secrets.env.tmpl` |

**Net-new in selfhost-configs:**

- Root `README.md` — index pointing at each project subdir
- `trmnl-ruuvi/README.md` — install + smoke-test guide (lifted from trmnl-ruuvitag's Option A, paths adjusted)
- `Makefile` — single `verify-quadlets` target adapted for `trmnl-ruuvi/quadlets/`
- `.github/workflows/ci.yml` — one job (`quadlet-validation`) running the validator directly via `docker run` (no `make` required on the CI runner)

**Deleted from trmnl-ruuvitag (in the follow-up PR):**

- `deploy/` and everything beneath it (6 files)
- `tests/Unit/QuadletParityTest.php`
- `symfony/yaml` from `composer.json` `require-dev` + `composer.lock` regeneration
- `verify-quadlets` target + section comment + `.PHONY` entry in `Makefile`
- `quadlet-validation:` job from `.github/workflows/ci.yml`
- `docs/superpowers/specs/2026-05-15-podman-quadlets-design.md`
- `docs/superpowers/plans/2026-05-15-podman-quadlets.md`

**Edited in trmnl-ruuvitag:** `README.md` — Option A under `## Server deploy` becomes a one-paragraph pointer to `iler/selfhost-configs`. Option B (compose) is verbatim unchanged.

**Stays in trmnl-ruuvitag, unchanged:** `docker-compose.{yml,prod.yml,dev.yml}`, `Dockerfile`, everything under `app/`, `resources/`, `routes/`, `tests/` (minus the deleted parity test), `config/`, `database/`, the rest of CI.

### Final selfhost-configs tree (after the import PR lands)

```
selfhost-configs/
├── .github/
│   └── workflows/
│       └── ci.yml                          # net-new
├── LICENSE                                  # already in initial commit
├── Makefile                                 # net-new
├── README.md                                # net-new (index)
└── trmnl-ruuvi/
    ├── README.md                            # net-new (install guide)
    ├── secrets.env.tmpl                     # imported
    ├── quadlets/
    │   ├── trmnl-ruuvi.network              # imported
    │   ├── ruuvi-db.volume                  # imported
    │   ├── nightwatch-agent.container       # imported
    │   └── app.container                    # imported
    └── systemd/
        └── op-inject.service                # imported
```

### Selfhost-configs net-new file contents

**`Makefile`:**

```makefile
.PHONY: verify-quadlets

# Validate quadlet unit files by running podman's quadlet generator in
# --dryrun mode. Exits non-zero on any parse error. Used by CI and by
# admins to sanity-check edits before copying files to a real host.
verify-quadlets:
	@echo "==> Validating trmnl-ruuvi quadlet unit files..."
	@docker run --rm \
		-v $(PWD)/trmnl-ruuvi/quadlets:/etc/containers/systemd:ro \
		quay.io/podman/stable \
		/usr/libexec/podman/quadlet --dryrun /tmp/out >/dev/null
	@echo "    all quadlets parse cleanly"
```

**`.github/workflows/ci.yml`:**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  quadlet-validation:
    name: Podman quadlet structural validation
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Validate trmnl-ruuvi quadlets
        run: |
          docker run --rm \
            -v $PWD/trmnl-ruuvi/quadlets:/etc/containers/systemd:ro \
            quay.io/podman/stable \
            /usr/libexec/podman/quadlet --dryrun /tmp/out
```

**Root `README.md`:**

```markdown
# selfhost-configs

Podman quadlets and systemd units for self-hosted services I run.

| Project | Path | Upstream |
| --- | --- | --- |
| trmnl-ruuvi | [`trmnl-ruuvi/`](./trmnl-ruuvi/) | [iler/trmnl-ruuvitag](https://github.com/iler/trmnl-ruuvitag) |

Each subdirectory is independent — its own README, its own quadlets. `make verify-quadlets` from the repo root validates the current project's units.
```

**`trmnl-ruuvi/README.md`:**

```markdown
# trmnl-ruuvi quadlets

Systemd-managed podman quadlets for [iler/trmnl-ruuvitag](https://github.com/iler/trmnl-ruuvitag). Native systemd integration plus a daily auto-update timer that picks up new images published by the app's CI to GHCR.

Requires podman 4.4+ (which ships the quadlet generator) and the `op` CLI at `/usr/local/bin/op`.

## Install

1. Clone this repo onto the VM.

2. On the VM, create the bootstrap directory and the env file (root:root 0600):

    ```sh
    sudo install -d -m 0700 /etc/trmnl-ruuvi
    sudo touch /etc/trmnl-ruuvi/bootstrap.env
    sudo chmod 0600 /etc/trmnl-ruuvi/bootstrap.env
    sudo $EDITOR /etc/trmnl-ruuvi/bootstrap.env
    ```

    Contents:
    ```
    OP_SERVICE_ACCOUNT_TOKEN=ops_eyJ...
    OP_ENVIRONMENT_ID=<your-1p-environment-id>
    ```

    The quadlet path uses only `OP_SERVICE_ACCOUNT_TOKEN`; `OP_ENVIRONMENT_ID` is retained for parity with the trmnl-ruuvitag compose deploy path.

3. Copy the unit files and the secret template into place:

    ```sh
    sudo install -m 0644 trmnl-ruuvi/secrets.env.tmpl /etc/trmnl-ruuvi/secrets.env.tmpl
    sudo $EDITOR /etc/trmnl-ruuvi/secrets.env.tmpl       # adjust op:// paths to your 1P layout

    sudo cp trmnl-ruuvi/quadlets/*.network trmnl-ruuvi/quadlets/*.volume trmnl-ruuvi/quadlets/*.container \
            /etc/containers/systemd/
    sudo cp trmnl-ruuvi/systemd/op-inject.service /etc/systemd/system/

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

`AutoUpdate=registry` is set on both containers and `podman-auto-update.timer` runs daily — new `:latest` images published to GHCR get pulled and restarted automatically, with rollback if the new container fails to start.

**Operational controls.** To pause auto-updates (e.g., to debug a regression without `:latest` moving under you): `sudo systemctl disable --now podman-auto-update.timer`. To pin to a specific image, edit the `Image=` line in `/etc/containers/systemd/app.container` to `ghcr.io/iler/trmnl-ruuvitag:<sha>`, then `sudo systemctl daemon-reload && sudo systemctl restart app.service`. Re-enable auto-updates with `sudo systemctl enable --now podman-auto-update.timer`.

## Validating edits

From the repo root: `make verify-quadlets` runs the podman quadlet generator in dry-run mode against `trmnl-ruuvi/quadlets/` and exits non-zero on any parse error.
```

### Trmnl-ruuvitag follow-up PR commit structure

Six commits on branch `move-quadlets-to-selfhost-configs`, in order:

1. `Remove deploy/ and op-inject artifacts (moved to iler/selfhost-configs)` — deletes the 6 imported files and the now-empty `deploy/` directory.
2. `Drop QuadletParityTest and its symfony/yaml dep` — deletes the test file and removes `symfony/yaml` from `composer.json` `require-dev`; regenerates `composer.lock`.
3. `Drop verify-quadlets Makefile target` — removes the recipe, the section comment, and the `.PHONY` entry.
4. `Drop quadlet-validation CI job` — removes the job from `.github/workflows/ci.yml` (leaving `test`, `lint`, `decoder-spec-vectors`, `docker-build`).
5. `Remove superseded quadlets spec and plan docs` — deletes `docs/superpowers/specs/2026-05-15-podman-quadlets-design.md` and `docs/superpowers/plans/2026-05-15-podman-quadlets.md`.
6. `Point quadlets deploy at iler/selfhost-configs` — replaces Option A under `## Server deploy` in `README.md` with this paragraph:

   ```markdown
   ### Option A — Podman quadlets (systemd-managed, recommended for long-lived servers)

   Quadlet unit files, the `op-inject.service` oneshot, and the install guide live in a separate repo: **[iler/selfhost-configs/trmnl-ruuvi/](https://github.com/iler/selfhost-configs/tree/main/trmnl-ruuvi)**. Native systemd integration plus a daily auto-update timer that picks up new images published by CI to GHCR. Recommended for long-lived servers.

   Why a separate repo: server configs (which 1Password environment ID, install paths, host-side systemd choices) belong with the operator, not with the app source.
   ```

   Option B (compose) is untouched.

## Execution order

Two PRs, sequenced:

**Step 1 — populate selfhost-configs (starts now, independent of trmnl-ruuvitag PR #1 state).**

In `/Users/ilari/.superset/projects/selfhost-configs`:

- Branch `add-trmnl-ruuvi-quadlets` off `main`
- Commit 1: `Import trmnl-ruuvi quadlets from iler/trmnl-ruuvitag@f0c117f` — the 6 imported files, byte-identical
- Commit 2: `Add Makefile, CI workflow, and READMEs` — toolchain + root README + trmnl-ruuvi install guide
- Push, open PR against `main`, wait for the `quadlet-validation` CI job to go green, merge

**Step 2 — operator (the user) merges PR #1 on `iler/trmnl-ruuvitag`.** Out of scope for this work; happens when the operator is ready.

**Step 3 — trmnl-ruuvitag follow-up PR (only after Step 2 so the branch starts from a clean `main` with the quadlets in it).**

From the existing trmnl-ruuvitag worktree:

- Pull `main`
- Branch `move-quadlets-to-selfhost-configs`
- Apply the 6 commits from "Trmnl-ruuvitag follow-up PR commit structure" above
- Run `make test` (Pest, minus the deleted parity test); sanity-grep `deploy/` references (should return nothing under tracked files)
- Push, open PR, wait for the remaining 4 CI checks (`test`, `lint`, `decoder-spec-vectors`, `docker-build` — last one will be SKIPPED on PR) to be green, merge

**Cross-repo handoff timing.** Step 1's PR ships the pointer target before Step 3's README references it. The published trmnl-ruuvitag repo is never in a "broken pointer" intermediate state.

## Open questions / known risks

- **PR #1 still in-flight.** Step 3 cannot start until the operator merges PR #1. The selfhost-configs repo can be fully populated and even merged in advance — it doesn't depend on PR #1 — but the README pointer on trmnl-ruuvitag's side waits.
- **No structural drift detection between compose and quadlets.** Deliberately accepted (see Non-goals). If trmnl-ruuvitag's compose grows a new env var without a matching selfhost-configs update, the operator's next `systemctl restart app.service` is the failure surface. Acceptable given the manual-sync posture.
- **`symfony/yaml` removal might leave transitive holes.** It was a `require-dev` addition for the parity test; nothing else depends on it. The trmnl-ruuvitag follow-up PR should regenerate `composer.lock` and run `make test` to confirm. If Laravel's dev tooling picks it up transitively, the dep stays in the lock anyway and the removal is a no-op there.
- **Future second project in selfhost-configs.** When a second app joins, the Makefile's single target becomes per-project. Choose between (a) per-project targets (`verify-trmnl-ruuvi`, `verify-nextcloud`), (b) a pattern target with project as variable (`make verify PROJECT=trmnl-ruuvi`), or (c) per-project subdirectory Makefiles. Decision deferred until the second project actually arrives.
- **Spec doc fate post-execution.** This design doc lives at `docs/superpowers/specs/2026-05-16-...` in trmnl-ruuvitag. Step 5 of the follow-up commits deletes *the older* spec/plan docs but leaves this one. The operator can decide whether to delete this one too in a tail-end commit; the design rationale is now in git history regardless.

## Rollout

1. Implement Step 1 (selfhost-configs) per the writing-plans hand-off.
2. Smoke-test by reading the new READMEs end-to-end as if installing on a fresh VM.
3. Park while operator merges trmnl-ruuvitag PR #1.
4. Implement Step 3 (trmnl-ruuvitag follow-up).
5. After both PRs are merged, optionally clean up this design doc in a follow-up commit.
