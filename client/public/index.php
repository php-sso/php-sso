<?php

// Demo relying party. All it knows about the SSO server is the OIDC protocol:
// redirect to /authorize (with PKCE + nonce), exchange the code at /token, verify the ID token.
// It also supports OIDC logout: it ends its own session when the SSO server posts a logout token to
// /backchannel-logout, and it signs the person out of the SSO server through /end-session.

declare(strict_types=1);

use PhpSso\OAuthServer\Clock\SystemClock;
use PhpSso\OAuthServer\Jwt\ClaimsValidator;
use PhpSso\OAuthServer\Jwt\JwtDecoder;
use PhpSso\OAuthServer\Jwt\VerificationKeySet;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Cookies are shared between ports on localhost, so every demo app needs its own cookie name.
session_name(getenv('CLIENT_SESSION_NAME') ?: 'client_session');
session_start();

$issuer = getenv('SSO_PUBLIC_URL');     // what the browser can reach; also the expected `iss`
$internal = getenv('SSO_INTERNAL_URL'); // what this container can reach
$self = getenv('CLIENT_URL');
$clientId = getenv('CLIENT_ID');
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$b64url = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

/** Small HTTP helper: returns the decoded JSON body (also for error responses). */
$http = static fn (string $method, string $url, array $headers = [], ?array $form = null): ?array => json_decode((string) @file_get_contents(
    $url,
    false,
    stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers + ($form ? ['Content-Type: application/x-www-form-urlencoded'] : [])),
        'content' => $form ? http_build_query($form) : '',
        'ignore_errors' => true,
    ]]),
), true);

/** Checks a token against the server's published keys (signature, RS256 only), then its issuer, audience and times. */
$verify = static function (string $jwt) use ($http, $internal, $issuer, $clientId): array {
    $claims = (new JwtDecoder(VerificationKeySet::fromJwks($http('GET', $internal . '/jwks') ?? [])))->decode($jwt)['claims'];
    (new ClaimsValidator(new SystemClock(), (string) $issuer, 30))->validate($claims, (string) $clientId);

    return $claims;
};

