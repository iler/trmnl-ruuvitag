# Development loop

The repo is the source of truth. Edit the files in `src/`, preview them locally,
and push to TRMNL only when they are right. Do not edit the markup in the TRMNL
browser editor: `trmnlp push` replaces the whole plugin, so a browser edit is
lost on the next push, and after publication it is a production change for every
installed user.

## Preview locally

`trmnlp` is TRMNL's own dev server. It reads `src/`, polls Ruuvi Cloud for real,
and runs `src/transform.js` through the same contract the hosted Serverless
runtime uses.

```sh
RUUVI_API_TOKEN=... docker run --rm --name trmnlp -p 4567:4567 \
  -e RUUVI_API_TOKEN -v "$PWD:/plugin" trmnl/trmnlp serve
```

Open <http://localhost:4567/full>. The page reloads when a file in `src/`
changes but keeps the last polled payload; use the **Poll** button or
`curl http://localhost:4567/poll` to fetch Ruuvi again.

| Route | Gives |
|---|---|
| `/render/full.png?width=800&height=480` | the PNG, as the device rasterises it |
| `/render/full.html` | the rendered HTML, for reading the markup |
| `/data` | the merge variables the transform produced |
| `/poll` | a fresh poll |

`.trmnlp.yml` sets `ruuvi_token` from `{{ env.RUUVI_API_TOKEN }}`. That is safe
**here** because the token is only ever consumed by `polling_headers`. `trmnlp`
interpolates env into the polling settings but hands templates the raw string,
so a field a template or the transform reads must not use `{{ env.* }}` — it
would render the Liquid source instead of the value.

## Previewing the TRMNL X layout

`full.liquid` carries two designs behind `lg:hidden` / `hidden lg:flex`, and
**`trmnlp` cannot render the `lg` one.** Its renderer builds the screen class
from exactly one input:

```ruby
def screen_classes(classes = 'screen')
  classes += ' screen--no-bleed' if config.plugin.no_screen_padding == 'yes'
  classes
end
```

No `screen--lg`, no `screen--v2`, whatever `width` and `height` say — those set
`trmnl.device.*` and the PNG size only. The render template is hardcoded inside
the gem, so a project cannot override it either. The `lg:` utilities never
activate locally.

Patch the built HTML instead:

```sh
docker run --rm -v "$PWD:/plugin" trmnl/trmnlp build

sed 's|<div class="screen">|<div class="screen screen--lg screen--4bit" \
    style="--screen-w:1040px;--screen-h:780px;--full-w:1040px;--full-h:780px;">|' \
    _build/full.html > _build/full_x.html

docker run --rm -v "$PWD:/plugin" --entrypoint firefox trmnl/trmnlp \
    --headless --screenshot /plugin/_build/x.png --window-size=1060,820 \
    file:///plugin/_build/full_x.html
```

**Set the canvas to the CSS size, not the device's pixel count.** TRMNL X
reports 1872x1404 but renders at `scale_factor: 1.8`, so the CSS canvas is
1040x780. Sizing against 1872 gives a layout roughly twice the room it has, and
the overflow only shows up on the panel.

Swap `screen--lg screen--4bit` for `screen--md screen--1bit`, and drop the style
attribute, to check the small layout.

**`trmnlp` cannot render a PNG narrower than about 450px.** Its headless
Firefox clamps the viewport, so `half_vertical` (400x480) and `quadrant`
(400x240) fail with `the browser clamped the viewport to 450x480`. Use the HTML
render in a browser sized to the real viewport instead:

```
http://localhost:4567/render/half_vertical.html?width=400&height=480
http://localhost:4567/render/quadrant.html?width=400&height=240
```

**Mashup sizes do not preview at all.** `screen--v2` renders halves about 1.8x
too large; setting the canvas by hand renders them blank, because `.layout`
takes the full screen width while a half view is narrower. Check
`half_horizontal` and `half_vertical` on real hardware.

## Preview states Ruuvi will not produce on demand

A live account shows whatever the sensors are doing, which is usually "all fine".
The stale card, the no-data card, a triggered alarm and a low battery cannot be
summoned from real data.

`demo/sensors-dense.json` has all of them: 19 sensors, one permanently stale,
one that never reported, one alarm, one low battery, and a freezer at -22.6 —
the widest string the hero has to hold. Its timestamps anchor to fixed dates
rather than to generation time, so it never rots into an all-stale screen.

