/**
 * Regenerates sensors-dense.json — a stand-in for the Ruuvi Cloud response.
 *
 *   node demo/build-fixture.js > demo/sensors-dense.json
 *
 * Two jobs: previewing every branch of the templates locally without a real
 * account, and serving as the demo data a published Recipe needs so the
 * marketplace can render a preview for someone who has not connected Ruuvi yet.
 *
 * Timestamps are written relative to the moment this runs, so regenerate
 * before publishing — a frozen fixture eventually renders as all-stale.
 */

const NOW = Math.floor(Date.now() / 1000);

function bytesToHex(bytes) {
  return bytes.map((b) => b.toString(16).padStart(2, '0')).join('').toUpperCase();
}

function int16(value) {
  const v = value < 0 ? value + 0x10000 : value;
  return [(v >> 8) & 0xff, v & 0xff];
}

/** Wrap a sensor payload in the AD structures Ruuvi Cloud reports verbatim. */
function advertisement(payload) {
  const flags = [0x02, 0x01, 0x06];
  const manufacturer = [payload.length + 3, 0xff, 0x99, 0x04, ...payload];
  return bytesToHex([...flags, ...manufacturer]);
}

/** Data Format 5 (Rawv2), 24 bytes. */
function rawv2({ temperature, humidity, pressure, batteryMv, sequence }) {
  return advertisement([
    0x05,
    ...int16(Math.round(temperature / 0.005)),
    ...int16(Math.round(humidity / 0.0025)),
    ...int16(pressure - 50000),
    ...int16(0), ...int16(0), ...int16(0),        // acceleration x/y/z
    ...int16(((batteryMv - 1600) << 5) | 22),     // battery + TX power (+4 dBm)
    0x40,                                          // movement counter
    ...int16(sequence),
    0xaa, 0xbb, 0xcc, 0xdd, 0xee, 0xff,           // MAC
  ]);
}

/** Data Format E1 (Ruuvi Air), 40 bytes. Air quality bytes left as filler. */
function air({ temperature, humidity, pressure }) {
  const head = [
    0xe1,
    ...int16(Math.round(temperature / 0.005)),
    ...int16(Math.round(humidity / 0.0025)),
    ...int16(pressure - 50000),
  ];
  return advertisement([...head, ...new Array(40 - head.length).fill(0x00)]);
}

function sensor({ mac, name, hex, agoSeconds, alerts = [] }) {
  return {
    sensor: mac,
    name,
    measurements: hex === null ? [] : [{ data: hex, timestamp: NOW - agoSeconds, rssi: -68 }],
    alerts,
  };
}

const alert = (type, triggered) => ({ type, enabled: true, triggered });

const sensors = [
  sensor({
    mac: 'CB:B8:33:4C:88:4F', name: 'Living room', agoSeconds: 90,
    hex: rawv2({ temperature: 21.4, humidity: 38, pressure: 100_930, batteryMv: 2980, sequence: 4102 }),
  }),
  sensor({
    mac: 'D4:1A:07:9C:22:B1', name: 'Bedroom', agoSeconds: 140,
    hex: rawv2({ temperature: 19.2, humidity: 41, pressure: 100_925, batteryMv: 2870, sequence: 3311 }),
  }),
  sensor({
    mac: 'E7:55:12:AF:70:3C', name: 'Outdoor', agoSeconds: 210,
    hex: rawv2({ temperature: -6.5, humidity: 86, pressure: 100_640, batteryMv: 2480, sequence: 9087 }),
    alerts: [alert('battery', true)],
  }),
  sensor({
    mac: 'A2:90:66:1D:04:E8', name: 'Sauna', agoSeconds: 320,
    hex: rawv2({ temperature: 78.5, humidity: 12, pressure: 100_910, batteryMv: 2930, sequence: 771 }),
    alerts: [alert('temperature', true)],
  }),
  sensor({
    mac: 'F0:3B:C1:88:9A:2D', name: 'Freezer', agoSeconds: 260,
    hex: rawv2({ temperature: -19.8, humidity: 62, pressure: 100_900, batteryMv: 2620, sequence: 5540 }),
  }),
  sensor({
    mac: '9C:44:D0:2E:61:07', name: 'Study (Air)', agoSeconds: 175,
    hex: air({ temperature: 22.8, humidity: 44, pressure: 100_940 }),
  }),
  // Last heard from two hours ago — exercises the stale branch.
  sensor({
    mac: 'B5:12:88:70:3F:44', name: 'Garage', agoSeconds: 7200,
    hex: rawv2({ temperature: 4.1, humidity: 71, pressure: 100_880, batteryMv: 2510, sequence: 118 }),
  }),
  // Claimed but never reported — exercises the no-data branch.
  sensor({ mac: '77:0C:19:B4:52:8A', name: 'Attic', agoSeconds: 0, hex: null }),
];

process.stdout.write(JSON.stringify({ result: 'success', data: { sensors } }, null, 2) + '\n');
