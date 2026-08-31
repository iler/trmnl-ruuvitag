# TRMNL Ruuvitag

A TRMNL private plugin that puts RuuviTag readings on the screen with no server
of your own. It replaces the Laravel app in the parent directory: TRMNL polls
Ruuvi Cloud on its own schedule, a Serverless function decodes the readings, and
Liquid templates draw them.

## How it works

```
TRMNL poller  ──▶  network.ruuvi.com/sensors-dense   (Authorization from a form field)
                            │
                            ▼
              src/transform.js  on TRMNL Serverless (node)
                    strips the BLE advertisement wrapper,
                    decodes Rawv2 (0x05) and Air (0xE1),
                    buckets battery, marks stale, orders by priority
                            │
                            ▼
              src/*.liquid  ──▶  the device
```

Ruuvi Cloud returns the whole BLE advertisement as a hex string and Liquid has
no filters to unpack it, which is why the Serverless step exists at all.

## Layout

| File | Purpose |
| --- | --- |
| `src/settings.yml` | Plugin definition — polling, headers, form fields |
| `src/transform.js` | Serverless `run(input)`; the decoding and shaping |
| `src/shared.liquid` | Assigns and `{% template %}` partials for every layout |
| `src/full.liquid` | Hero plus indexed ledger; a card grid on large screens |
| `src/half_horizontal.liquid` | Four sensors as lines |
| `src/half_vertical.liquid` | Six sensors as cards |
| `src/quadrant.liquid` | One sensor |
| `demo/build-fixture.js` | Regenerates the demo response below |
| `demo/sensors-dense.json` | A stand-in Ruuvi response covering every branch |

## Settings the installer fills in

| Field | Notes |
| --- | --- |
| Ruuvi Cloud API token | Masked. Ruuvi Station > Settings > My Ruuvi account > Request an API token |
| Priority sensors | Comma-separated names or MACs, best first. Optional |
| Stale after (minutes) | Default 30 |

**Priority sensors sets the order, not a filter.** Sensors you leave out still
appear, after the ones you name. That is what lets one field serve every
layout: the quadrant shows the first, a half shows the first few, the full
screen shows them all. A name that matches nothing is listed in the title bar
so a typo is visible on the device rather than silent.

## Local development

```sh
export RUUVI_API_TOKEN=...        # .trmnlp.yml reads it from the environment
docker run --rm --pull always -p 4567:4567 -v "$PWD:/plugin" trmnl/trmnlp serve
```

Then open <http://localhost:4567>. Edit any file under `src/` and the preview
follows. `trmnlp` runs `src/transform.js` through its own node, the same way
the hosted runtime does.

```sh
docker run --rm -v "$PWD:/plugin" trmnl/trmnlp lint    # gate before pushing
docker run --rm -v "$PWD:/plugin" trmnl/trmnlp build   # static HTML per layout
```

To preview without a Ruuvi account, switch `strategy` to `static` and paste
`demo/sensors-dense.json` into `static_data`.

## Tests

```sh
node --test test/transform.test.mjs
```

No dependencies and no build — Node's own test runner against
`src/transform.js`. The suite loads the file and wraps it in a `Function` to
reach its top-level declarations, so the deployed source keeps the bare
`run(input)` contract TRMNL expects and carries no exports.

It covers the four Rawv2 vectors from the Ruuvi spec, a captured Air payload,
advertisement unwrapping, the battery buckets, priority ordering, and `run()`
end to end including the fault and stale paths.

This matters more than template tests. A Liquid mistake shows up as a blank or
ugly screen; a decoding mistake shows up as a plausible wrong number that
nobody notices. Confirmed to catch a wrong bit shift, a removed sentinel
check, and an off-by-one in the AD walk.

| File | Purpose |
| --- | --- |
| `test/transform.test.mjs` | Spec vectors and behaviour for the transform |

## Deploying

```sh
bin/push            # asks before it overwrites
bin/push --force    # no prompt
```

