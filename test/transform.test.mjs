/**
 * Spec-vector regression for src/transform.js.
 *
 *   node --test test/*.test.mjs
 *
 * The bit-level decoding is the most failure-prone part of this plugin and the
 * least visible when it breaks: a wrong shift or a missed sentinel produces a
 * plausible number, not an error, and the screen shows it without complaint.
 *
 * The .mjs extension is deliberate: it fixes the module type regardless of
 * whether a package.json sits above this directory.
 *
 * Vectors are the ones the Laravel app was tested against, taken from the
 * official Ruuvi spec:
 * https://docs.ruuvi.com/communication/bluetooth-advertisements/data-format-5-rawv2
 *
 * If a vector starts failing, the spec changed. Check the docs page before
 * touching the decoder.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Load the transform's internals without editing it.
 *
 * TRMNL's runtime expects a bare `run(input)` and nothing else, so the file
 * carries no exports. Wrapping it in a Function gives the tests its top-level
 * declarations while leaving the deployed source byte-for-byte what runs on
 * the server.
 */
function loadTransform() {
  const source = fs.readFileSync(fileURLToPath(new URL('../src/transform.js', import.meta.url)), 'utf8');
  const names = [
    'extractRuuviPayload', 'decodeRawv2', 'decodeAir', 'decodeAdvertisement',
    'bucketBatteryLevel', 'resolveFeatured', 'temperatureIcon', 'batteryIcon', 'run',
  ];
  return new Function(`${source}\nreturn { ${names.join(', ')} };`)();
}

const T = loadTransform();

const hex = (bytes) => Buffer.from(bytes).toString('hex').toUpperCase();
const close = (actual, expected, delta = 0.001) =>
  assert.ok(Math.abs(actual - expected) <= delta, `expected ${actual} within ${delta} of ${expected}`);

/* ------------------------------------------------- Rawv2 (data format 5) */

test('spec case 1: valid data', () => {
  const r = T.decodeRawv2(Buffer.from('0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F', 'hex'));
  close(r.temperature, 24.3);
  close(r.humidity, 53.49);
  assert.equal(r.pressure, 100044);
  assert.equal(r.batteryMv, 2977);
});

test('spec case 2: maximum values', () => {
  const r = T.decodeRawv2(Buffer.from('057FFFFFFEFFFE7FFF7FFF7FFFFFDEFEFFFECBB8334C884F', 'hex'));
  close(r.temperature, 163.835);
  close(r.humidity, 163.835);
  assert.equal(r.pressure, 115534);
  assert.equal(r.batteryMv, 3646);
});

test('spec case 3: minimum values', () => {
  const r = T.decodeRawv2(Buffer.from('058001000000008001800180010000000000CBB8334C884F', 'hex'));
  close(r.temperature, -163.835);
  close(r.humidity, 0.0);
  assert.equal(r.pressure, 50000);
  assert.equal(r.batteryMv, 1600);
});

test('spec case 4: sentinels decode to null, never a phantom value', () => {
  const r = T.decodeRawv2(Buffer.from('058000FFFFFFFF800080008000FFFFFFFFFFFFFFFFFFFFFF', 'hex'));
  assert.equal(r.temperature, null, 'must not report 163.84 C');
  assert.equal(r.humidity, null);
  assert.equal(r.pressure, null);
  assert.equal(r.batteryMv, null, 'must not report 3647 mV');
});

test('a payload shorter than 24 bytes is rejected, not partially decoded', () => {
  assert.equal(T.decodeRawv2(Buffer.from('05000000000000000000', 'hex')), null);
});

test('trailing bytes past the payload are ignored', () => {
  const r = T.decodeRawv2(Buffer.from('0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884FDEADBEEF', 'hex'));
  close(r.temperature, 24.3);
});

/* --------------------------------------------------- Air (data format E1) */

// Captured from a live Ruuvi Air sensor; only the BLE wrapper is stripped.
const AIR = 'E1104E4B70BD8500040008000C000E02C63700FFFFFFFFFFFF0A1F9BFCFFFFFFFFFFCD0D61D2ED8C';

