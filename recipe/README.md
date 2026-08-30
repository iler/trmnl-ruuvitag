# Ruuvi Indoor Climate — TRMNL recipe

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

## Deploying

```sh
docker run -it --rm -v "$HOME/.config/trmnlp:/root/.config/trmnlp" -v "$PWD:/plugin" \
    --entrypoint /bin/bash trmnl/trmnlp
# inside: trmnlp login && trmnlp push
```

**Add the returned `id:` to `src/settings.yml` after the first push.** Without
one, every `trmnlp push` creates another new plugin instead of updating this one.

## Before publishing as a Recipe

- [ ] Add an `author_bio` field with contact details — the `Chef` linter expects one
- [ ] Regenerate `demo/sensors-dense.json`; its timestamps are absolute, so a
      stale fixture makes the marketplace preview render every sensor as stale
- [ ] Check the card grid on a real TRMNL X, portrait as well as landscape
- [ ] Confirm the token is only ever read from the form field

Note what publishing means for secrets: each installer's Ruuvi token is stored
by TRMNL and sent from TRMNL's servers to Ruuvi. The plugin only ever reads.

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
you get the hero and ledger, which stay legible when a large fleet has to fit
800x480. On `lg` — TRMNL X and similar — you get the card grid ported from
`trmnl_x.blade.php`: a header band with counts, then ten cards showing
temperature, humidity, pressure and battery.

Only one is ever displayed; the other carries `hidden`. A hero-plus-ledger and
a card grid are different structures, not one structure at two sizes, so
responsive utilities on a single tree would have meant fighting both.

Ten cards is what the 5x2 grid holds. Priority order decides which ten, and
anything past that is counted at the foot of the screen rather than dropped
silently.

Two changes from the Blade original, both to satisfy `trmnlp lint`:

- The alert stripe was a `:has()` rule. The condition is known in Liquid, so
  the card just gets a `ruuvi-card--flagged` class instead.
- Spacing, alignment and the corner radius are framework classes. The
  stylesheet keeps only the card frame, the header rule, and the row sizing —
  `limited_inline_styles` counts `padding`, `justify-content` and
  `border-radius` anywhere in the markup and allows six.

### Previewing the large layout

`trmnlp` renders at 800x480 and never emits a `screen--lg` class, so the card
grid is invisible locally. To see it, patch the built HTML:

```sh
docker run --rm -v "$PWD:/plugin" trmnl/trmnlp build

sed 's|<div class="screen">|<div class="screen screen--lg screen--4bit" \
    style="--screen-w:1872px;--screen-h:1404px;--full-w:1872px;--full-h:1404px;">|' \
    _build/full.html > _build/full_x.html

docker run --rm -v "$PWD:/plugin" --entrypoint firefox trmnl/trmnlp \
    --headless --screenshot /plugin/_build/x.png --window-size=1900,1440 \
    file:///plugin/_build/full_x.html
```

Swap `screen--lg` for `screen--md screen--1bit` to check the small layout the
same way.
