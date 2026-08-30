/**
 * Ruuvi Cloud -> TRMNL merge data.
 *
 * Runs on TRMNL Serverless (node) over the polled response of
 *   GET https://network.ruuvi.com/sensors-dense?measurements=true&alerts=true
 *
 * Ruuvi Cloud hands back the whole BLE advertisement as a hex string, so the
 * decoding a self-hosted backend would do has to happen here instead. Ported
 * from the Laravel app this recipe replaces:
 *   app/Services/Ruuvi/{BleAdvertisement,Rawv2Decoder,AirDecoder,RuuviService}.php
 *
 * Deliberately narrower than the original: acceleration, TX power, movement
 * and the measurement sequence are all dropped. Sequence numbers only existed
 * to dedupe rows in SQLite, and there is no database here — every render reads
 * whatever Ruuvi reports right now.
 */

// Ruuvi Innovations Ltd, assigned by the Bluetooth SIG. Little-endian in the
// advertisement: the bytes 0x99 0x04.
const RUUVI_MANUFACTURER_ID = [0x99, 0x04];
const AD_TYPE_MANUFACTURER_SPECIFIC = 0xff;

const FORMAT_RAWV2 = 0x05;
const FORMAT_AIR = 0xe1;

// Per the Rawv2 spec, a field is "not available" at the extreme of its range.
const INVALID_INT16 = -32768; // 0x8000
const INVALID_UINT16 = 0xffff;
const INVALID_BATTERY_RAW = 2047; // 11-bit max

const DEFAULT_STALE_AFTER_MINUTES = 30;

/* ------------------------------------------------------------------ bytes */

function hexToBytes(hex) {
  if (typeof hex !== 'string' || hex.length === 0 || hex.length % 2 !== 0) return null;
  if (!/^[0-9a-fA-F]+$/.test(hex)) return null;

  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) {
    out[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
  }
  return out;
}

function uint16BE(b, o) {
  return (b[o] << 8) | b[o + 1];
}

function int16BE(b, o) {
  const u = uint16BE(b, o);
  return u >= 0x8000 ? u - 0x10000 : u;
}

function round(value, places) {
  const factor = Math.pow(10, places);
  return Math.round(value * factor) / factor;
}

/* ------------------------------------------------------------ advertising */

/**
 * Pull the Ruuvi sensor payload out of a BLE advertisement — a sequence of
 * AD structures, each [length][type][data...], where length covers type+data.
 * The returned bytes start with the format byte (0x05 Rawv2, 0xE1 Air).
 */
function extractRuuviPayload(hex) {
  const bytes = hexToBytes(hex);
  if (!bytes) return null;

  let i = 0;
  while (i < bytes.length) {
    const adLen = bytes[i];
    if (adLen === 0 || i + 1 + adLen > bytes.length) return null;

    const adType = bytes[i + 1];
    const isRuuvi =
      adType === AD_TYPE_MANUFACTURER_SPECIFIC &&
      adLen >= 3 &&
      bytes[i + 2] === RUUVI_MANUFACTURER_ID[0] &&
      bytes[i + 3] === RUUVI_MANUFACTURER_ID[1];

    if (isRuuvi) return bytes.slice(i + 4, i + 1 + adLen);

    i += 1 + adLen;
  }
  return null;
}

/* -------------------------------------------------------------- decoding */

/**
 * Data Format 5 (Rawv2), 24 bytes.
 * https://docs.ruuvi.com/communication/bluetooth-advertisements/data-format-5-rawv2
 */
function decodeRawv2(b) {
  if (b.length < 24) return null;

  const tempRaw = int16BE(b, 1);
  const humRaw = uint16BE(b, 3);
  const pressRaw = uint16BE(b, 5);

  // Offset 13-14 packs 11 bits of battery (mV above 1600) and 5 of TX power.
  const batteryRaw = (uint16BE(b, 13) >> 5) & 0x7ff;

  return {
    temperature: tempRaw === INVALID_INT16 ? null : round(tempRaw * 0.005, 3),
    humidity: humRaw === INVALID_UINT16 ? null : round(humRaw * 0.0025, 4),
    pressure: pressRaw === INVALID_UINT16 ? null : pressRaw + 50000,
    batteryMv: batteryRaw === INVALID_BATTERY_RAW ? null : 1600 + batteryRaw,
  };
}

/**
 * Data Format E1 (Ruuvi Air), 40 bytes. Shares its first three fields with
 * Rawv2 and then carries air quality data the display does not use. Battery
 * is not broadcast in E1 at all, so it stays null and reads as "unknown".
 *
 * The full 40-byte length is required rather than just the seven bytes read
 * below, so a truncated or mislabelled frame is rejected instead of decoded.
 */
function decodeAir(b) {
  if (b.length < 40) return null;

  const tempRaw = int16BE(b, 1);
  const humRaw = uint16BE(b, 3);
  const pressRaw = uint16BE(b, 5);

  return {
    temperature: tempRaw === INVALID_INT16 ? null : round(tempRaw * 0.005, 3),
    humidity: humRaw === INVALID_UINT16 ? null : round(humRaw * 0.0025, 4),
    pressure: pressRaw === INVALID_UINT16 ? null : pressRaw + 50000,
    batteryMv: null,
  };
}

