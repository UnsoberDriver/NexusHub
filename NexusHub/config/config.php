<?php

require_once __DIR__ . '/env.php';

// Récupérer les variables (du .env ou valeurs par défaut)
$db_host = $_ENV['DB_HOST'] ?? '';
$db_name = $_ENV['DB_NAME'] ?? '';
$db_user = $_ENV['DB_USER'] ?? '';
$db_pass = $_ENV['DB_PASS'] ?? '';

// Cloudflare Realtime TURN (voir call.php, action=turn_credentials)
if (!defined('CF_TURN_KEY_ID')) {
    define('CF_TURN_KEY_ID', $_ENV['CF_TURN_KEY_ID'] ?? '');
}
if (!defined('CF_TURN_KEY_API_TOKEN')) {
    define('CF_TURN_KEY_API_TOKEN', $_ENV['CF_TURN_KEY_API_TOKEN'] ?? '');
}

try {
    $pdo = new PDO(
        'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4',
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('Erreur DB: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur de connexion à la base de données');
}

// Table pour le "Se souvenir de moi" (migration légère, comme pour message_reactions)
// ------------------------------------------------------------------
// config.php est inclus par TOUTES les pages (index.php, seen.php,
// login.php, call.php, insta.php...), y compris les pollings AJAX
// toutes les 5s. Sans garde-fou, ce CREATE TABLE IF NOT EXISTS tournait
// sur CHAQUE requête. On applique ici le même mécanisme de flag fichier
// que celui déjà utilisé dans index.php pour ses propres migrations,
// afin qu'il ne s'exécute qu'une seule fois par déploiement.
$__configMigrationFlagFile = sys_get_temp_dir() . '/nexushub_migrated_config_v1.flag';
if (!file_exists($__configMigrationFlagFile)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
            id         INT PRIMARY KEY AUTO_INCREMENT,
            user_id    INT NOT NULL,
            selector   VARCHAR(24) NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_selector (selector),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) { /* table déjà là */ }
    @file_put_contents($__configMigrationFlagFile, (string) time());
}