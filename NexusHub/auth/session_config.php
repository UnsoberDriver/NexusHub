<?php
// ============================================
// Configuration de session partagée
// A inclure à la place de session_start() dans TOUTES les pages
// (index.php, login.php, logout.php, call.php, insta.php, etc.)
// ============================================

// Durée de vie de la session : 30 jours (au lieu des ~24 min par défaut de PHP)
const SESSION_LIFETIME = 60 * 60 * 24 * 30;

// Le "garbage collector" de PHP ne doit pas effacer la session côté serveur
// avant l'expiration du cookie
ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
session_cache_limiter('');

// Détection HTTPS fiable même derrière un reverse proxy / load balancer
// (AlwaysData, etc.) qui termine le TLS en amont et transmet le protocole
// d'origine via l'en-tête X-Forwarded-Proto.
$__isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $__isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

if (!headers_sent()) {
    header('Cache-Control: private, no-cache, must-revalidate');
}

// Prolonge le cookie à chaque visite tant que l'utilisateur est actif
// (sinon le cookie garde sa date d'émission d'origine et expire quand même après 30 jours fixes)
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), $_COOKIE[session_name()], [
        'expires'  => time() + SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $__isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}