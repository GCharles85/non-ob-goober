<?php
// Auth bridge (temporary, for the PHP -> C# migration).
// The C# API is the auth authority: on login it sets an HttpOnly cookie `gb_auth` holding a
// signed JWT (HS256). This shim verifies that token with the shared secret and, if valid,
// populates $_SESSION['username'] so un-migrated legacy PHP pages still see a logged-in user.
// Remove this once PHP is fully retired.

if (!function_exists('goober_b64url_decode')) {
    function goober_b64url_decode($data) {
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

if (!function_exists('goober_jwt_verify')) {
    // Returns the decoded claims array if the token is a valid, unexpired HS256 JWT, else null.
    function goober_jwt_verify($jwt, $secret) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h, $p, $sig] = $parts;

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $sig)) return null;

        $payload = json_decode(goober_b64url_decode($p), true);
        if (!is_array($payload)) return null;
        if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;
        return $payload;
    }
}

// Make sure there's a session to populate.
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (empty($_SESSION['username']) && !empty($_COOKIE['gb_auth'])) {
    $secret = getenv('JWT_SECRET');
    if ($secret === false || $secret === '') {
        $secret = $_ENV['JWT_SECRET'] ?? '';
    }
    if ($secret !== '') {
        $claims = goober_jwt_verify($_COOKIE['gb_auth'], $secret);
        if ($claims && !empty($claims['sub'])) {
            $_SESSION['username'] = $claims['sub'];
        }
    }
}
?>