test('decodes a captured Air payload', () => {
  const r = T.decodeAir(Buffer.from(AIR, 'hex'));
  close(r.temperature, 20.87);
  close(r.humidity, 48.28);
  assert.equal(r.pressure, 98517);
});

test('decodes the air quality fields from a captured payload', () => {
  const r = T.decodeAir(Buffer.from(AIR, 'hex'));
  close(r.pm25, 0.8, 0.05);
  assert.equal(r.co2, 710);
});

test('the flags bit is the LEAST significant bit of VOC and NOX', () => {
  // Verified against two live sensors read at the same moment as the Ruuvi
  // app: byte17=44 with flags 0xBC gave 88, byte17=73 with flags 0xFC gave
  // 147. Treating the bit as the MSB halves the value, and that error is
  // invisible while a sensor sits below 256 — which both did.
  const r = T.decodeAir(Buffer.from(AIR, 'hex'));
  assert.equal(r.voc, 111, 'byte 0x37 = 55, flags 0xFC has b6 set: (55 << 1) | 1');
  assert.equal(r.nox, 1, 'byte 0x00, flags 0xFC has b7 set: (0 << 1) | 1');
});

test('the discriminating case: the same byte with the flag bit clear', () => {
  // Makuuhuone Air, whose flags byte is 0xBC. b6 clear is what separates this
  // layout from every other candidate; with b6 set it would read 89.
  const hex = 'e10f6a63d0c201000200050007000801e42c00ffffffffffff9ca79dbcffffffffffcd0d61d2ed8c';
  const r = T.decodeAir(Buffer.from(hex, 'hex'));
  assert.equal(r.voc, 88);
  assert.equal(r.nox, 1);
  assert.equal(r.co2, 484);
  close(r.pm25, 0.5, 0.05);
});

test('a RuuviTag reports no air quality fields at all', () => {
  // Rather than absent keys: every card has the same shape, and null is what
  // "this tag cannot measure it" means.
  const r = T.decodeRawv2(Buffer.from('0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F', 'hex'));
  assert.equal(r.co2, null);
  assert.equal(r.pm25, null);
  assert.equal(r.voc, null);
  assert.equal(r.nox, null);
});

test('Air never reports a battery voltage', () => {
  // E1 does not broadcast one. Guessing "full" here would hide a dying tag.
  assert.equal(T.decodeAir(Buffer.from(AIR, 'hex')).batteryMv, null);
});

test('Air sentinels decode to null', () => {
  // Flags 0xFF as well, so the 9th bits are set: VOC and NOX only reach their
  // 0x1FF sentinel when the flag bit joins a 0xFF low byte. With flags 0x00
  // the same low bytes decode to a legitimate 255.
  const h = 'E1' + '8000' + 'FFFF' + 'FFFF' + 'FF'.repeat(18) + 'FFFFFF' + 'FF' + '00'.repeat(11);
  const r = T.decodeAir(Buffer.from(h, 'hex'));
  assert.equal(r.temperature, null);
  assert.equal(r.humidity, null);
  assert.equal(r.pressure, null);
  assert.equal(r.pm25, null);
  assert.equal(r.co2, null);
  assert.equal(r.voc, null);
  assert.equal(r.nox, null);
});

test('a 0xFF byte without its flag bit is 510, not unavailable', () => {
  // The sentinel is the full 9-bit 0x1FF, which needs the flag bit too.
  // Treating the byte alone as "not available" would drop a real reading.
  const h = 'E1' + '8000' + 'FFFF' + 'FFFF' + 'FF'.repeat(18) + 'FFFFFF' + '00' + '00'.repeat(11);
  const r = T.decodeAir(Buffer.from(h, 'hex'));
  assert.equal(r.voc, 510);
  assert.equal(r.nox, 510);
});

test('an Air payload shorter than 40 bytes is rejected', () => {
  assert.equal(T.decodeAir(Buffer.from('E1' + '00'.repeat(30), 'hex')), null);
});

/* ------------------------------------------------- advertisement unwrapping */

