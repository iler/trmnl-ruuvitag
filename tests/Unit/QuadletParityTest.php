<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

function extractEnvKeysFromQuadlet(string $path): array
{
    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^Environment=([A-Z_]+)=/', $line, $m)) {
            $keys[] = $m[1];
        }
    }

    return $keys;
}

function extractEnvKeysFromTemplate(string $path): array
{
    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z_]+)=/', $line, $m)) {
            $keys[] = $m[1];
        }
    }

    return $keys;
}

function quadletEffectiveEnv(string $serviceName): array
{
    $root = __DIR__.'/../..';
    $quadletKeys = extractEnvKeysFromQuadlet("{$root}/deploy/quadlets/{$serviceName}.container");
    $secretKeys = extractEnvKeysFromTemplate("{$root}/deploy/secrets.env.tmpl");

    return array_values(array_unique(array_merge($quadletKeys, $secretKeys)));
}

function composeEnvKeys(string $serviceName): array
{
    $compose = Yaml::parseFile(__DIR__.'/../../docker-compose.prod.yml');

    return array_keys($compose['services'][$serviceName]['environment']);
}

it('passes every compose app env key through to the app quadlet', function () {
    $compose = composeEnvKeys('app');
    $quadlet = quadletEffectiveEnv('app');
    $missing = array_values(array_diff($compose, $quadlet));
    expect($missing)->toBe(
        [],
        'app.container is missing keys present in compose: '.implode(', ', $missing)
    );
});

it('passes every compose nightwatch-agent env key through to the nightwatch-agent quadlet', function () {
    $compose = composeEnvKeys('nightwatch-agent');
    $quadlet = quadletEffectiveEnv('nightwatch-agent');
    $missing = array_values(array_diff($compose, $quadlet));
    expect($missing)->toBe(
        [],
        'nightwatch-agent.container is missing keys present in compose: '.implode(', ', $missing)
    );
});

it('routes secrets through the op-inject env file in every container', function (string $service) {
    $contents = file_get_contents(__DIR__."/../../deploy/quadlets/{$service}.container");
    expect($contents)->toContain('EnvironmentFile=/run/trmnl-ruuvi/env');
})->with(['app', 'nightwatch-agent']);
