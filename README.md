# TRMNL Ruuvitag

RuuviTag sensor readings on a TRMNL e-ink display, as a TRMNL plugin. No server
of your own: TRMNL polls Ruuvi Cloud on its own schedule, a Serverless function
decodes the readings, and Liquid templates draw them.

Everything lives in **[`recipe/`](recipe/)** — see its README for the layout,
local development, deployment, and the design notes.

```
TRMNL poller  ──▶  network.ruuvi.com/sensors-dense   (Authorization from a form field)
                            │
                            ▼
              recipe/src/transform.js  on TRMNL Serverless (node)
                    strips the BLE advertisement wrapper,
                    decodes Rawv2 (0x05) and Air (0xE1),
                    buckets battery, marks stale, orders by priority
                            │
                            ▼
              recipe/src/*.liquid  ──▶  the device
```

Ruuvi Cloud returns the whole BLE advertisement as a hex string and Liquid has
no filters to unpack it, which is the only reason any code runs at all.

## Environment

| Variable | Purpose |
| --- | --- |
| `TRMNL_RUUVI_MCP_API_KEY` | Resolves `${TRMNL_RUUVI_MCP_API_KEY}` in `.mcp.json`. Scoped to this plugin; generate it from the plugin's settings page in the TRMNL web UI. |
| `TRMNL_API_KEY` | Used by `trmnlp push` to deploy. Account-wide. |

Neither belongs in the repo. `.mcp.json` holds only the reference, not the key.

## CI

- **Recipe transform spec vectors** — the Ruuvi spec vectors against
  `transform.js`. The decoding fails silently when it fails at all, so this is
  the job that matters.
- **Plugin lint** — `trmnlp lint`.

## History

This was a self-hosted Laravel application that pushed to TRMNL over a webhook:
scheduler, SQLite, Docker images on GHCR, systemd units on a server. TRMNL's
Serverless runtime removed the need for any of it, and the app was replaced by
the plugin in #6 and #7, then deleted in #9 once its decoder tests had been
ported to Node in #8.

The git history has all of it if you need it back.

## License

MIT — see [LICENSE](LICENSE).
