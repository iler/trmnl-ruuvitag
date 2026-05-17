<?php

use App\Services\Ruuvi\BleAdvertisement;

it('extracts the Rawv2 payload from a wrapped advertisement', function () {
    // Real example from /sensors-dense:
    //   02 01 06              Flags AD
    //   1B FF 9904            Manufacturer-Specific AD (Ruuvi, length 0x1B)
    //   05 ... 24 bytes ...   Rawv2 payload (format 0x05)
    $hex = '0201061BFF99040512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';

    expect(strtoupper((string) BleAdvertisement::extractRuuviPayload($hex)))
        ->toBe('0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F');
});

it('extracts the Air payload (format 0xE1) from a single-AD advertisement', function () {
    // 2B FF 9904 E1 ... — Ruuvi Air sensors use a single, longer
    // Manufacturer-Specific AD with format byte 0xE1.
    $hex = '2BFF9904E1104E4B70BD8500040008000C000E02C63700FFFFFFFFFFFF0A1F9BFCFFFFFFFFFFCD0D61D2ED8C030398FC';

    $payload = BleAdvertisement::extractRuuviPayload($hex);

    expect($payload)->not->toBeNull();
    expect(strtoupper(substr($payload, 0, 2)))->toBe('E1');
});

it('returns null when no manufacturer-specific AD is present', function () {
    // 02 01 06 — just a Flags AD, nothing else
    expect(BleAdvertisement::extractRuuviPayload('020106'))->toBeNull();
});

it('returns null when the manufacturer ID is not Ruuvi', function () {
    // 04 FF 4C00 01 — length 4, manuf-specific, Apple (0x004C), payload 0x01
    expect(BleAdvertisement::extractRuuviPayload('04FF4C0001'))->toBeNull();
});

it('returns null for non-hex input', function () {
    expect(BleAdvertisement::extractRuuviPayload('zzzz'))->toBeNull();
});

it('returns null when an AD claims to extend past the buffer', function () {
    // Length byte says 10 bytes follow, but only 2 are present
    expect(BleAdvertisement::extractRuuviPayload('0AFF99'))->toBeNull();
});

it('returns null on empty input', function () {
    expect(BleAdvertisement::extractRuuviPayload(''))->toBeNull();
});
