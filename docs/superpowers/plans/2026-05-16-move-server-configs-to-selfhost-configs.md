# Move Server Configs to selfhost-configs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the podman-quadlets stack out of `iler/trmnl-ruuvitag` into a new repo `iler/selfhost-configs`, leaving the app + docker-compose paths behind in trmnl-ruuvitag with a pointer to the new home.

**Architecture:** Two sequenced PRs. PR A populates an empty `selfhost-configs` repo with a `trmnl-ruuvi/` subdirectory holding the 6 imported quadlet/systemd/secret-template files plus a fresh Makefile + CI workflow + READMEs. PR B (gated on `iler/trmnl-ruuvitag` PR #1 being merged into `main` first) deletes the now-mirrored files from trmnl-ruuvitag, drops the parity test + symfony/yaml dep + validator Makefile target + CI job + old spec/plan docs, and rewrites `README.md`'s Option A as a one-paragraph pointer to selfhost-configs.

**Tech Stack:** Plain `cp` for the file move (no `git filter-repo`), Makefile + GitHub Actions for the new validator, podman quadlets running inside `quay.io/podman/stable` as the validator harness, `gh` for PR ops.

**Spec:** `docs/superpowers/specs/2026-05-16-move-server-configs-to-selfhost-configs-design.md`

**Import source SHA in trmnl-ruuvitag:** `f0c117f` (the spec was committed at `1cf2cd4`; the import contents are unchanged since `f0c117f`).

**Two worktrees in play:**
- `/Users/ilari/.superset/worktrees/trmnl-ruuvitag/initial-version` — source repo (Phase B)
- `/Users/ilari/.superset/projects/selfhost-configs` — target repo (Phase A)

**1Password signing:** every `git commit` is signed via `op-ssh-sign`. If commit fails with `1Password: failed to fill whole buffer` or `1Password: agent returned an error`, STOP and report BLOCKED — the user must unlock 1Password. Never bypass with `--no-gpg-sign`.

---

# Phase A — Populate `iler/selfhost-configs`

Starts now. Does not depend on trmnl-ruuvitag PR #1 state.

### Task A1: Branch + import the 6 files (single commit)

**Working directory:** `/Users/ilari/.superset/projects/selfhost-configs`

**Files to create (by copy):**
- `trmnl-ruuvi/quadlets/trmnl-ruuvi.network`
- `trmnl-ruuvi/quadlets/ruuvi-db.volume`
- `trmnl-ruuvi/quadlets/nightwatch-agent.container`
- `trmnl-ruuvi/quadlets/app.container`
- `trmnl-ruuvi/systemd/op-inject.service`
- `trmnl-ruuvi/secrets.env.tmpl`

- [ ] **Step 1: Verify clean starting state**

```bash
cd /Users/ilari/.superset/projects/selfhost-configs
git status
git branch --show-current
git log --oneline -3
```

Expected: clean working tree, currently on `main`, one commit (`f78f59a Initial commit` with just LICENSE). If branch != `main` or working tree dirty, STOP and report BLOCKED.

- [ ] **Step 2: Create the import branch**

```bash
git checkout -b add-trmnl-ruuvi-quadlets
```

- [ ] **Step 3: Create the destination directories**

```bash
mkdir -p trmnl-ruuvi/quadlets trmnl-ruuvi/systemd
```

- [ ] **Step 4: Copy the 6 files byte-for-byte**

```bash
SRC=/Users/ilari/.superset/worktrees/trmnl-ruuvitag/initial-version/deploy
cp "$SRC/quadlets/trmnl-ruuvi.network"        trmnl-ruuvi/quadlets/
cp "$SRC/quadlets/ruuvi-db.volume"            trmnl-ruuvi/quadlets/
cp "$SRC/quadlets/nightwatch-agent.container" trmnl-ruuvi/quadlets/
cp "$SRC/quadlets/app.container"              trmnl-ruuvi/quadlets/
cp "$SRC/systemd/op-inject.service"           trmnl-ruuvi/systemd/
cp "$SRC/secrets.env.tmpl"                    trmnl-ruuvi/
```

- [ ] **Step 5: Verify byte-for-byte identical**

```bash
SRC=/Users/ilari/.superset/worktrees/trmnl-ruuvitag/initial-version/deploy
diff -r trmnl-ruuvi/quadlets/ "$SRC/quadlets/" && echo "quadlets: identical"
diff "$SRC/systemd/op-inject.service" trmnl-ruuvi/systemd/op-inject.service && echo "systemd: identical"
diff "$SRC/secrets.env.tmpl"          trmnl-ruuvi/secrets.env.tmpl          && echo "secrets template: identical"
```

Expected: three "identical" lines, no diff output. If anything differs, STOP and report BLOCKED.

- [ ] **Step 6: Stage + commit**

```bash
git add trmnl-ruuvi/
git status
```

Confirm staging shows exactly 6 new files under `trmnl-ruuvi/`. Then:

```bash
git commit -m "$(cat <<'EOF'
Import trmnl-ruuvi quadlets from iler/trmnl-ruuvitag@f0c117f

Brings in the podman-quadlets stack that previously lived under
deploy/ in the app repo:

* trmnl-ruuvi/quadlets/{trmnl-ruuvi.network, ruuvi-db.volume,
  nightwatch-agent.container, app.container}
* trmnl-ruuvi/systemd/op-inject.service
* trmnl-ruuvi/secrets.env.tmpl

Files are byte-identical to the trmnl-ruuvitag originals; full design
rationale lives in the trmnl-ruuvitag commit history through f0c117f.
A follow-up PR on iler/trmnl-ruuvitag will remove deploy/ from there
and add a pointer to this location.

Co-Authored-By: Claude
EOF
)"
```

---

### Task A2: Add the Makefile, CI workflow, and READMEs (single commit)

**Files to create:**
- `Makefile`
- `.github/workflows/ci.yml`
- `README.md` (root)
- `trmnl-ruuvi/README.md`

- [ ] **Step 1: Create the Makefile**

```bash
mkdir -p .github/workflows
```

Write `Makefile`:

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

Recipe indentation MUST be a tab, not spaces (Makefile requirement).

- [ ] **Step 2: Run the validator against the imported quadlets**

```bash
make verify-quadlets
```

Expected:
```
==> Validating trmnl-ruuvi quadlet unit files...
    all quadlets parse cleanly
```

Exit 0. The quadlet generator may print informational `Loading source unit file ...` lines on stderr — that's fine, they don't cause non-zero exit. If exit is non-zero, STOP and report BLOCKED — the imported files don't parse.

- [ ] **Step 3: Create the CI workflow**

Write `.github/workflows/ci.yml`:

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

- [ ] **Step 4: Verify the YAML parses**

```bash
python3 -c "import yaml,sys; print(list(yaml.safe_load(open('.github/workflows/ci.yml'))['jobs'].keys()))"
```

Expected: `['quadlet-validation']`.

- [ ] **Step 5: Create the root README**

Write `README.md`:

```markdown
# selfhost-configs

Podman quadlets and systemd units for self-hosted services I run.

| Project | Path | Upstream |
| --- | --- | --- |
| trmnl-ruuvi | [`trmnl-ruuvi/`](./trmnl-ruuvi/) | [iler/trmnl-ruuvitag](https://github.com/iler/trmnl-ruuvitag) |

Each subdirectory is independent — its own README, its own quadlets. `make verify-quadlets` from the repo root validates the current project's units.
```

- [ ] **Step 6: Create the trmnl-ruuvi install guide README**

Write `trmnl-ruuvi/README.md`:

````markdown
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
````

- [ ] **Step 7: Stage + commit all four net-new files**

```bash
git add Makefile .github/workflows/ci.yml README.md trmnl-ruuvi/README.md
git status
```

Confirm staging shows exactly 4 new files. Then:

```bash
git commit -m "$(cat <<'EOF'
Add Makefile, CI workflow, and READMEs

* Makefile: single verify-quadlets target that runs the podman quadlet
  generator inside quay.io/podman/stable against trmnl-ruuvi/quadlets/.
* .github/workflows/ci.yml: one job (quadlet-validation) running the
  same validator directly via docker run (no make required on the
  runner).
* README.md: short index pointing at each project subdirectory.
* trmnl-ruuvi/README.md: install + smoke-test guide ported from the
  trmnl-ruuvitag README's Option A, with paths adjusted to live in
  this repo.

Verified locally: make verify-quadlets exits 0 against the 4 imported
.container/.network/.volume files.

Co-Authored-By: Claude
EOF
)"
```

---

### Task A3: Push, open PR, watch CI, merge

- [ ] **Step 1: Push the branch**

```bash
git push -u origin add-trmnl-ruuvi-quadlets
```

- [ ] **Step 2: Open the PR**

```bash
gh pr create --title "Add trmnl-ruuvi quadlets + validator toolchain" --body "$(cat <<'EOF'
## Summary
- Imports the podman-quadlets stack from `iler/trmnl-ruuvitag@f0c117f` under `trmnl-ruuvi/`.
- Adds a `Makefile` target `verify-quadlets` and a one-job CI workflow that runs the podman quadlet generator in --dryrun mode inside `quay.io/podman/stable`.
- Adds a root README index and a `trmnl-ruuvi/README.md` install + smoke-test guide.
- Replaces nothing; the existing `LICENSE` is the only prior content.

## Test plan
- [x] `make verify-quadlets` exits 0 locally
- [ ] CI's `quadlet-validation` job goes green on this PR

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Wait for CI**

```bash
sleep 8
gh run list --branch add-trmnl-ruuvi-quadlets --limit 1
```

Note the run ID. Then:

```bash
gh run watch <RUN_ID> --exit-status
```

Expected: `quadlet-validation` SUCCESS, no other jobs.

- [ ] **Step 4: Confirm the PR rollup**

```bash
gh pr view --json statusCheckRollup -q '.statusCheckRollup[] | "\(.conclusion // .status)\t\(.name)"'
```

Expected: `SUCCESS  Podman quadlet structural validation`.

- [ ] **Step 5: Merge the PR**

```bash
gh pr merge --squash --delete-branch
```

(Squash is fine here — only two commits, both clean. If user has a strong preference for merge-commit, use `--merge` instead.)

- [ ] **Step 6: Verify main is updated**

```bash
git checkout main
git pull
git log --oneline -3
```

Expected: a new commit on `main` whose message starts with `Add trmnl-ruuvi quadlets + validator toolchain (#1)` (or whatever PR number is assigned). The selfhost-configs repo is now live with the quadlets.

---

# ⛔ GATE: Wait for trmnl-ruuvitag PR #1 to be merged into `main`

Phase B cannot start until the operator merges trmnl-ruuvitag PR #1 (the original quadlets-stack PR). Confirm by running:

```bash
cd /Users/ilari/.superset/worktrees/trmnl-ruuvitag/initial-version
git fetch origin
gh pr view 1 --json state,mergedAt -q '"\(.state) at \(.mergedAt // "(not merged)")"'
```

If output is not `MERGED at <timestamp>`, STOP and tell the operator: "Phase B is blocked until PR #1 is merged. Please review and merge https://github.com/iler/trmnl-ruuvitag/pull/1, then re-invoke this plan starting at Task B1."

---

# Phase B — trmnl-ruuvitag follow-up PR

**Working directory:** `/Users/ilari/.superset/worktrees/trmnl-ruuvitag/initial-version`

This phase contains 6 small commits on a single branch, then a push + watch + merge step.

### Task B1: Branch off the post-PR-#1 main

- [ ] **Step 1: Pull the post-merge main**

```bash
cd /Users/ilari/.superset/worktrees/trmnl-ruuvitag/initial-version
git fetch origin
git checkout main
git pull
git log --oneline -3
```

Expected: HEAD includes the squashed/merged quadlets-stack commit from PR #1. If `git checkout main` fails because the worktree is on `initial-version`, that's expected — switch via `git checkout main`.

If the worktree refuses to switch (uncommitted changes, etc.), STOP and report BLOCKED.

- [ ] **Step 2: Confirm the quadlets stack is on main**

```bash
ls deploy/quadlets/
test -f tests/Unit/QuadletParityTest.php && echo "parity test present"
grep -q "verify-quadlets" Makefile && echo "verify-quadlets target present"
grep -q "quadlet-validation" .github/workflows/ci.yml && echo "CI job present"
```

Expected: 4 quadlet files listed, three "present" lines. If any check fails, the merge did not include the quadlets stack — STOP and report BLOCKED.

- [ ] **Step 3: Create the cleanup branch**

```bash
git checkout -b move-quadlets-to-selfhost-configs
```

---

### Task B2: Remove `deploy/` (commit 1 of 6)

- [ ] **Step 1: Delete the directory**

```bash
git rm -r deploy/
```

- [ ] **Step 2: Confirm staging**

```bash
git status
```

Expected: 6 deletions, all under `deploy/`. No other changes.

- [ ] **Step 3: Commit**

```bash
git commit -m "$(cat <<'EOF'
Remove deploy/ and op-inject artifacts (moved to iler/selfhost-configs)

The podman-quadlets stack now lives at
https://github.com/iler/selfhost-configs/tree/main/trmnl-ruuvi —
server configs belong with the operator, not with the app source.
Files were imported byte-identically; design rationale travels with
the git history of this repo through commit f0c117f.

Co-Authored-By: Claude
EOF
)"
```

---

### Task B3: Drop `QuadletParityTest` and `symfony/yaml` dep (commit 2 of 6)

- [ ] **Step 1: Delete the test file**

```bash
git rm tests/Unit/QuadletParityTest.php
```

- [ ] **Step 2: Remove symfony/yaml via composer (inside dev container)**

```bash
docker compose -f docker-compose.dev.yml exec -T app composer remove --dev symfony/yaml
```

This rewrites `composer.json` and regenerates `composer.lock`.

If `symfony/yaml` is still pulled in transitively by Laravel (likely — it's a transitive dep of multiple Laravel packages), it stays in `composer.lock` but disappears from `composer.json`'s `require-dev`. That's the intended state.

If composer fails because the dev container isn't running, start it first:
```bash
make dev
```
Then retry the composer command.

- [ ] **Step 3: Run the test suite to confirm nothing else broke**

```bash
docker compose -f docker-compose.dev.yml exec -T app vendor/bin/pest 2>&1 | tail -10
```

Expected: all remaining tests pass (the parity test count is gone). If anything else fails, STOP and report BLOCKED — investigate before continuing.

- [ ] **Step 4: Stage + commit**

```bash
git add composer.json composer.lock tests/Unit/QuadletParityTest.php
git status
```

Confirm staging shows: deleted test file, modified composer.json, modified composer.lock. Nothing else.

```bash
git commit -m "$(cat <<'EOF'
Drop QuadletParityTest and its symfony/yaml dev dep

The parity test asserted compose ⊆ quadlet env keys, but the quadlets
now live in iler/selfhost-configs — the two repos are independent
products with independent truth, so cross-repo parity testing no
longer fits. Operator notices drift on the next deploy (compose adds
a var, quadlet missing it → app starts with empty env → 500s) rather
than via this test.

symfony/yaml was added only to parse docker-compose.prod.yml in that
test; removing it from require-dev. Composer keeps the package in
composer.lock if Laravel still pulls it transitively.

Co-Authored-By: Claude
EOF
)"
```

---

### Task B4: Drop `verify-quadlets` Makefile target (commit 3 of 6)

- [ ] **Step 1: Read the current Makefile to find the target's exact lines**

```bash
grep -n "verify-quadlets\|prod (quadlets)" Makefile
```

- [ ] **Step 2: Remove the target, its comment block, and its `.PHONY` entry**

The Makefile currently contains (approximately) at the end:
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

Delete all 11 of those lines (the blank line before the section comment, the section comment header, the 4-line docstring comment, the target name line, and the 5 recipe lines). The file should end on the existing `.env` rule above it.

Also, in the `.PHONY` line (line 32 historically), remove the trailing ` verify-quadlets`:

Before:
```makefile
.PHONY: up down build logs shell ps test dev dev-down dev-build dev-logs dev-shell dev-ps verify-quadlets
```

After:
```makefile
.PHONY: up down build logs shell ps test dev dev-down dev-build dev-logs dev-shell dev-ps
```

- [ ] **Step 3: Verify the target is gone**

```bash
grep -c "verify-quadlets" Makefile
```

Expected: `0`. If non-zero, missed an occurrence — find and remove.

- [ ] **Step 4: Confirm `make` still works for unaffected targets**

```bash
make -n dev-shell 2>&1 | head -3
```

Expected: prints the dev-shell command without complaint (`-n` is dry-run). If `make` errors out, the file was edited incorrectly — STOP and report BLOCKED.

- [ ] **Step 5: Stage + commit**

```bash
git add Makefile
git commit -m "$(cat <<'EOF'
Drop verify-quadlets Makefile target

The quadlets now live in iler/selfhost-configs, which has its own
Makefile target for the same validator. No reason to keep a target
here that has no files to validate.

Co-Authored-By: Claude
EOF
)"
```

---

### Task B5: Drop `quadlet-validation` CI job (commit 4 of 6)

- [ ] **Step 1: Read the CI file to locate the job block**

```bash
grep -n "quadlet-validation\|docker-build" .github/workflows/ci.yml
```

- [ ] **Step 2: Remove the `quadlet-validation:` job block**

Open `.github/workflows/ci.yml`. Find the block:

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

Delete the entire block (from the `  quadlet-validation:` line down through the last `/usr/libexec/podman/quadlet --dryrun /tmp/out` line, plus the trailing blank line that separated it from `docker-build:`). The file should still have the four remaining jobs: `test`, `lint`, `decoder-spec-vectors`, `docker-build`.

- [ ] **Step 3: Verify YAML parses and contains the right job set**

```bash
python3 -c "import yaml; print(list(yaml.safe_load(open('.github/workflows/ci.yml'))['jobs'].keys()))"
```

Expected: `['test', 'lint', 'decoder-spec-vectors', 'docker-build']`. No `quadlet-validation`.

- [ ] **Step 4: Stage + commit**

```bash
git add .github/workflows/ci.yml
git commit -m "$(cat <<'EOF'
Drop quadlet-validation CI job

Selfhost-configs's own CI runs the same validator against the files
now hosted there. Keeping this job here would validate an empty
directory.

Co-Authored-By: Claude
EOF
)"
```

---

### Task B6: Remove superseded quadlets spec and plan docs (commit 5 of 6)

- [ ] **Step 1: Delete the two files**

```bash
git rm docs/superpowers/specs/2026-05-15-podman-quadlets-design.md
git rm docs/superpowers/plans/2026-05-15-podman-quadlets.md
```

- [ ] **Step 2: Confirm staging**

```bash
git status
```

Expected: exactly 2 deletions. The newer spec for THIS split (`docs/superpowers/specs/2026-05-16-move-server-configs-to-selfhost-configs-design.md`) MUST NOT be deleted in this commit — the operator may choose to clean it up later.

- [ ] **Step 3: Commit**

```bash
git commit -m "$(cat <<'EOF'
Remove superseded quadlets spec and plan docs

These documented the "quadlets live inside the app repo" design,
which is no longer how things are arranged. Git history preserves
them for anyone who wants to spelunk the journey.

Co-Authored-By: Claude
EOF
)"
```

---

### Task B7: Update README Option A to a pointer (commit 6 of 6)

- [ ] **Step 1: Locate the current Option A heading**

```bash
grep -n "^### Option A" README.md
```

- [ ] **Step 2: Replace the Option A subsection**

Open `README.md`. Find the current `### Option A` subsection (it spans from the `### Option A — Podman quadlets (systemd-managed, recommended for long-lived servers)` heading down to — but NOT including — the `### Option B — podman-compose with \`op run\`` heading that follows).

Replace everything between the two `###` headings (inclusive of the Option A heading, exclusive of the Option B heading) with exactly:

```markdown
### Option A — Podman quadlets (systemd-managed, recommended for long-lived servers)

Quadlet unit files, the `op-inject.service` oneshot, and the install guide live in a separate repo: **[iler/selfhost-configs/trmnl-ruuvi/](https://github.com/iler/selfhost-configs/tree/main/trmnl-ruuvi)**. Native systemd integration plus a daily auto-update timer that picks up new images published by CI to GHCR. Recommended for long-lived servers.

Why a separate repo: server configs (which 1Password environment ID, install paths, host-side systemd choices) belong with the operator, not with the app source.

```

(Note: include the trailing blank line so `### Option B` is properly separated.)

Option B (compose) is left exactly as-is.

- [ ] **Step 3: Verify the README's section structure is intact**

```bash
grep -n "^##" README.md
grep -n "^###" README.md
```

Expected: 11 h2 sections unchanged; exactly 2 h3 sections (`### Option A — ...` with the new pointer text; `### Option B — podman-compose with \`op run\``).

- [ ] **Step 4: Verify no stale deploy/ references remain in the README**

```bash
grep -n "deploy/" README.md && echo "FOUND" || echo "clean"
```

Expected: `clean`. If `FOUND`, a stale path leaked through — investigate.

- [ ] **Step 5: Stage + commit**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
Point quadlets deploy at iler/selfhost-configs

Option A now references the separate selfhost-configs repo where the
.container / .network / .volume / .service units actually live. Server
configs travel with the operator; the app source stays focused on the
app.

Co-Authored-By: Claude
EOF
)"
```

---

### Task B8: Final sanity check, push, open PR, watch CI, merge

- [ ] **Step 1: Sanity-grep for stale references**

```bash
grep -rn "deploy/quadlets\|deploy/systemd\|deploy/secrets\|QuadletParity\|verify-quadlets\|quadlet-validation" \
  --include="*.md" --include="*.yml" --include="*.yaml" --include="*.php" --include="Makefile" \
  --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
  .
```

Expected: only matches inside `docs/superpowers/specs/2026-05-16-move-server-configs-to-selfhost-configs-design.md` (the design doc references the old names while describing the migration — that's fine). NO matches in `README.md`, `Makefile`, `.github/workflows/ci.yml`, or any source/test file. If anything outside the design doc shows a match, investigate.

- [ ] **Step 2: Run the test suite one more time end-to-end**

```bash
make test
```

Expected: all tests pass (one fewer than before — `QuadletParityTest`'s 3-4 tests are gone). If anything fails, STOP and report BLOCKED.

- [ ] **Step 3: Confirm the 6 commits are in place**

```bash
git log --oneline main..HEAD
```

Expected: 6 commit titles in this order (most recent first):
- `Point quadlets deploy at iler/selfhost-configs`
- `Remove superseded quadlets spec and plan docs`
- `Drop quadlet-validation CI job`
- `Drop verify-quadlets Makefile target`
- `Drop QuadletParityTest and its symfony/yaml dev dep`
- `Remove deploy/ and op-inject artifacts (moved to iler/selfhost-configs)`

- [ ] **Step 4: Push the branch**

```bash
git push -u origin move-quadlets-to-selfhost-configs
```

- [ ] **Step 5: Open the PR**

```bash
gh pr create --title "Move server configs to iler/selfhost-configs" --body "$(cat <<'EOF'
## Summary
- Moves the podman-quadlets stack out of this repo. The configs now live at [iler/selfhost-configs/trmnl-ruuvi/](https://github.com/iler/selfhost-configs/tree/main/trmnl-ruuvi).
- Deletes: `deploy/`, `tests/Unit/QuadletParityTest.php`, the `verify-quadlets` Makefile target, the `quadlet-validation` CI job, the old quadlets spec + plan docs.
- Drops `symfony/yaml` from `require-dev` (was used only by the deleted parity test; remains in composer.lock if Laravel still pulls it transitively).
- Rewrites README's `## Server deploy` → Option A as a one-paragraph pointer to selfhost-configs. Option B (compose) unchanged.

Design rationale in `docs/superpowers/specs/2026-05-16-move-server-configs-to-selfhost-configs-design.md`.

## Test plan
- [x] `make test` green locally after all 6 commits
- [x] `grep -rn` for stale `deploy/quadlets`, `QuadletParity`, `verify-quadlets`, `quadlet-validation` strings returns nothing outside the design doc
- [ ] CI's four checks (Tests / Static analysis / RuuviTag spec vector / Docker image build) go green on this PR

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 6: Watch CI**

```bash
sleep 8
gh run list --branch move-quadlets-to-selfhost-configs --limit 1
```

Note the run ID, then:

```bash
gh run watch <RUN_ID> --exit-status
```

Expected: `Tests`, `Static analysis & code style`, `RuuviTag spec vector regression` all SUCCESS; `Docker image build` SKIPPED (it only fires on push to `main`).

- [ ] **Step 7: Confirm the rollup**

```bash
gh pr view --json statusCheckRollup -q '.statusCheckRollup[] | "\(.conclusion // .status)\t\(.name)"'
```

Expected:
```
SUCCESS  Tests
SUCCESS  Static analysis & code style
SUCCESS  RuuviTag spec vector regression
SKIPPED  Docker image build
```

If `Tests` fails on `symfony/yaml`-related class-not-found errors (unlikely but possible if any other test file imports `Symfony\Component\Yaml\Yaml` outside the parity test we deleted), investigate that file; it's the same composer-remove issue.

- [ ] **Step 8: Merge the PR**

```bash
gh pr merge --squash --delete-branch
```

(Or `--merge` if the operator prefers a merge commit. The 6 commits are tightly scoped, so squash gives a clean history.)

- [ ] **Step 9: Verify main is updated**

```bash
git checkout main
git pull
git log --oneline -3
```

Expected: HEAD is the new squashed/merged commit for the move. `deploy/` is gone, README Option A is a pointer.

---

## Self-Review Checklist (for the engineer executing the plan)

After both phases land, sanity-check end-to-end:

```bash
# Phase A — selfhost-configs is live
cd /Users/ilari/.superset/projects/selfhost-configs
git log --oneline -3
make verify-quadlets
ls trmnl-ruuvi/

# Phase B — trmnl-ruuvitag is cleaned up
cd /Users/ilari/.superset/worktrees/trmnl-ruuvitag/initial-version
git log --oneline -3
ls deploy/ 2>/dev/null && echo "FAIL: deploy still exists" || echo "OK: deploy gone"
grep -c "verify-quadlets\|quadlet-validation" Makefile .github/workflows/ci.yml
grep -n "iler/selfhost-configs" README.md
make test
```

All four `cd`-and-check sections should report cleanly. The grep counts should be 0 0 (no traces left in Makefile or CI). README should reference selfhost-configs.

## Out of Scope

These are deliberate non-goals for this PR pair. Don't expand into them:

- Preserving git history of the 13 quadlets-stack commits inside selfhost-configs (plain copy was chosen over `git filter-repo`).
- Cross-repo parity testing (the original parity test is gone for good).
- An `install.sh` helper script for selfhost-configs (deferred; six commands copy-pasted from README).
- Multi-project Makefile abstraction in selfhost-configs (single project = single target until a second app shows up).
- Deleting the new design doc (`docs/superpowers/specs/2026-05-16-move-server-configs-to-selfhost-configs-design.md`) and this plan from trmnl-ruuvitag. Operator can do that as a tail-end commit after both PRs merge if desired; not part of either PR.
