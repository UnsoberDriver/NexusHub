<?php
// ============================================
// "Se souvenir de moi" : reconnexion automatique persistante
// A inclure APRES session_config.php (session démarrée) ET APRES config.php ($pdo dispo)
// ============================================

const REMEMBER_COOKIE_NAME = 'remember_me';
const REMEMBER_DAYS = 30;

// Crée un nouveau token "remember me" pour un utilisateur, le stocke en base
// (haché, jamais en clair) et pose le cookie correspondant.
function issueRememberMeCookie(PDO $pdo, int $userId): void
{
    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(33));
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * REMEMBER_DAYS);

    $stmt = $pdo->prepare('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $selector, $tokenHash, $expiresAt]);

    setcookie(REMEMBER_COOKIE_NAME, $selector . ':' . $validator, [
        'expires'  => time() + 60 * 60 * 24 * REMEMBER_DAYS,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Supprime le token en base + le cookie (à appeler depuis logout.php)
function clearRememberMeCookie(PDO $pdo): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE_NAME])) {
        $parts = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
        $selector = $parts[0] ?? '';
        if ($selector !== '') {
            $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        }
    }
    setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path'    => '/',
    ]);
}

// Si la session est vide mais qu'un cookie "remember me" valide existe,
// reconnecte automatiquement l'utilisateur et fait tourner le token (sécurité).
function attemptRememberMeLogin(PDO $pdo): void
{
    if (isset($_SESSION['user_id']) || empty($_COOKIE[REMEMBER_COOKIE_NAME])) {
        return;
    }

    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE_NAME], 2);
    if (count($parts) !== 2) {
        return;
    }
    [$selector, $validator] = $parts;

    $stmt = $pdo->prepare('
        SELECT rt.user_id, rt.token_hash, rt.expires_at, u.username
        FROM remember_tokens rt
        JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = ?
    ');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    // Token inconnu ou expiré : on nettoie le cookie et on s'arrête là
    if (!$row || strtotime($row['expires_at']) < time()) {
        setcookie(REMEMBER_COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/']);
        return;
    }

    // Comparaison en temps constant pour éviter le timing attack
    if (!hash_equals($row['token_hash'], hash('sha256', $validator))) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']  = (int) $row['user_id'];
    $_SESSION['username'] = $row['username'];

    // Token à usage unique : on le supprime et on en réémet un nouveau
    $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    issueRememberMeCookie($pdo, (int) $row['user_id']);
}
