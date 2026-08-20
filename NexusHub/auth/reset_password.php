<?php
require __DIR__ . '/error_handling.php';
require __DIR__ . '/session_config.php';
require __DIR__ . '/config.php';
require __DIR__ . '/csrf.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

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

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$error = '';
$success = false;
$tokenRow = null;

if ($token !== '') {
    $stmt = $pdo->prepare('SELECT id, user_id, expires_at, used FROM password_resets WHERE token = ?');
    $stmt->execute([$token]);
    $tokenRow = $stmt->fetch();
}

$tokenValid = $tokenRow
    && !$tokenRow['used']
    && strtotime($tokenRow['expires_at']) >= time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!csrfTokenIsValid()) {
        $error = "Session expirée, merci de recharger la page.";
    } elseif (!$tokenValid) {
        $error = "Ce lien de réinitialisation est invalide ou expiré.";
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $error = "Merci de remplir tous les champs.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Les deux mots de passe ne correspondent pas.";
    } elseif (mb_strlen($newPassword) < 8) {
        $error = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $tokenRow['user_id']]);

        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
            ->execute([$tokenRow['id']]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialiser le mot de passe - NexusHub</title>
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
            <h1>Réinitialiser le mot de passe</h1>

            <?php if ($success): ?>
                <p style="color:var(--text-secondary);font-size:15px;margin-bottom:18px;">
                    Ton mot de passe a bien été mis à jour.
                </p>
                <a href="login.php" class="download-btn-secondary" style="display:inline-block;text-decoration:none;">Se connecter</a>

            <?php elseif (!$tokenValid): ?>
                <p class="auth-error">Ce lien de réinitialisation est invalide ou a expiré.</p>
                <p style="margin-top:12px;">
                    <a href="forgot_password.php" style="font-size:13px;color:var(--button-active,#4b6cb7);text-decoration:none;">Demander un nouveau lien</a>
                </p>

            <?php else: ?>
                <?php if ($error): ?>
                    <p class="auth-error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <form method="POST" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="auth-field">
                        <label for="new-password">Nouveau mot de passe</label>
                        <input type="password" name="new_password" id="new-password" required minlength="8">
                    </div>
                    <div class="auth-field">
                        <label for="confirm-password">Confirmer le mot de passe</label>
                        <input type="password" name="confirm_password" id="confirm-password" required minlength="8">
                    </div>
                    <button type="submit">Mettre à jour le mot de passe</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</body>

</html>
