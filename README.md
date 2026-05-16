# TRMNL Ruuvitag

Laravel app that pushes Ruuvitag sensor data to a TRMNL e-ink device via TRMNL's webhook push strategy.

## Configuration

The app reads its sensor list from the Ruuvi Cloud account that owns `RUUVI_API_TOKEN`. Sensor names, display order, and alert thresholds (temperature, humidity, battery) are managed in the Ruuvi app — there is no per-sensor configuration in this repo. Add or rename a sensor in Ruuvi and it shows up on TRMNL within one fetch cycle. Alarms surface when Ruuvi's own alert is both enabled and triggered, which respects any delay/hysteresis configured there.

Required and tunable env vars:

| Variable             | Default                      | Purpose                                    |
| -------------------- | ---------------------------- | ------------------------------------------ |
| `RUUVI_API_TOKEN`    | _required_                   | Ruuvi Cloud bearer token                   |
| `RUUVI_API_BASE`     | `https://network.ruuvi.com`  | API base URL (override only for testing)   |
| `RUUVI_DISPLAY_TZ`   | `Europe/Helsinki`            | Timezone for `measured_at` in the payload  |
| `RUUVI_CACHE_TTL`    | `300`                        | Seconds the cloud response is cached       |
| `RUUVI_STALE_AFTER`  | `1800`                       | Age (s) past which a reading is `stale`    |

The scheduler triggers a fetch every 15 minutes (`routes/console.php`). Readings persist in SQLite for dedup and history; only the latest is sent to TRMNL.

## Local development

Secrets come from a 1Password Environment. The 1Password desktop app + `op` CLI authenticate via system biometrics — no service-account token is needed locally.

```sh
make build      # build the app image
make up         # start app + nightwatch-agent
make logs       # tail app logs
make shell      # shell into the app container
make test       # run Pest tests inside the container
make down       # stop
```

Every target wraps `docker compose` with `op run --environment $OP_ENV_ID --` so the Environment is the single source of truth for app secrets. Plain `docker compose up` won't work — the Environment IDs aren't in shell env without the wrap.

## Server deploy

Two parallel deploy paths, both first-class. They share the same secret bootstrap (`/etc/trmnl-ruuvi/bootstrap.env`); pick the orchestrator that fits the target host.

### Option A — Podman quadlets (systemd-managed, recommended for long-lived servers)

Native systemd integration plus a daily auto-update timer that picks up new images published by CI to GHCR. Requires podman 4.4+ (which ships the quadlet generator) and the `op` CLI at `/usr/local/bin/op`.

1. Clone this repo onto the VM (the deploy files ship inside it).

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
    OP_ENVIRONMENT_ID=einqhwbbevqifrwwxl66hvitpm
    ```

    The quadlet path uses only `OP_SERVICE_ACCOUNT_TOKEN`; `OP_ENVIRONMENT_ID` is retained for Option B (sharing one bootstrap.env across both paths).

3. Copy the unit files and the secret template into place:

    ```sh
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

**Operational controls.** To pause auto-updates (e.g., to debug a regression without `:latest` moving under you): `sudo systemctl disable --now podman-auto-update.timer`. To pin to a specific image, edit the `Image=` line in `/etc/containers/systemd/app.container` to `ghcr.io/iler/trmnl-ruuvitag:<sha>`, then `sudo systemctl daemon-reload && sudo systemctl restart app.service`. Re-enable auto-updates with `sudo systemctl enable --now podman-auto-update.timer`.

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

## Why not the local-`.env` FIFO mount

The 1Password local-`.env` mount looks like the natural fit for this project but doesn't currently work with Docker Compose. Compose opens the `.env` FIFO multiple times during a single `compose up` (default substitution + one open per service that lists `env_file:`), and 1P's writer races on subsequent opens — comment-header lines and earlier content get re-injected mid-stream, producing parser errors at varying line offsets. A minimal repro is in `docs/1password-fifo-repro.md`. Filed upstream: _add links_.

---

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
