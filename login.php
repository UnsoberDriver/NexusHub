<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/recaptcha_config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Vérifie la réponse reCAPTCHA auprès de Google
function verifyRecaptcha(string $secret, string $response): bool {
    if ($response === '') {
        return false;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => $secret,
        'response' => $response,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false) {
        return false;
    }

    $data = json_decode($result, true);
    return !empty($data['success']);
}

$error = '';
$mode  = $_POST['mode'] ?? 'login'; // 'login' ou 'register'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username         = trim($_POST['username'] ?? '');
    $password         = $_POST['password'] ?? '';
    $captchaResponse  = $_POST['g-recaptcha-response'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Merci de remplir tous les champs.";
    } elseif (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
        $error = "Le nom d'utilisateur doit faire entre 3 et 50 caractères.";
    } elseif (!verifyRecaptcha($recaptcha_secret_key, $captchaResponse)) {
        $error = "Merci de valider le captcha \"Je ne suis pas un robot\".";
    } elseif ($mode === 'register') {

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);

        if ($stmt->fetch()) {
            $error = "Ce nom d'utilisateur est déjà pris.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
            $stmt->execute([$username, $hash]);
            $userId = $pdo->lastInsertId();

            // Ajoute automatiquement le nouvel utilisateur au chat général
            $general = $pdo->query("SELECT id FROM conversations WHERE type = 'general' LIMIT 1")->fetch();
            if ($general) {
                $stmt = $pdo->prepare('INSERT IGNORE INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)');
                $stmt->execute([$general['id'], $userId]);
            }

            $_SESSION['user_id']  = $userId;
            $_SESSION['username'] = $username;
            header('Location: index.php');
            exit;
        }
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: index.php');
            exit;
        }

        $error = "Identifiants incorrects.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion - Messagerie</title>
<link rel="stylesheet" href="styles.css">
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>

<nav class="navbar">
    <div class="navbar-inner">
        <a href="login.php" class="navbar-brand">Torme</a>
    </div>
</nav>

<div class="page-content">
<div class="auth-box">
    <div class="auth-tabs">
        <button type="button" class="auth-tab-btn <?= $mode === 'login' ? 'active' : '' ?>" data-mode="login">Connexion</button>
        <button type="button" class="auth-tab-btn <?= $mode === 'register' ? 'active' : '' ?>" data-mode="register">Inscription</button>
    </div>

    <?php if ($error): ?>
        <p class="auth-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" class="auth-form">
        <input type="hidden" name="mode" id="auth-mode" value="<?= htmlspecialchars($mode) ?>">
        <input type="text" name="username" placeholder="Nom d'utilisateur" required maxlength="50"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        <input type="password" name="password" placeholder="Mot de passe" required>

        <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_site_key) ?>"></div>

        <button type="submit" id="auth-submit"><?= $mode === 'register' ? "S'inscrire" : 'Se connecter' ?></button>
    </form>
</div>
</div>

<script>
const tabBtns   = document.querySelectorAll('.auth-tab-btn');
const modeInput = document.getElementById('auth-mode');
const submitBtn = document.getElementById('auth-submit');

tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        tabBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        modeInput.value = btn.dataset.mode;
        submitBtn.textContent = btn.dataset.mode === 'register' ? "S'inscrire" : 'Se connecter';
    });
});
</script>

</body>
</html>