`bin/push` reads the TRMNL account key from 1Password (or `$TRMNL_API_KEY` if
already exported) and refuses to run when `src/settings.yml` has no `id` —
without one, `trmnlp push` does not fail, it quietly creates a new plugin and
does it again on every run. The script is shared verbatim across plugin repos;
its canonical copy lives in
[iler/trmnl-met-norway](https://github.com/iler/trmnl-met-norway/blob/main/bin/push).

`push` uploads the templates, the transform, and everything in `settings.yml` —
strategy, polling URL and headers, refresh interval, and the custom-field
*definitions*. It replaces whatever the plugin held, which is why
`src/settings.yml` carries the plugin `id`.

**`push` rewrites `src/settings.yml`.** It ends by writing the server's
canonical YAML back over the local file, which strips its comments and adds the
empty `oauth_*` keys the API returns. Restore it with `git restore
src/settings.yml` after a push, unless you actually changed something.

What `push` does *not* upload is the field **values** — the Ruuvi token, the
priority list, the stale threshold. Those belong to the plugin instance, so
enter them in the TRMNL web UI. They survive later pushes.


## Before publishing as a Recipe

- [ ] Check the marketplace preview renders — `demo/sensors-dense.json` anchors
      its timestamps to fixed dates, so it does not need regenerating
- [ ] Check the card grid on a real TRMNL X, portrait as well as landscape
- [ ] Confirm the token is only ever read from the form field

Note what publishing means for secrets: each installer's Ruuvi token is stored
by TRMNL and sent from TRMNL's servers to Ruuvi. The plugin only ever reads.
The `author_bio` field says so on the form, which the publishing guidelines
ask for.

## Differences from the Laravel app

| Laravel | Here |
| --- | --- |
| SQLite `sensor_readings`, dedupe by sequence number | No storage — each render reads what Ruuvi reports now |
| `fetchHistory()`, unused, kept for a future sparkline | Gone; a sparkline would need a backend again |
| Scheduler every 15 minutes, plus a cache and a rate limiter | TRMNL's `refresh_interval`, one request per refresh |
| `opacity-50` dims a stale sensor | `text--gray-30` — `trmnlp lint` rejects opacity, which dithers badly on e-ink |
| Icon components for battery and temperature | The same Lucide glyphs, inlined in `shared.liquid` |
| `RUUVI_DISPLAY_TZ` | The viewer's own TRMNL time zone |

Acceleration, TX power, movement and the measurement sequence are no longer
decoded. Nothing on screen used them, and sequence numbers only existed to
dedupe database rows.

## Large screens

`full.liquid` carries two designs, not one design that stretches. Below `lg`
you get the hero and ledger; on `lg` — TRMNL X and similar — you get the card
grid ported from `trmnl_x.blade.php`: a header band with counts, then twelve
cards showing temperature, humidity, pressure and battery.

Only one is ever displayed; the other carries `hidden`. A hero-plus-ledger and
a card grid are different structures, not one structure at two sizes, so
responsive utilities on a single tree would have meant fighting both.

### Size for the CSS canvas, not the pixel count

TRMNL X reports 1872x1404, but the device log shows how it actually renders:

```
Appearance(... scale_factor: 1.8, text_scale: large,
           custom_width: 1872, custom_height: 1404, css_size: lg)
```

At `scale_factor: 1.8` the CSS canvas is about **1040x780**, so a four-column
grid leaves roughly 200px of content per card. That is what the card is built
for. Sized against the raw 1872 instead, `value--xxlarge` (96px) needs about
275px for `-22.6` alone, and everything below the number is pushed out of the
card — which is exactly what happened on the first attempt.

Both grids cap what they draw and count the rest: twelve cards on `lg`, eleven
ledger rows below it. A nineteen-sensor fleet overruns either otherwise, and on
800x480 the overrun pushes the hero off the top of the screen.

Two changes from the Blade original, both to satisfy `trmnlp lint`:

- The alert stripe was a `:has()` rule. The condition is known in Liquid, so
  the card just gets a `ruuvi-card--flagged` class instead.
- Spacing, alignment and the corner radius are framework classes. The
  stylesheet keeps only the card frame, the header rule, the row sizing and
  the ledger's column widths — `limited_inline_styles` counts `padding`,
  `justify-content` and `border-radius` anywhere in the markup and allows six.

Note that `value--medium` does not exist in the framework. `value--base` (38px)
is the step between `small` (26px) and `large` (58px).

### Previewing the large layout

`trmnlp` renders at 800x480 and never emits a `screen--lg` class, so the card
grid is invisible locally. Patch the built HTML, and set the canvas variables
to the CSS size rather than the device's pixel count:

```sh
docker run --rm -v "$PWD:/plugin" trmnl/trmnlp build

sed 's|<div class="screen">|<div class="screen screen--lg screen--4bit" \
    style="--screen-w:1040px;--screen-h:780px;--full-w:1040px;--full-h:780px;">|' \
    _build/full.html > _build/full_x.html

docker run --rm -v "$PWD:/plugin" --entrypoint firefox trmnl/trmnlp \
    --headless --screenshot /plugin/_build/x.png --window-size=1060,820 \
    file:///plugin/_build/full_x.html
```

Swap `screen--lg` for `screen--md screen--1bit` and drop the style attribute to
check the small layout the same way.

This does not reproduce `text_scale: large`, so the device is still the last
word.

**Mashup sizes do not preview reliably at all.** Adding `screen--v2` renders
half layouts about 1.8x too large; setting `--screen-w` / `--screen-h` by hand
instead renders them blank, because `.layout` takes the full screen width while
the half view is narrower. Neither matches the device. Check `half_horizontal`
and `half_vertical` on real hardware, not here. The TRMNL MCP server (`.mcp.json`) is the quickest way to check the real
thing: `MergeVariablesShowTool` for what the transform produced and
`IntegrationsLogsTool` for the render appearance and any transform error. Its
screenshot tool returns a 160x96 thumbnail, which is too small to judge a
layout by.

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
