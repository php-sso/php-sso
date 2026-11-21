<?php

declare(strict_types=1);

/**
 * Prepares a throwaway php-sso for the OpenID conformance suite: one test person and the two clients the Basic OP
 * plan asks for. Run inside the php-sso container by run.sh; safe to run twice.
 *
 * Everything here is a well-known test value. Never run this against a database that matters.
 */

use PhpSso\Store\Clients;
use PhpSso\Store\StorageFactory;
use PhpSso\Store\StorageOptions;
use PhpSso\Store\Users;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$storage = StorageFactory::create($config['storage'], new StorageOptions($config['session_idle_seconds'], array_keys($config['scopes'])));
$users = $storage->users();
$clients = $storage->clients();

if ($users->findByUsername('conformance') === null) {
    $users->create(['username' => 'conformance', 'password' => 'conformance-pass', 'name' => 'Conformance Tester', 'email' => 'conformance@example.com', 'email_verified' => true, 'is_admin' => false]);
}

// The suite sends people back to {suite}/test/a/{alias}/callback; the alias is set in plan-config.json.
$redirect = 'https://localhost.emobix.co.uk:8443/test/a/php-sso/callback';
foreach (['conformance-1' => 'conformance-secret-1', 'conformance-2' => 'conformance-secret-2'] as $clientId => $secret) {
    if ($clients->find($clientId) !== null) {
        continue;
    }
    $clients->insert([
        'client_id' => $clientId,
        'name' => 'Conformance suite client ' . substr($clientId, -1),
        'secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
        'confidential' => true,
        'redirect_uris' => [$redirect],
        'grants' => ['authorization_code', 'refresh_token'],
        'backchannel_logout_uri' => null,
        'post_logout_redirect_uris' => [],
    ]);
}

echo "ready\n";