/** Dispatch on the format byte. Unknown formats are skipped, not guessed at. */
function decodeAdvertisement(hex) {
  const payload = extractRuuviPayload(hex);
  if (!payload || payload.length === 0) return null;

  switch (payload[0]) {
    case FORMAT_RAWV2:
      return decodeRawv2(payload);
    case FORMAT_AIR:
      return decodeAir(payload);
    default:
      return null;
  }
}

/* ---------------------------------------------------------------- alerts */

/**
 * Type of the first triggered, enabled alert the display cares about.
 * Battery alerts surface separately, through battery_low.
 *
 * Reading Ruuvi's own alert state rather than comparing thresholds here means
 * the delay and hysteresis configured in the Ruuvi app are respected.
 */
function firstTriggeredAlarm(raw) {
  for (const alert of raw.alerts || []) {
    if (!alert.enabled || !alert.triggered) continue;
    if (alert.type === 'temperature' || alert.type === 'humidity') return alert.type;
  }
  return null;
}

function isBatteryLow(raw) {
  for (const alert of raw.alerts || []) {
    if (alert.type === 'battery' && alert.enabled && alert.triggered) return true;
  }
  return false;
}

/**
 * Bucket the cell voltage. Thresholds for a CR2477: fresh is about 3000 mV,
 * and the under-load discharge knee sits around 2500 mV. Air sensors report
 * no voltage at all, so they land on 'unknown' rather than a guessed state.
 */
function bucketBatteryLevel(batteryMv) {
  if (batteryMv === null || batteryMv === undefined) return 'unknown';
  if (batteryMv >= 2900) return 'full';
  if (batteryMv >= 2500) return 'medium';
  return 'low';
}

/**
 * Visual cue for the reading, picked here rather than in Liquid so the
 * templates stay free of threshold logic:
 *   at or below freezing -> snowflake, above 20 C -> sun, otherwise plain.
 */
function temperatureIcon(temperature) {
  if (temperature === null || temperature === undefined) return 'thermometer';
  if (temperature <= 0) return 'thermometer-snowflake';
  if (temperature > 20) return 'thermometer-sun';
  return 'thermometer';
}

function batteryIcon(level) {
  switch (level) {
    case 'full':
      return 'battery-full';
    case 'medium':
      return 'battery-medium';
    case 'low':
      return 'battery-low';
    default:
      // An empty frame reads as "no reading" rather than overstating the
      // battery as either healthy or dead. Ruuvi Air never reports voltage.
      return 'battery';
  }
}

/* ------------------------------------------------------------------ time */

function pad2(n) {
  return String(n).padStart(2, '0');
}

/**
 * Break a moment into calendar parts in the viewer's zone.
 *
 * Intl is the accurate path, but the sandbox is not guaranteed to carry a full
 * ICU dataset, so a fixed-offset fallback keeps the clock from silently
 * reverting to UTC. The fallback is wrong across a DST boundary by an hour
 * until TRMNL refreshes utc_offset; Intl handles that correctly.
 */
function zonedParts(date, timeZone, utcOffsetSeconds) {
  try {
    const formatter = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    });

    const parts = {};
    for (const { type, value } of formatter.formatToParts(date)) parts[type] = value;

    // Some ICU builds render midnight as hour 24 under hour12: false.
    if (parts.hour === '24') parts.hour = '00';
    if (parts.year && parts.hour) return parts;
  } catch (e) {
    // No ICU, or a zone name it does not know — fall through.
  }

  const shifted = new Date(date.getTime() + utcOffsetSeconds * 1000);
  return {
    year: String(shifted.getUTCFullYear()),
    month: pad2(shifted.getUTCMonth() + 1),
    day: pad2(shifted.getUTCDate()),
    hour: pad2(shifted.getUTCHours()),
    minute: pad2(shifted.getUTCMinutes()),
  };
}

function formatClock(p) {
  return `${p.hour}:${p.minute}`;
}