To render from it, switch `strategy` to `static` and paste the file into
`static_data`. Regenerate with `node demo/build-fixture.js > demo/sensors-dense.json`
after changing the sensor list.

`.trmnlp.yml`'s `variables:` block also deep-merges over any top-level key, so it
can override part of a live payload — but it cannot empty one out, and a failed
poll is easier to test by pointing `polling_url` at a path that does not exist.

## Preview another configuration

Edit `custom_fields` and `time_zone` in `.trmnlp.yml`:

- `featured_sensors` — the priority order. Blank keeps Ruuvi's own order.
- `stale_after_minutes` — drop it to `1` to see every card go stale.
- `time_zone` — change it and check the clock follows the viewer, not the API.

## Tests

```sh
node --test test/transform.test.mjs
```

No dependencies, no build. The suite wraps `src/transform.js` in a `Function` to
reach its top-level declarations, so the deployed file keeps the bare
`run(input)` contract TRMNL expects and carries no exports.

The decoding is the part worth testing: a wrong shift or a missed sentinel does
not raise, it produces a plausible number and the screen shows it as fact. The
vectors come from the Ruuvi spec. Confirmed to catch a `>>5` turned into `>>4`,
a removed sentinel check, and an off-by-one in the AD walk.

## Lint

```sh
docker run --rm -v "$PWD:/plugin" trmnl/trmnlp lint
```

The **Docker image, not the gem**: the published `trmnl_preview` gem has no
`lint` command yet and fails with `Could not find command "lint"`. The image is
also what the previews above use, so there is no version skew.

`.github/workflows/ci.yml` runs this and the transform tests on every pull
request and on `main`. There is no push job on purpose: lint needs no key, so
the gate costs no exposure, while a push job would put the account key where
every action in the workflow can read it.

## Push to TRMNL

`src/settings.yml` carries `id: 461530`. `trmnlp push` updates that plugin.
**Without the id it creates a new plugin on every run**, so never remove it.
`bin/push` refuses to run when the id is missing, and checks before fetching the
key so a doomed push raises no 1Password prompt.

```sh
bin/push            # asks before it overwrites
bin/push --force    # no prompt
```

**Run it from your own terminal.** A coding agent's sandbox cannot read
`~/.config/op`, so `op read` fails there with `operation not permitted` and no
push happens. In Claude Code, type `! bin/push` so the output lands in the
conversation.

`bin/push` takes the key from the first source that has it:

1. `TRMNL_API_KEY` already in the environment — GitHub Actions, or an export.
2. `op read` of `TRMNL_API_KEY_REF`, which defaults to `op://TRMNL/API/credential`.
   Override it for a different vault or item:

   ```sh
   export TRMNL_API_KEY_REF="op://Vault/Item/field"
   ```

Either way it hands the key to Docker **by name, not by value**, so it never
reaches your shell history or the process list. Do not write
`-e TRMNL_API_KEY=<the key>` by hand.

`push` uploads the templates, the transform, and everything in `settings.yml` —
strategy, polling URL and headers, refresh interval, and the custom-field
*definitions*. It does **not** upload the field *values*: the Ruuvi token, the
priority list and the stale threshold belong to the plugin instance and are
entered in the web UI. They survive later pushes.

### Using this script in another plugin

`bin/push` holds nothing repo-specific. Copy it to any trmnl plugin project
unchanged:

```sh
cp bin/push ../other-plugin/bin/push
```

**Never copy `src/settings.yml` with it.** Its `id` is the one per-plugin value,
and pushing with another plugin's id overwrites that plugin — silently, because
it is a valid update. Copying a whole repo as a template is where this bites.

Prefer a copy in each repo over one shared script on your `PATH`: the repos are
public and document this loop, so a clone should be able to run it.

### After publication, a push is a production change

Today the plugin is private and a bad push costs nothing. Once the Recipe is
published, the Recipe Master is live for every installed user and its own
generated screen is the public preview. At that point `bin/push --force` is a
deploy, not a save. Lint, preview locally, and look at the screen first.

## Publishing: what local tooling cannot tell you

**`trmnlp lint` is not Chef.** Chef is TRMNL's publication linter and runs
server-side only, at publish. Local lint passes happily with problems that
block publication, so a green CI badge says nothing about readiness. Chef's
checks are the authority:
<https://gist.github.com/ryanckulp/fbe5f68c51db1ae214a97da24be4d62b>

Two of its requirements are attached outside the code and cannot travel with a
push:

