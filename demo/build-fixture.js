/**
 * Regenerates sensors-dense.json — a stand-in for the Ruuvi Cloud response.
 *
 *   node demo/build-fixture.js > demo/sensors-dense.json
 *
 * Two jobs: previewing every branch of the templates locally without a real
 * account, and serving as the demo data a published Recipe needs so the
 * marketplace can render a preview for someone who has not connected Ruuvi yet.
 *
 * Timestamps anchor to fixed dates rather than to the moment this runs. Demo
 * data is static once published, so anything relative to "now" reads as fresh
 * on the day it is generated and as hopelessly stale a fortnight later — every
 * card grey, every reading flagged. A far-future anchor is never stale, and
 * only the clock time is ever displayed, so the date itself is invisible.
 */

// Comfortably beyond any plausible staleness threshold, so these read as fresh
// no matter when the preview runs.
const FRESH = Date.UTC(2035, 5, 1, 9, 0, 0) / 1000;

// Deliberately ancient, to keep one card demonstrating the stale state.
const ANCIENT = Date.UTC(2020, 0, 15, 8, 30, 0) / 1000;

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
    ...int16(humidity === null ? 0xffff : Math.round(humidity / 0.0025)),
    ...int16(pressure === null ? 0xffff : pressure - 50000),
    ...int16(0), ...int16(0), ...int16(0),        // acceleration x/y/z
    ...int16(((batteryMv - 1600) << 5) | 22),     // battery + TX power (+4 dBm)
    0x40,                                          // movement counter
    ...int16(sequence),
    0xaa, 0xbb, 0xcc, 0xdd, 0xee, 0xff,           // MAC
  ]);
}

/** Data Format E1 (Ruuvi Air), 40 bytes. */
function air({ temperature, humidity, pressure, pm25, co2, voc, nox, sequence }) {
  const pm = (value) => int16(Math.round(value / 0.1));
  return advertisement([
    0xe1,
    ...int16(Math.round(temperature / 0.005)),
    ...int16(Math.round(humidity / 0.0025)),
    ...int16(pressure - 50000),
    ...pm(pm25 * 0.5),                     // PM1.0, always below PM2.5
    ...pm(pm25),
    ...pm(pm25 * 1.4),                     // PM4.0
    ...pm(pm25 * 1.7),                     // PM10
    ...int16(co2),
    voc & 0xff,
    nox & 0xff,
    0xff, 0xff, 0xff,                      // luminosity: not fitted on production hardware
    0xff, 0xff, 0xff,                      // reserved
    (sequence >> 16) & 0xff, (sequence >> 8) & 0xff, sequence & 0xff,
    // Reserved bits are ones, as everywhere else in this format; the two data
    // bits carry the 9th bit of VOC and NOX.
    0xfc | ((voc >> 8) & 0x01) | (((nox >> 8) & 0x01) << 1),
    0xff, 0xff, 0xff, 0xff, 0xff,          // reserved
    0xcd, 0x0d, 0x61, 0xd2, 0xed, 0x8c,    // MAC
  ]);
}

function sensor({ mac, name, hex, agoSeconds, stale = false, alerts = [] }) {
  const timestamp = stale ? ANCIENT : FRESH - agoSeconds;
  return {
    sensor: mac,
    name,
    measurements: hex === null ? [] : [{ data: hex, timestamp, rssi: -68 }],
    alerts,
  };
}

const alert = (type, triggered) => ({ type, enabled: true, triggered });