test('extracts the Rawv2 payload from a wrapped advertisement', () => {
  //   02 01 06            Flags AD
  //   1B FF 9904          Manufacturer-Specific AD (Ruuvi)
  //   05 ... 24 bytes     Rawv2 payload
  const payload = T.extractRuuviPayload('0201061BFF99040512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F');
  assert.equal(hex(payload), '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F');
});

test('extracts an Air payload from a single-AD advertisement', () => {
  const payload = T.extractRuuviPayload('2BFF9904' + AIR + '030398FC');
  assert.equal(payload[0], 0xe1);
});

test('rejects advertisements that are not Ruuvi', () => {
  assert.equal(T.extractRuuviPayload('020106'), null, 'flags only');
  assert.equal(T.extractRuuviPayload('04FF4C0001'), null, 'Apple manufacturer id');
  assert.equal(T.extractRuuviPayload('zzzz'), null, 'not hex');
  assert.equal(T.extractRuuviPayload('0AFF99'), null, 'AD length runs past the buffer');
  assert.equal(T.extractRuuviPayload(''), null, 'empty');
});

test('an unknown format byte is skipped rather than guessed at', () => {
  // 0x03 is the deprecated RAWv1.
  assert.equal(T.decodeAdvertisement('0201061BFF9904' + '03' + '00'.repeat(23)), null);
});

/* ---------------------------------------------------------------- battery */

test('battery buckets follow the CR2477 discharge knee', () => {
  assert.equal(T.bucketBatteryLevel(3000), 'full');
  assert.equal(T.bucketBatteryLevel(2900), 'full');
  assert.equal(T.bucketBatteryLevel(2899), 'medium');
  assert.equal(T.bucketBatteryLevel(2500), 'medium');
  assert.equal(T.bucketBatteryLevel(2499), 'low');
  assert.equal(T.bucketBatteryLevel(null), 'unknown', 'never guess a level we cannot read');
});

/* --------------------------------------------------------- icon selection */

test('temperature icon changes at freezing and at 20 C', () => {
  assert.equal(T.temperatureIcon(-0.1), 'thermometer-snowflake');
  assert.equal(T.temperatureIcon(0), 'thermometer-snowflake');
  assert.equal(T.temperatureIcon(0.1), 'thermometer');
  assert.equal(T.temperatureIcon(20), 'thermometer');
  assert.equal(T.temperatureIcon(20.1), 'thermometer-sun');
  assert.equal(T.temperatureIcon(null), 'thermometer');
});

test('battery icon follows the bucket', () => {
  assert.equal(T.batteryIcon('full'), 'battery-full');
  assert.equal(T.batteryIcon('medium'), 'battery-medium');
  assert.equal(T.batteryIcon('low'), 'battery-low');
  assert.equal(T.batteryIcon('unknown'), 'battery');
});

/* ------------------------------------------------------- priority ordering */

const sensors = [
  { name: 'Living room', mac: 'AA:BB:CC:DD:EE:01' },
  { name: 'Sauna', mac: 'AA:BB:CC:DD:EE:02' },
  { name: 'Outdoor', mac: 'AA:BB:CC:DD:EE:03' },
];

test('an empty priority list keeps Ruuvi order', () => {
  const { featured, unmatched } = T.resolveFeatured(sensors, '');
  assert.deepEqual(featured.map((s) => s.name), ['Living room', 'Sauna', 'Outdoor']);
  assert.deepEqual(unmatched, []);
});

test('named sensors come first, in the order given', () => {
  const { featured } = T.resolveFeatured(sensors, 'Outdoor, Sauna');
  assert.deepEqual(featured.map((s) => s.name), ['Outdoor', 'Sauna', 'Living room']);
});

test('the list orders rather than filters — unnamed sensors still appear', () => {
  const { featured } = T.resolveFeatured(sensors, 'Sauna');
  assert.equal(featured.length, 3);
  assert.equal(featured[0].name, 'Sauna');
});

test('matching is case-insensitive and accepts a MAC with or without separators', () => {
  const { featured, unmatched } = T.resolveFeatured(sensors, 'sauna, AABBCCDDEE03');
  assert.deepEqual(featured.slice(0, 2).map((s) => s.name), ['Sauna', 'Outdoor']);
  assert.deepEqual(unmatched, []);
});