function formatStamp(p) {
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}`;
}

/* -------------------------------------------------------------- ordering */

/** Compare loosely: names are case-insensitive, MACs ignore separators. */
function matchKeys(sensor) {
  return [
    String(sensor.name || '').trim().toLowerCase(),
    String(sensor.mac || '').replace(/[^0-9a-z]/gi, '').toLowerCase(),
  ];
}

/**
 * Order sensors by the installer's priority list.
 *
 * The list is a priority order, not a filter: sensors it does not name still
 * appear, after the ones it does. That is what makes one field serve every
 * layout — the quadrant shows the first, a half shows the first few, and the
 * full screen shows everything.
 */
function resolveFeatured(sensors, spec) {
  const wanted = String(spec || '')
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s.length > 0);

  if (wanted.length === 0) return { featured: sensors.slice(), unmatched: [] };

  const featured = [];
  const unmatched = [];
  const taken = new Set();

  for (const want of wanted) {
    const key = want.toLowerCase();
    const macKey = want.replace(/[^0-9a-z]/gi, '').toLowerCase();

    const idx = sensors.findIndex((sensor, i) => {
      if (taken.has(i)) return false;
      const [nameKey, sensorMacKey] = matchKeys(sensor);
      return nameKey === key || (macKey.length > 0 && sensorMacKey === macKey);
    });

    if (idx === -1) {
      unmatched.push(want);
      continue;
    }

    taken.add(idx);
    featured.push(sensors[idx]);
  }

  sensors.forEach((sensor, i) => {
    if (!taken.has(i)) featured.push(sensor);
  });

  return { featured, unmatched };
}

/* ----------------------------------------------------------------- input */

/**
 * Ruuvi answers { result, data: { sensors: [...] } }. TRMNL passes a parsed
 * object body straight through but wraps a bare array under `data`, so probe
 * both shapes rather than assume one. A failed poll arrives as {}, which
 * falls through to null and becomes an on-screen fault.
 */
function pickSensors(input) {
  if (input && input.data && Array.isArray(input.data.sensors)) return input.data.sensors;
  if (input && Array.isArray(input.sensors)) return input.sensors;
  return null;
}

function positiveInt(value, fallback) {
  const n = parseInt(value, 10);
  return Number.isFinite(n) && n > 0 ? n : fallback;
}

/* ------------------------------------------------------------------- run */

function run(input) {
  const trmnl = (input && input.trmnl) || {};
  const user = trmnl.user || {};
  const fields = (trmnl.plugin_settings || {}).custom_fields_values || {};

  const timeZone = user.time_zone_iana || user.time_zone || 'UTC';
  const utcOffset = Number(user.utc_offset) || 0;
  const staleAfter = positiveInt(fields.stale_after_minutes, DEFAULT_STALE_AFTER_MINUTES) * 60;

  const now = new Date();
  const nowSeconds = Math.floor(now.getTime() / 1000);

  const apiSensors = pickSensors(input);

  // The device has no logs to check, so a broken setup has to name itself on
  // the screen. Silence would just look like a plugin that never loaded.
  let fault = '';
  if (apiSensors === null) {
    fault = 'Cannot reach Ruuvi Cloud. Check the API token in this plugin’s settings.';
  } else if (apiSensors.length === 0) {
    fault = 'No sensors in this Ruuvi account yet.';
  }

  const sensors = [];
  for (const raw of apiSensors || []) {
    const mac = String(raw.sensor || '').toUpperCase();
    if (mac === '') continue;

    const name = raw.name || mac;
    const measurement = (raw.measurements || [])[0];
    const reading = measurement ? decodeAdvertisement(measurement.data) : null;

    // Temperature is the primary metric. Without it there is nothing worth
    // showing, so the card drops to a placeholder. Humidity and pressure are
    // allowed to be null on their own — freezer tags report no humidity.
    if (!reading || reading.temperature === null) {
      sensors.push({
        mac,
        name,
        status: 'no_data',
        temperature: null,
        humidity: null,
        pressure_hpa: null,
        measured_at: null,
        is_stale: false,
        battery_low: isBatteryLow(raw),
        battery_level: 'unknown',
        battery_icon: batteryIcon('unknown'),
        temp_icon: temperatureIcon(null),
        alarm: firstTriggeredAlarm(raw),
      });
      continue;
    }

    const measuredAt = Number(measurement.timestamp) || 0;
    const isStale = nowSeconds - measuredAt > staleAfter;

    const batteryLevel = bucketBatteryLevel(reading.batteryMv);
    const temperature = round(reading.temperature, 1);

    sensors.push({
      mac,
      name,
      status: isStale ? 'stale' : 'ok',
      temperature,
      humidity: reading.humidity === null ? null : Math.round(reading.humidity),
      pressure_hpa: reading.pressure === null ? null : Math.round(reading.pressure / 100),
      measured_at: formatClock(zonedParts(new Date(measuredAt * 1000), timeZone, utcOffset)),
      is_stale: isStale,
      battery_low: isBatteryLow(raw),
      battery_level: batteryLevel,
      battery_icon: batteryIcon(batteryLevel),
      temp_icon: temperatureIcon(temperature),
      alarm: firstTriggeredAlarm(raw),
    });
  }

  const { featured, unmatched } = resolveFeatured(sensors, fields.featured_sensors);

  return {
    fault,
    sensors,
    featured,
    unmatched,
    // "Europe/Helsinki" -> "Helsinki", for the header band on the large layout.
    time_zone_label: timeZone.split('/').pop().replace(/_/g, ' '),
    updated_at: formatStamp(zonedParts(now, timeZone, utcOffset)),
    stats: {
      total: sensors.length,
      alarms: sensors.filter((s) => s.alarm).length,
      low_battery: sensors.filter((s) => s.battery_low).length,
      stale: sensors.filter((s) => s.is_stale).length,
    },
  };
}