// Sessions the SSO server told us (back-channel) have ended. A real app would keep this in its session store.
$revokedFile = sys_get_temp_dir() . '/demo-client-revoked-sids';
$revoked = is_file($revokedFile) ? file($revokedFile, FILE_IGNORE_NEW_LINES) : [];
if (!empty($_SESSION['sid']) && in_array($_SESSION['sid'], $revoked, true)) {
    $_SESSION = [];
}

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/backchannel-logout':
        // OpenID Connect Back-Channel Logout 1.0: verify the logout token, then end the session it names.
        header('Cache-Control: no-store');
        try {
            $logout = $verify((string) ($_POST['logout_token'] ?? ''));
            $events = (array) ($logout['events'] ?? []);
            if ($logout['iss'] !== $issuer || !in_array($clientId, (array) $logout['aud'], true)
                || !array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events)
                || isset($logout['nonce']) || empty($logout['sid'])) {
                throw new RuntimeException('Not a valid logout token for this app');
            }
        } catch (Throwable $ex) {
            http_response_code(400);
            exit(json_encode(['error' => 'invalid_request', 'error_description' => $ex->getMessage()]));
        }
        file_put_contents($revokedFile, $logout['sid'] . "\n", FILE_APPEND | LOCK_EX);
        http_response_code(200);
        break;

    case '/login':
        $_SESSION['state'] = bin2hex(random_bytes(16));
        $_SESSION['nonce'] = bin2hex(random_bytes(16));
        $_SESSION['verifier'] = $b64url(random_bytes(32));
        header('Location: ' . $issuer . '/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $self . '/callback',
            'scope' => 'openid profile email groups',
            'state' => $_SESSION['state'],
            'nonce' => $_SESSION['nonce'],
            'code_challenge' => $b64url(hash('sha256', $_SESSION['verifier'], true)),
            'code_challenge_method' => 'S256',
        ]));
        break;

    case '/callback':
        if (isset($_GET['error'])) {
            http_response_code(400);
            exit('SSO error: ' . $e($_GET['error'] . ' ' . ($_GET['error_description'] ?? '')));
        }
        if (!hash_equals($_SESSION['state'] ?? '', (string) ($_GET['state'] ?? ''))) {
            http_response_code(400);
            exit('Invalid state');
        }

        $tokens = $http('POST', $internal . '/token', [], [
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'] ?? '',
            'redirect_uri' => $self . '/callback',
            'client_id' => $clientId,
            'client_secret' => getenv('CLIENT_SECRET'),
            'code_verifier' => $_SESSION['verifier'] ?? '',
        ]);
        if (empty($tokens['id_token'])) {
            http_response_code(502);
            exit('Token exchange failed: ' . $e(json_encode($tokens)));
        }

        // Verify the ID token: signature (keys from JWKS), issuer, audience and its times.
        try {
            $claims = $verify($tokens['id_token']);
        } catch (Throwable $ex) {
            http_response_code(400);
            exit('Invalid ID token: ' . $e($ex->getMessage()));
        }
        $aud = (array) $claims['aud'];
        if ($claims['iss'] !== $issuer || !in_array($clientId, $aud, true) || ($claims['nonce'] ?? '') !== $_SESSION['nonce']) {
            http_response_code(400);
            exit('ID token iss/aud/nonce mismatch');
        }

        unset($_SESSION['state'], $_SESSION['nonce'], $_SESSION['verifier']);
        $_SESSION['user'] = $claims;
        $_SESSION['sid'] = $claims['sid'] ?? null;     // identifies the SSO session, matched by back-channel logout
        $_SESSION['id_token'] = $tokens['id_token'];   // sent as id_token_hint when signing out of the SSO server
        header('Location: /');
        break;

    case '/logout':
        // Ask the SSO server to end the session too (RP-initiated logout); it sends the browser back to /.
        $hint = $_SESSION['id_token'] ?? null;
        $_SESSION = [];
        header('Location: ' . ($hint
            ? $issuer . '/end-session?' . http_build_query(['id_token_hint' => $hint, 'post_logout_redirect_uri' => $self . '/', 'state' => bin2hex(random_bytes(8))])
            : '/'));
        break;

    case '/logout-local':
        $_SESSION = [];
        header('Location: /');
        break;

    default:
        echo '<h1>Demo client</h1>';
        if (!empty($_SESSION['user'])) {
            $u = $_SESSION['user'];
            echo '<p>Hello <b>' . $e($u['name']) . '</b> (' . $e($u['email']) . '), sub=' . $e($u['sub']) . '</p>'
                . '<p>Groups: ' . $e(implode(', ', $u['groups'] ?? []) ?: 'none') . '</p>'
                . '<p><a href="/logout">Log out</a> · <a href="/logout-local">Log out of this app only</a></p>'
                . '<pre>' . $e(json_encode($u, JSON_PRETTY_PRINT)) . '</pre>';
        } else {
            echo <<<'HTML'
<style>
.psso-btn{display:inline-flex;align-items:center;gap:.6em;min-height:44px;padding:.7em 1.2em .7em 1em;box-sizing:border-box;font:600 1rem/1.2 system-ui,sans-serif;background:#3a3fc4;color:#fff;border:1px solid transparent;border-radius:8px;text-decoration:none}
.psso-btn:hover{background:#2f33a8}
.psso-btn:focus-visible{outline:none;box-shadow:0 0 0 2px #fff,0 0 0 5px #3a3fc4}
.psso-btn svg{width:1.1em;height:1.9em;flex:none}
</style>
<p>Not signed in.</p>
<p><a class="psso-btn" href="/login"><svg viewBox="16 6 32 54" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="7"><circle cx="32" cy="22" r="10"/><path d="M32 32V57M32 49h11"/></g><circle cx="32" cy="22" r="3" fill="#ffb020"/></svg>Sign in with php-sso</a></p>
HTML;
        }
}