test('a name matching nothing is reported, not silently dropped', () => {
  const { featured, unmatched } = T.resolveFeatured(sensors, 'Kitchn, Sauna');
  assert.deepEqual(unmatched, ['Kitchn']);
  assert.equal(featured[0].name, 'Sauna');
});

/* ------------------------------------------------------------ run(input) */

const WRAPPED = '0201061BFF99040512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';

function input(overrides = {}) {
  const now = Math.floor(Date.now() / 1000);
  return {
    result: 'success',
    data: {
      sensors: [{
        sensor: 'AA:BB:CC:DD:EE:FF',
        name: 'Living room',
        measurements: [{ data: WRAPPED, timestamp: now - 30 }],
        alerts: [],
      }],
    },
    trmnl: {
      user: { time_zone_iana: 'Europe/Helsinki', utc_offset: 10800 },
      plugin_settings: { custom_fields_values: { stale_after_minutes: '30' } },
    },
    ...overrides,
  };
}

test('a fresh reading comes through end to end', () => {
  const out = T.run(input());
  assert.equal(out.fault, '');
  assert.equal(out.stats.total, 1);
  const s = out.sensors[0];
  assert.equal(s.name, 'Living room');
  assert.equal(s.temperature, 24.3);
  assert.equal(s.humidity, 53);
  assert.equal(s.pressure_hpa, 1000);
  assert.equal(s.status, 'ok');
  assert.equal(s.is_stale, false);
});

test('a failed poll names itself on screen rather than rendering blank', () => {
  // The device has no logs to read, so silence would look like a dead plugin.
  assert.match(T.run({}).fault, /Cannot reach Ruuvi Cloud/);
  assert.deepEqual(T.run({}).sensors, []);
});

test('an account with no sensors says so', () => {
  const out = T.run(input({ data: { sensors: [] } }));
  assert.match(out.fault, /No sensors/);
});

test('a reading older than the threshold is marked stale', () => {
  const i = input();
  i.data.sensors[0].measurements[0].timestamp = Math.floor(Date.now() / 1000) - 3600;
  const out = T.run(i);
  assert.equal(out.sensors[0].is_stale, true);
  assert.equal(out.sensors[0].status, 'stale');
  assert.equal(out.stats.stale, 1);
});

test('an undecodable measurement becomes no_data, not a wrong number', () => {
  const i = input();
  i.data.sensors[0].measurements[0].data = 'deadbeef';
  const out = T.run(i);
  assert.equal(out.sensors[0].status, 'no_data');
  assert.equal(out.sensors[0].temperature, null);
});

test('Ruuvi alert state drives the alarm and low-battery flags', () => {
  // Read from Ruuvi rather than compared here, so the delay and hysteresis
  // configured in the Ruuvi app are respected.
  const i = input();
  i.data.sensors[0].alerts = [
    { type: 'temperature', enabled: true, triggered: true },
    { type: 'battery', enabled: true, triggered: true },
  ];
  const out = T.run(i);
  assert.equal(out.sensors[0].alarm, 'temperature');
  assert.equal(out.sensors[0].battery_low, true);
  assert.equal(out.stats.alarms, 1);
  assert.equal(out.stats.low_battery, 1);
});

test('an alert that is enabled but not triggered raises nothing', () => {
  const i = input();
  i.data.sensors[0].alerts = [{ type: 'temperature', enabled: true, triggered: false }];
  assert.equal(T.run(i).sensors[0].alarm, null);
});

test('timestamps render in the viewer time zone', () => {
  const i = input();
  i.data.sensors[0].measurements[0].timestamp = 1788084413; // 2026-04-28T18:06:53Z
  const helsinki = T.run(i).sensors[0].measured_at;
  i.trmnl.user = { time_zone_iana: 'UTC', utc_offset: 0 };
  const utc = T.run(i).sensors[0].measured_at;
  assert.notEqual(helsinki, utc, 'the clock must follow the viewer, not the server');
});
