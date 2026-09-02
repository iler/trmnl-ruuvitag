# TRMNL Ruuvitag

A TRMNL private plugin that puts RuuviTag readings on the screen with no server
of your own: TRMNL polls
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
| — | A Ruuvi Air card shows CO2 where a RuuviTag shows pressure |
| `src/half_horizontal.liquid` | Four sensors — lines below `lg`, cards above |
| `src/half_vertical.liquid` | Six sensors — cards either way, two columns on `lg` |
| `src/quadrant.liquid` | One sensor |
| `test/transform.test.mjs` | Spec vectors and behaviour for the transform |
| `demo/build-fixture.js` | Regenerates the demo response below |
| `demo/sensors-dense.json` | A stand-in Ruuvi response covering every branch |
| `bin/push` | Deploy; shared verbatim across plugin repos |
| `docs/development.md` | The development loop, in full |

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
RUUVI_API_TOKEN=... docker run --rm -p 4567:4567 \
  -e RUUVI_API_TOKEN -v "$PWD:/plugin" trmnl/trmnlp serve
```

Then <http://localhost:4567>. Edit anything under `src/` and the preview
follows; `trmnlp` runs `src/transform.js` through its own node, the same way
the hosted runtime does.

**[`docs/development.md`](docs/development.md) is the full loop** — previewing
the TRMNL X layout, reaching states the sensors will not produce on demand,
linting, pushing, and the traps worth knowing before you hit them.

## Tests

```sh
node --test test/transform.test.mjs
```

The Ruuvi spec vectors against `src/transform.js`. No dependencies, no build.
The decoding is what deserves the coverage: a wrong shift or a missed sentinel
does not raise, it produces a plausible number and the screen shows it as fact.
See [`docs/development.md`](docs/development.md#tests).

## Deploying

```sh
bin/push            # asks before it overwrites
bin/push --force    # no prompt
```

Reads the TRMNL account key from 1Password and refuses to run without an `id`
in `src/settings.yml`. Run it from your own terminal — an agent's sandbox
cannot reach `op`. Push rewrites `src/settings.yml`, so `git restore
src/settings.yml` afterwards.

Full detail, including who can hold the key and why it is not in this repo's
1Password Environment, in [`docs/development.md`](docs/development.md#push-to-trmnl).

## Before publishing as a Recipe

Chef, TRMNL's publication linter, runs server-side at publish — `trmnlp lint`
passing locally says nothing about readiness. Outstanding:

- [ ] **Icon source** — the icon is uploaded on TRMNL, but no source file is in
      the repo, so it cannot be recut or restored from here. `trmnlp push`
      cannot carry it either way; the upload is and stays manual
- [ ] **Featured image** — captured from the plugin's own current screen, not
      uploaded. Take it off every playlist first so the screen freezes, and
      never capture one built from `demo/sensors-dense.json`
- [ ] Check the card grid on a real TRMNL X, portrait as well as landscape

Done: the Developer add-on, the icon, `author_bio` with `category` and a
contact route, title and description within their limits, every custom field
used, one inline-style occurrence against a budget of six, no `view--*`
classes, no opacity, no async, and inline SVG rather than remote images.

See [`docs/development.md`](docs/development.md#publishing-what-local-tooling-cannot-tell-you).

## What it deliberately does not do

Nothing persists between renders. Each poll draws what Ruuvi reports at that
moment, so there is no history on the device and a sparkline would mean running
a backend again.

The decoders skip acceleration, TX power, movement and the measurement
sequence. Nothing on screen uses them, and the sequence number only ever
existed to deduplicate stored readings, which there are none of.

Ruuvi Air's VOC and NOX indices are decoded but not drawn: the bit position
they come from is reasoned from one payload rather than proven, so they ride
in the merge data where they can be checked against the Ruuvi app first.
Luminosity is not decoded at all — the sensor is not fitted on production
hardware and the field is always its sentinel.

## Large screens

`full.liquid` carries two designs, not one design that stretches. Below `lg`
you get the hero and ledger; on `lg` — TRMNL X and similar — you get the card
grid: a header band with counts, then twelve
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

Two constraints come from `trmnlp lint`:

- The alert stripe is a `ruuvi-card--flagged` class rather than a `:has()`
  rule. The condition is known in Liquid, so it can simply be written.
- Spacing, alignment and the corner radius are framework classes. The
  stylesheet keeps only the card frame, the header rule, the row sizing and
  the ledger's column widths — `limited_inline_styles` counts `padding`,
  `justify-content` and `border-radius` anywhere in the markup and allows six.

Note that `value--medium` does not exist in the framework. `value--base` (38px)
is the step between `small` (26px) and `large` (58px).

### Previewing the large layout

`trmnlp` cannot render the `lg` branch: its renderer only ever adds
`screen--no-bleed` to the screen class, whatever `width` and `height` say, and
the render template is hardcoded inside the gem. The workaround — patch the
built HTML, then screenshot it at the CSS canvas size — is in
[`docs/development.md`](docs/development.md#previewing-the-trmnl-x-layout),
along with why mashup sizes cannot be previewed at all.

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

## License

MIT — see [LICENSE](LICENSE).