- **An icon.** Uploaded in the plugin's settings view. `trmnlp push` does not
  carry it — it is not a `settings.yml` field — so it is a manual step and
  stays one. 512x512 PNG. This plugin's icon is uploaded but its source is not
  in the repo, so it cannot be recut or restored from here; drop the source in
  if you still have it. Keep Ruuvi's branding out of it.
- **A featured image**, which is the section below.

Three more live in `settings.yml` and so are in git: the `author_bio` field,
its `category` (up to two, comma separated — there is no "weather" category,
and Chef fails without one), and at least one contact route among
`email_address`, `github_url`, `learn_more_url` and `youtube_url`.

## The featured image is not a file

TRMNL captures it from the plugin's **current generated screen**: edit the
recipe settings and press the button that sets the preview image to the current
screen. So the work is to make the screen worth capturing, then press it.

**Never capture a screen built from `demo/sensors-dense.json`.** The fixture
exists to reach states the sensors will not produce on demand. Presented as a
marketplace preview it is a fabricated product shot — its rooms do not exist.

## Ruuvi Cloud's limits

Ruuvi rate-limits authenticated queries to
`4 * MAX_SENSORS_OWNED + 0.1 * MAX_HISTORY_DAYS` per minute — on a Pro plan with
25 sensors and 731 days that is about 173 a minute, against the four an hour a
15-minute refresh makes.

The limit is **per account, not per application**, because every installer
authenticates with their own token. So the usual recipe hazard — one installer
picking a fast refresh and getting the whole plugin throttled — does not apply
here. An installer can only spend their own quota. The `author_bio` says so.

## The API key, and who can hold one

The key is **per account, not per plugin**. It reads and writes every private
plugin on the account.

**It is not in this repo's 1Password Environment, and should not be added.** That
Environment holds one plugin's secrets; a key with a wider scope would be copied
into every plugin repo, which means many places to rotate and an account-wide key
readable by everything in each of them. One canonical item feeds every plugin
repo through `TRMNL_API_KEY_REF`, so a rotation is one edit and `bin/push` stays
byte-identical across repos.

There is no narrower key to hand out, so:

- A second person cannot be given a push key scoped to this plugin. Either the
  account owner does the pushes, or that person gets the account key and with it
  every plugin on the account.
- Share the *process* — this file — never the value. Someone with their own TRMNL
  account points `TRMNL_API_KEY_REF` at their own item.
- Agents must not read `./.env`. It is a named pipe that streams the real
  secrets, which is also why `test -f .env` reports it missing.

## Traps found the hard way

- **TRMNL X renders at `scale_factor: 1.8`.** It reports 1872x1404; the CSS
  canvas is 1040x780. The device log names it:
  `Appearance(... scale_factor: 1.8, text_scale: large, css_size: lg)`.
  Read it with the MCP server's `IntegrationsLogsTool`.
- **`value--tnums` is tabular.** The minus sign and the decimal point each take a
  full digit width, so `-21.2` is five 0.6em glyphs, not three digits and two
  narrow marks — about 35px more than it looks at 58px. Size the hero from the
  widest reading the fleet can produce, not from a typical one.
- **`value--medium` does not exist** in the framework. The step between
  `value--small` (26px) and `value--large` (58px) is `value--base` (38px). A
  missing size class does not error; the span just renders unstyled.
- **`data-clamp` is driven by the framework's JavaScript.** Where that has not
  run, a long name wraps and costs the row beneath it. Truncate in CSS as well.
- **`trmnlp push` rewrites `src/settings.yml`,** replacing it with the server's
  canonical form: comments stripped, keys reordered, every unset `oauth_*` field
  added. Do not put anything there you need to survive a push — restore it with
  `git restore src/settings.yml` afterwards.
- **`{% endtemplate %}` must be written exactly like that.** The tag closes only
  on that literal token, so `{%- endtemplate -%}` swallows the rest of the file
  and the screen renders empty, with no error.
- **`{% render %}` gets an isolated scope** and comma-separated arguments.
  Everything the partial needs must be passed in.
- **`trmnlp lint` counts CSS property names as text.** `limited_inline_styles`
  allows six occurrences of `padding`, `justify-content`, `border-radius` and
  friends across all markup, and `no_opacity` rejects opacity outright, because
  it dithers badly on e-ink. Use framework classes and the budget is never near.
- **`trmnlp lint` does not read `transform.js`.** Its custom-fields check scans
  only the markup and the polling settings, so a field consumed solely by the
  transform is reported as unused. `shared.liquid` carries a comment naming
  those fields, which documents them and satisfies the check.
