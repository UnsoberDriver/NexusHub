<?php
require __DIR__ . '/error_handling.php';
require __DIR__ . '/session_config.php';
require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

// Déjà connecté : pas besoin de cette page.
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Table des tokens de réinitialisation (migration légère, une seule fois).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) { /* table déjà là */ }

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim($_POST['email'] ?? ''));

    if (!csrfTokenIsValid()) {
        $error = "Session expirée, merci de réessayer.";
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Merci de saisir une adresse email valide.";
    } else {
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = "Adresse e-mail invalide";
        } else {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // valable 1h

            $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$user['id'], $token, $expiresAt]);

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $resetLink = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/login.php?token=' . $token;

            $subject = 'Réinitialisation de ton mot de passe NexusHub';
            $message = "Bonjour " . $user['username'] . ",\r\n\r\n"
                . "Tu as demandé la réinitialisation de ton mot de passe NexusHub.\r\n"
                . "Clique sur le lien ci-dessous pour choisir un nouveau mot de passe (valable 1 heure) :\r\n\r\n"
                . $resetLink . "\r\n\r\n"
                . "Si tu n'es pas à l'origine de cette demande, ignore simplement cet email.\r\n\r\n"
                . "-- L'équipe NexusHub";

            $headers = "From: NexusHub <no-reply@" . $_SERVER['HTTP_HOST'] . ">\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n";

            @mail($email, $subject, $message, $headers);

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié - NexusHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Arimo:wght@400;700;800&family=DM+Sans:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .page-content { align-items: center; }
    </style>
</head>

<body>

    <div class="page-content">
        <div class="auth-box">
            <h1>Réinitialisation de mot de passe</h1>

            <?php if ($success): ?>
                <p style="color:var(--text-secondary);font-size:15px;margin-bottom:18px;">
                    Une demande de réinitialisation de mot de passe a été envoyée à <strong><?= htmlspecialchars($email) ?></strong>.
                </p>
                <a href="login.php" class="download-btn-secondary" style="display:inline-block;text-decoration:none;">Retour à la connexion</a>
            <?php else: ?>
                <p style="color:var(--text-secondary);font-size:14px;margin-bottom:18px;">
                    Saisis l'adresse email associée à ton compte, tu recevras un lien pour choisir un nouveau mot de passe.
                </p>

                <?php if ($error): ?>
                    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <div class="auth-field">
                        <label for="forgot-email">Adresse email</label>
                        <input type="email" name="email" id="forgot-email" required maxlength="255"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <button type="submit">Envoyer le lien</button>
                </form>

                <p style="margin-top:16px;">
                    <a href="login.php" style="font-size:13px;color:var(--button-active,#4b6cb7);text-decoration:none;">← Retour à la connexion</a>
                </p>
            <?php endif; ?>
        </div>
    </div>

</body>

</html>