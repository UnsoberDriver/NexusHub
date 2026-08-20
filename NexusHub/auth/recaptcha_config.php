<?php
/**
 * Configuration reCAPTCHA - Charge les clés du fichier .env
 */

require_once __DIR__ . '/../config/env.php';

// Récupérer les clés reCAPTCHA.
// NB: si RECAPTCHA_SECRET_KEY n'est pas défini en prod, on arrête tout
// plutôt que de retomber silencieusement sur une clé de test codée en dur.
$recaptcha_site_key   = $_ENV['RECAPTCHA_SITE_KEY'] ?? null;
$recaptcha_secret_key = $_ENV['RECAPTCHA_SECRET_KEY'] ?? null;

if (!$recaptcha_site_key || !$recaptcha_secret_key) {
    if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
        // Clés de test Google (valables seulement en local/dev)
        $recaptcha_site_key   = $recaptcha_site_key ?? '6Lc7FkotAAAAADptMDQ3O2ypvaC21WA2gy8YdUuM';
        $recaptcha_secret_key = $recaptcha_secret_key ?? '6Lc7FkotAAAAACOIFwvb4DRCz0P1HexuYBwaiHdP';
    } else {
        error_log('RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY manquants en production.');
        http_response_code(500);
        die('Erreur de configuration du serveur.');
    }
}