const sensors = [
  // Names are deliberately long. Real fleets use room names like
  // "Olohuoneen pakastin", not "Kitchen", and short demo names hide layout
  // problems that only appear on a real screen.
  sensor({ mac: 'CB:B8:33:4C:88:4F', name: 'Living room', agoSeconds: 90,
    hex: rawv2({ temperature: 21.4, humidity: 38, pressure: 100_930, batteryMv: 2980, sequence: 4102 }) }),
  sensor({ mac: 'D4:1A:07:9C:22:B1', name: 'Main bedroom north', agoSeconds: 140,
    hex: rawv2({ temperature: 19.2, humidity: 41, pressure: 100_925, batteryMv: 2870, sequence: 3311 }) }),
  sensor({ mac: 'E7:55:12:AF:70:3C', name: 'Outdoor north wall', agoSeconds: 210,
    hex: rawv2({ temperature: -6.5, humidity: 86, pressure: 100_640, batteryMv: 2480, sequence: 9087 }),
    alerts: [alert('battery', true)] }),
  sensor({ mac: 'A2:90:66:1D:04:E8', name: 'Sauna', agoSeconds: 320,
    hex: rawv2({ temperature: 78.5, humidity: 12, pressure: 100_910, batteryMv: 2930, sequence: 771 }),
    alerts: [alert('temperature', true)] }),
  // Freezer tags report neither humidity nor pressure, and the coldest
  // reading is the widest string the hero has to hold.
  sensor({ mac: 'F0:3B:C1:88:9A:2D', name: 'Living room freezer', agoSeconds: 260,
    hex: rawv2({ temperature: -22.6, humidity: null, pressure: null, batteryMv: 2620, sequence: 5540 }) }),
  sensor({ mac: '9C:44:D0:2E:61:07', name: 'Study (Ruuvi Air)', agoSeconds: 175,
    hex: air({ temperature: 22.8, humidity: 44, pressure: 100_940,
               pm25: 3.2, co2: 842, voc: 96, nox: 1, sequence: 663451 }) }),
  sensor({ mac: 'B5:12:88:70:3F:44', name: 'Garage workbench', stale: true, agoSeconds: 0,
    hex: rawv2({ temperature: 4.1, humidity: 71, pressure: 100_880, batteryMv: 2510, sequence: 118 }) }),
  sensor({ mac: '77:0C:19:B4:52:8A', name: 'Attic hatch', agoSeconds: 0, hex: null }),
  sensor({ mac: '31:A0:5C:6D:11:92', name: 'Kitchen worktop', agoSeconds: 120,
    hex: rawv2({ temperature: 23.1, humidity: 39, pressure: 100_935, batteryMv: 2950, sequence: 2201 }) }),
  sensor({ mac: '4E:77:2B:09:C4:6F', name: 'Upstairs hallway', agoSeconds: 165,
    hex: rawv2({ temperature: 20.7, humidity: 43, pressure: 100_930, batteryMv: 2760, sequence: 8812 }) }),
  sensor({ mac: '6A:31:F8:5E:2C:70', name: "Children's room", agoSeconds: 200,
    hex: rawv2({ temperature: 20.1, humidity: 45, pressure: 100_928, batteryMv: 2840, sequence: 1907 }) }),
  sensor({ mac: '8D:62:04:11:7B:33', name: 'Basement storage', agoSeconds: 240,
    hex: rawv2({ temperature: 13.4, humidity: 68, pressure: 100_950, batteryMv: 2530, sequence: 660 }) }),
  sensor({ mac: '2F:98:A3:47:D0:15', name: 'Utility room', agoSeconds: 190,
    hex: rawv2({ temperature: 18.9, humidity: 52, pressure: 100_933, batteryMv: 2900, sequence: 4455 }) }),
  sensor({ mac: 'C3:0B:7E:22:98:AC', name: 'Greenhouse', agoSeconds: 150,
    hex: rawv2({ temperature: 27.3, humidity: 74, pressure: 100_915, batteryMv: 2690, sequence: 3120 }) }),
  sensor({ mac: '5B:41:D9:63:0A:27', name: 'Wine cellar', agoSeconds: 280,
    hex: rawv2({ temperature: 12.2, humidity: 63, pressure: 100_948, batteryMv: 2810, sequence: 990 }) }),
  sensor({ mac: 'A9:17:6C:35:B2:5E', name: 'Guest bedroom east', agoSeconds: 210,
    hex: rawv2({ temperature: 19.8, humidity: 42, pressure: 100_929, batteryMv: 2880, sequence: 7001 }) }),
  sensor({ mac: '73:CE:20:88:41:D6', name: 'Boiler room', agoSeconds: 130,
    hex: rawv2({ temperature: 31.6, humidity: 28, pressure: 100_940, batteryMv: 2960, sequence: 5321 }) }),
  sensor({ mac: '18:D4:B7:5A:66:39', name: 'Balcony', agoSeconds: 230,
    hex: rawv2({ temperature: -3.9, humidity: 81, pressure: 100_650, batteryMv: 2470, sequence: 2740 }) }),
  sensor({ mac: 'E0:8A:35:C1:79:B4', name: 'Workshop', agoSeconds: 195,
    hex: rawv2({ temperature: 16.5, humidity: 55, pressure: 100_922, batteryMv: 2720, sequence: 1580 }) }),
];

process.stdout.write(JSON.stringify({ result: 'success', data: { sensors } }, null, 2) + '\n');
