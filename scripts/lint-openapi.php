#!/usr/bin/env php
<?php

/** Dependency-free OpenAPI 3.1 structural and route sanity check. */
declare(strict_types=1);

$root = dirname(__DIR__);
$specPath = $root.'/docs/openapi/v1/openapi.json';
$routesPath = $root.'/routes/api.php';
$errors = [];
$raw = @file_get_contents($specPath);
if ($raw === false) {
    $errors[] = "missing spec: {$specPath}";
}
$spec = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (! is_array($spec)) {
    $errors[] = 'spec is not a JSON object';
}
if (is_array($spec)) {
    foreach (['openapi', 'info', 'paths', 'components'] as $key) {
        if (! array_key_exists($key, $spec)) {
            $errors[] = "missing top-level key: {$key}";
        }
    }
    if (($spec['openapi'] ?? null) !== '3.1.0') {
        $errors[] = 'openapi must be exactly 3.1.0 for the versioned v1 contract';
    }
    if (! isset($spec['info']['version']) || ! is_string($spec['info']['version'])) {
        $errors[] = 'info.version is required';
    }
    if (! isset($spec['servers'][0]['url']) || $spec['servers'][0]['url'] !== '/api/v1') {
        $errors[] = 'server URL must be /api/v1';
    }
    $operationIds = [];
    foreach (($spec['paths'] ?? []) as $path => $pathItem) {
        if (! str_starts_with((string) $path, '/')) {
            $errors[] = "path must start with /: {$path}";
        }
        foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'] as $method) {
            if (! isset($pathItem[$method])) {
                continue;
            }
            $operation = $pathItem[$method];
            if (! isset($operation['operationId'])) {
                $errors[] = "{$method} {$path} has no operationId";
            } elseif (isset($operationIds[$operation['operationId']])) {
                $errors[] = "duplicate operationId: {$operation['operationId']}";
            } else {
                $operationIds[$operation['operationId']] = true;
            }
            if (! isset($operation['responses']) || $operation['responses'] === []) {
                $errors[] = "{$method} {$path} has no responses";
            }
        }
    }
    foreach (['health', 'version', 'login', 'logout', 'me', 'dashboard', 'branches', 'warehouses', 'profile', 'accounting-settings'] as $required) {
        if (! isset($spec['paths']['/'.$required])) {
            $errors[] = "required contract path missing: /{$required}";
        }
    }
    foreach (['DataResponse', 'ErrorResponse', 'HealthResponse'] as $schema) {
        if (! isset($spec['components']['schemas'][$schema])) {
            $errors[] = "required schema missing: {$schema}";
        }
    }
}
$routes = @file_get_contents($routesPath) ?: '';
foreach (['/health', '/version', '/login', '/logout', '/me', '/dashboard'] as $route) {
    if (! str_contains($routes, "'{$route}'")) {
        $errors[] = "Laravel route sanity failed: {$route}";
    }
}
if ($errors) {
    fwrite(STDERR, "OpenAPI contract FAILED\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}
echo 'OpenAPI contract OK: '.count($spec['paths']).' paths, '.count($operationIds).' operations, version '.$spec['info']['version']."\n";
