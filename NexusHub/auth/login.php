<?php
require __DIR__ . '/../includes/error_handling.php';
require __DIR__ . '/session_config.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/remember_me.php';
require __DIR__ . '/recaptcha_config.php';
require __DIR__ . '/captcha_verify.php';

if (isset($_SESSION['user_id'])) {
    header('Location: https://nexushub.alwaysdata.net/index.php?source=browser');
    exit;
}

// L'inscription n'est autorisée que si l'utilisateur est passé par le vrai navigateur
// (lien "Ouvrir dans le navigateur"), jamais depuis la webview de l'application.
if (isset($_GET['source']) && $_GET['source'] === 'browser') {
    $_SESSION['allow_register'] = true;
}
$canRegister = true;

// ============================================
// Réinitialisation de mot de passe via lien reçu par email
// (login.php?token=xxx). Remplace l'ancienne page séparée reset_password.php :
// le lien du mail amène directement ici et affiche le formulaire de
// nouveau mot de passe à la place du formulaire de connexion habituel.
// ============================================
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

$resetToken = $_GET['token'] ?? ($_POST['token'] ?? '');
$isResetMode = $resetToken !== '';
$resetError = '';
$resetSuccess = false;
$resetTokenRow = null;

if ($isResetMode) {
    $stmt = $pdo->prepare('SELECT id, user_id, expires_at, used FROM password_resets WHERE token = ?');
    $stmt->execute([$resetToken]);
    $resetTokenRow = $stmt->fetch();

    $resetTokenValid = $resetTokenRow
        && !$resetTokenRow['used']
        && strtotime($resetTokenRow['expires_at']) >= time();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'reset_password') {
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!csrfTokenIsValid()) {
            $resetError = "Session expirée, merci de recharger la page.";
        } elseif (!$resetTokenValid) {
            $resetError = "Ce lien de réinitialisation est invalide ou expiré.";
        } elseif ($newPassword === '' || $confirmPassword === '') {
            $resetError = "Merci de remplir tous les champs.";
        } elseif ($newPassword !== $confirmPassword) {
            $resetError = "Les deux mots de passe ne correspondent pas.";
        } elseif (mb_strlen($newPassword) < 8) {
            $resetError = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$hash, $resetTokenRow['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
                ->execute([$resetTokenRow['id']]);
            $resetSuccess = true;
        }
    }
}

$error = '';
$mode = $_POST['mode'] ?? 'login'; // 'login' ou 'register'

if ($mode === 'register' && !$canRegister) {
    // Empêche de forcer le mode inscription par POST direct depuis la webview.
    $mode = 'login';
}

if (!$isResetMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $identifier = trim($_POST['identifier'] ?? '');
    $birthDay = trim($_POST['birth_day'] ?? '');
    $birthMonth = trim($_POST['birth_month'] ?? '');
    $birthYear = trim($_POST['birth_year'] ?? '');
    $birthDate = ($birthDay !== '' && $birthMonth !== '' && $birthYear !== '')
        ? sprintf('%04d-%02d-%02d', (int) $birthYear, (int) $birthMonth, (int) $birthDay)
        : '';
    $password = $_POST['password'] ?? '';
    $captchaResponse = $_POST['g-recaptcha-response'] ?? '';

    // Pour la connexion, le captcha se base sur l'email seulement si l'identifiant saisi en est un
    $captchaLookupEmail = $mode === 'register' ? $email : (filter_var($identifier, FILTER_VALIDATE_EMAIL) ? mb_strtolower($identifier) : '');

    // Le captcha n'est exigé que si cette adresse email ne l'a jamais validé
    $captchaAlreadyVerified = $captchaLookupEmail !== '' && isCaptchaVerifiedForEmail($pdo, $captchaLookupEmail);

    if (!csrfTokenIsValid()) {
        $error = "Session expirée, merci de réessayer.";
    } elseif ($mode === 'register' && !$canRegister) {
        $error = "Merci d'ouvrir cette page dans votre navigateur pour créer un compte.";
    } elseif ($mode === 'register' && ($username === '' || $password === '' || $email === '')) {
        $error = "Merci de remplir tous les champs.";
    } elseif ($mode !== 'register' && ($identifier === '' || $password === '')) {
        $error = "Merci de remplir tous les champs.";
    } elseif ($mode === 'register' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Merci de saisir une adresse email valide.";
    } elseif ($mode === 'register' && (mb_strlen($username) < 3 || mb_strlen($username) > 50)) {
        $error = "Le nom d'utilisateur doit faire entre 3 et 50 caractères.";
    } elseif ($mode === 'register' && $birthDate === '') {
        $error = "Merci de renseigner votre date de naissance.";
    } elseif ($mode === 'register' && !checkdate((int) $birthMonth, (int) $birthDay, (int) $birthYear)) {
        $error = "Date de naissance invalide.";
    } elseif ($mode === 'register' && (function () use ($birthDate) {
        $dob = DateTime::createFromFormat('Y-m-d', $birthDate);
        $today = new DateTime('today');
        if ($dob > $today) {
            return true; // date dans le futur => invalide
        }
        $age = $today->diff($dob)->y;
        return $age < 16;
    })()) {
        $error = "Vous devez avoir au moins 16 ans pour vous inscrire.";
    } elseif (!$captchaAlreadyVerified && !verifyRecaptcha($recaptcha_secret_key, $captchaResponse)) {
        $error = "Merci de valider le captcha \"Je ne suis pas un robot\".";
    } elseif ($mode === 'register') {

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);

        $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $emailStmt->execute([$email]);

        if ($stmt->fetch()) {
            $error = "Ce nom d'utilisateur est déjà pris.";
        } elseif ($emailStmt->fetch()) {
            $error = "Cette adresse email est déjà utilisée.";
        } else {
            if (!$captchaAlreadyVerified) {
                markCaptchaVerifiedForEmail($pdo, $email);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, birth_date) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $email, $hash, $birthDate]);
            $userId = $pdo->lastInsertId();

            // Ajoute automatiquement le nouvel utilisateur au chat général
            $general = $pdo->query("SELECT id FROM conversations WHERE type = 'general' LIMIT 1")->fetch();
            if ($general) {
                $stmt = $pdo->prepare('INSERT IGNORE INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)');
                $stmt->execute([$general['id'], $userId]);
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            if (!empty($_POST['remember'])) {
                issueRememberMeCookie($pdo, (int) $userId);
            }
            header('Location: https://nexushub.alwaysdata.net/index.php?source=browser');
            exit;
        }
    } else {
        $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$identifier, mb_strtolower($identifier)]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (!$captchaAlreadyVerified) {
                markCaptchaVerifiedForEmail($pdo, mb_strtolower($user['email']));
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            if (!empty($_POST['remember'])) {
                issueRememberMeCookie($pdo, (int) $user['id']);
            }
            header('Location: https://nexushub.alwaysdata.net/index.php?source=browser');
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Arimo:wght@400;700;800&family=DM+Sans:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        .page-content {
            align-items: center;
        }
        body {
            background: radial-gradient(circle at 20% 15%, #6d28d9 0%, transparent 45%),
                        radial-gradient(circle at 85% 20%, #2563eb 0%, transparent 50%),
                        linear-gradient(160deg, #1e1030 0%, #0d1224 45%, #000000 100%);
            background-color: #05050a;
        }
        .auth-box {
            background: #12141c;
            --bg-tertiary: #1c1f2b;
            --bg-secondary: #12141c;
            --text-primary: #f2f2f2;
            --text-secondary: #9a9ea6;
            --border-color: #2a2d34;
        }
    </style>
</head>

<body>

    <div class="page-content">
        <div class="auth-box">
            <?php if ($isResetMode): ?>

                <h1>Réinitialisation de mot de passe</h1>

                <?php if ($resetSuccess): ?>
                    <p style="color:var(--text-secondary);font-size:15px;margin-bottom:18px;">
                        Ton mot de passe a bien été mis à jour.
                    </p>
                    <a href="login.php" class="download-btn-secondary" style="display:inline-block;text-decoration:none;">Se connecter</a>

                <?php elseif (!$resetTokenValid): ?>
                    <p class="auth-error">Ce lien de réinitialisation est invalide ou a expiré.</p>
                    <p style="margin-top:12px;">
                        <a href="forgot_password.php" style="font-size:13px;color:var(--button-active,#4b6cb7);text-decoration:none;">Demander un nouveau lien</a>
                    </p>

                <?php else: ?>
                    <?php if ($resetError): ?>
                        <p class="auth-error"><?= htmlspecialchars($resetError) ?></p>
                    <?php endif; ?>

                    <form method="POST" class="auth-form">
                        <input type="hidden" name="form" value="reset_password">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

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

            <?php else: ?>

            <div class="auth-tabs">
                <button type="button" class="auth-tab-btn auth-welcome-btn <?= $mode === 'login' ? 'active' : '' ?>"
                    data-mode="login">
                    <span class="auth-welcome-title" id="auth-welcome-title">Ha, te revoilà !</span>
                    <span class="auth-welcome-subtitle" id="auth-welcome-subtitle">Nous sommes si heureux de te revoir !</span>
                </button>
                <?php if ($canRegister): ?>
                <button type="button" class="auth-tab-btn <?= $mode === 'register' ? 'active' : '' ?>"
                    data-mode="register" style="display:none;">Créer un compte</button>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <p class="auth-error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <input type="hidden" name="mode" id="auth-mode" value="<?= htmlspecialchars($mode) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <div class="auth-field" id="auth-identifier-field" <?= $mode === 'register' ? 'hidden' : '' ?>>
                    <label for="auth-identifier">Adresse email / nom d'utilisateur</label>
                    <input type="text" name="identifier" id="auth-identifier"
                        required maxlength="255" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                </div>

                <div class="auth-field" id="auth-email-field" <?= $mode === 'register' ? '' : 'hidden' ?>>
                    <label for="auth-email">Adresse email</label>
                    <input type="email" name="email" id="auth-email" maxlength="255"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="auth-field" id="auth-username-field" <?= $mode === 'register' ? '' : 'hidden' ?>>
                    <label for="auth-username">Nom d'utilisateur</label>
                    <input type="text" name="username" id="auth-username" maxlength="50"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div class="auth-field" id="auth-birthdate-field" <?= $mode === 'register' ? '' : 'hidden' ?>>
                    <label>Date de naissance <span class="auth-required">*</span></label>
                    <div class="auth-birthdate-row">
                        <select name="birth_day" id="auth-birth-day" class="auth-select">
                            <option value="" disabled <?= empty($_POST['birth_day']) ? 'selected' : '' ?>>Jour</option>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>" <?= (isset($_POST['birth_day']) && (int) $_POST['birth_day'] === $d) ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="birth_month" id="auth-birth-month" class="auth-select">
                            <option value="" disabled <?= empty($_POST['birth_month']) ? 'selected' : '' ?>>Mois</option>
                            <?php
                                $moisNoms = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
                                foreach ($moisNoms as $i => $nomMois):
                            ?>
                                <option value="<?= $i + 1 ?>" <?= (isset($_POST['birth_month']) && (int) $_POST['birth_month'] === $i + 1) ? 'selected' : '' ?>><?= $nomMois ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="birth_year" id="auth-birth-year" class="auth-select">
                            <option value="" disabled <?= empty($_POST['birth_year']) ? 'selected' : '' ?>>Année</option>
                            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 100; $y--): ?>
                                <option value="<?= $y ?>" <?= (isset($_POST['birth_year']) && (int) $_POST['birth_year'] === $y) ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="auth-field">
                    <label for="auth-password">Mot de passe</label>
                    <div class="auth-password-wrap">
                        <input type="password" name="password" id="auth-password" required>
                        <button type="button" class="auth-password-toggle" id="auth-password-toggle"
                            aria-label="Afficher le mot de passe" aria-pressed="false">
                            <svg class="auth-eye-icon auth-eye-open" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="auth-eye-icon auth-eye-closed" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.9 18.9 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 8 11 8a18.9 18.9 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <div class="auth-field" id="auth-forgot-field" <?= $mode === 'register' ? 'hidden' : '' ?> style="text-align:left;">
                    <a href="forgot_password.php" style="font-size:13px;color:var(--button-active,#4b6cb7);text-decoration:none;">Mot de passe oublié ?</a>
                </div>

                <!-- Case "Rester connecté" retirée de l'affichage ; on garde le comportement
                     (connexion mémorisée) actif par défaut via ce champ caché. -->
                <input type="hidden" name="remember" value="1">

                <?php
                    if ($mode === 'register') {
                        $prefillEmail = $_POST['email'] ?? '';
                    } else {
                        $prefillIdentifier = $_POST['identifier'] ?? '';
                        $prefillEmail = filter_var($prefillIdentifier, FILTER_VALIDATE_EMAIL) ? mb_strtolower($prefillIdentifier) : '';
                    }
                    $captchaAlreadyVerifiedForDisplay = $prefillEmail !== '' && isCaptchaVerifiedForEmail($pdo, $prefillEmail);
                ?>
                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($recaptcha_site_key) ?>" data-theme="dark"
                    id="auth-captcha" <?= $captchaAlreadyVerifiedForDisplay ? 'hidden' : '' ?>></div>

                <button type="submit"
                    id="auth-submit"><?= $mode === 'register' ? "Créer un compte" : 'Se connecter' ?></button>

                <?php if ($canRegister): ?>
                <p id="auth-register-hint" style="text-align:center;margin-top:14px;font-size:13px;color:var(--text-secondary,#9a9ea6);" <?= $mode === 'register' ? 'hidden' : '' ?>>
                    Besoin d'un compte ?
                    <a href="#" id="auth-register-link" style="color:var(--button-active,#4b6cb7);text-decoration:none;font-weight:600;">S'inscrire</a>
                </p>
                <p id="auth-login-hint" style="text-align:center;margin-top:14px;font-size:13px;color:var(--text-secondary,#9a9ea6);" <?= $mode === 'register' ? '' : 'hidden' ?>>
                    Tu as déjà un compte ?
                    <a href="#" id="auth-login-link" style="color:var(--button-active,#4b6cb7);text-decoration:none;font-weight:600;">Connecte-toi</a>
                </p>
                <?php endif; ?>
            </form>

            <?php endif; ?>
        </div>
    </div>

    <script>
        // En mode réinitialisation (?token=...), le formulaire de connexion/inscription
        // n'existe pas dans le DOM : on arrête le script tout de suite pour éviter
        // des erreurs sur des éléments absents.
        if (!document.getElementById('auth-mode')) {
            // rien à faire sur cette page dans ce mode
        } else {
        const tabBtns = document.querySelectorAll('.auth-tab-btn');
        const modeInput = document.getElementById('auth-mode');
        const submitBtn = document.getElementById('auth-submit');
        const identifierInput = document.getElementById('auth-identifier');
        const emailInputEl = document.getElementById('auth-email');
        const usernameInputEl = document.getElementById('auth-username');
        const birthDayEl = document.getElementById('auth-birth-day');
        const birthMonthEl = document.getElementById('auth-birth-month');
        const birthYearEl = document.getElementById('auth-birth-year');

        const identifierField = document.getElementById('auth-identifier-field');
        const emailField = document.getElementById('auth-email-field');
        const usernameField = document.getElementById('auth-username-field');
        const birthDateField = document.getElementById('auth-birthdate-field');
        const forgotField = document.getElementById('auth-forgot-field');
        const registerHint = document.getElementById('auth-register-hint');
        const registerLink = document.getElementById('auth-register-link');
        const loginHint = document.getElementById('auth-login-hint');
        const loginLink = document.getElementById('auth-login-link');

        function applyModeFields(mode) {
            const isRegister = mode === 'register';

            identifierField.hidden = isRegister;
            identifierInput.required = !isRegister;
            identifierInput.disabled = isRegister;

            emailField.hidden = !isRegister;
            emailInputEl.required = isRegister;
            emailInputEl.disabled = !isRegister;

            usernameField.hidden = !isRegister;
            usernameInputEl.required = isRegister;
            usernameInputEl.disabled = !isRegister;

            birthDateField.hidden = !isRegister;
            forgotField.hidden = isRegister;
            if (registerHint) registerHint.hidden = isRegister;
            if (loginHint) loginHint.hidden = !isRegister;
            [birthDayEl, birthMonthEl, birthYearEl].forEach(el => {
                el.required = isRegister;
                el.disabled = !isRegister;
            });
        }

        const welcomeTitle = document.getElementById('auth-welcome-title');
        const welcomeSubtitle = document.getElementById('auth-welcome-subtitle');

        function applyWelcomeText(mode) {
            if (!welcomeTitle || !welcomeSubtitle) return;
            if (mode === 'register') {
                welcomeTitle.textContent = 'Créer un compte';
                welcomeSubtitle.textContent = '';
            } else {
                welcomeTitle.textContent = 'Ha, te revoilà !';
                welcomeSubtitle.textContent = 'Nous sommes si heureux de te revoir !';
            }
        }

        function applyUrl(mode) {
            const target = mode === 'register' ? '/register' : '/login';
            if (window.location.pathname !== target) {
                history.pushState({}, '', target);
            }
        }

        applyModeFields(modeInput.value);
        applyWelcomeText(modeInput.value);
        applyUrl(modeInput.value);

        if (registerLink) {
            registerLink.addEventListener('click', (e) => {
                e.preventDefault();
                const registerTabBtn = document.querySelector('.auth-tab-btn[data-mode="register"]');
                if (registerTabBtn) registerTabBtn.click();
            });
        }

        if (loginLink) {
            loginLink.addEventListener('click', (e) => {
                e.preventDefault();
                const loginTabBtn = document.querySelector('.auth-tab-btn[data-mode="login"]');
                if (loginTabBtn) loginTabBtn.click();
            });
        }

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                modeInput.value = btn.dataset.mode;
                submitBtn.textContent = btn.dataset.mode === 'register' ? "Créer un compte" : 'Se connecter';
                applyModeFields(btn.dataset.mode);
                applyWelcomeText(btn.dataset.mode);
                applyUrl(btn.dataset.mode);
                checkEmailCaptchaStatus();
            });
        });

        // --- Bascule l'affichage du mot de passe (icône œil) ---
        const passwordInput = document.getElementById('auth-password');
        const passwordToggle = document.getElementById('auth-password-toggle');
        const eyeOpen = passwordToggle.querySelector('.auth-eye-open');
        const eyeClosed = passwordToggle.querySelector('.auth-eye-closed');

        passwordToggle.addEventListener('click', () => {
            const willShow = passwordInput.type === 'password';
            passwordInput.type = willShow ? 'text' : 'password';
            passwordToggle.setAttribute('aria-pressed', String(willShow));
            passwordToggle.setAttribute('aria-label', willShow ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            eyeOpen.hidden = willShow;
            eyeClosed.hidden = !willShow;
        });

        // --- Affiche le captcha uniquement si cette adresse email ne l'a jamais validé ---
        const captchaBox = document.getElementById('auth-captcha');
        let emailCheckTimer = null;

        function isValidEmail(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        }

        function getCurrentEmailValue() {
            // En inscription : le champ email dédié.
            // En connexion : le champ identifiant, seulement s'il ressemble à un email.
            if (modeInput.value === 'register') {
                return emailInputEl.value.trim();
            }
            const value = identifierInput.value.trim();
            return isValidEmail(value) ? value : '';
        }

        function checkEmailCaptchaStatus() {
            const email = getCurrentEmailValue();
            if (!isValidEmail(email)) {
                // Pas d'email reconnu : on garde le captcha visible par sécurité
                captchaBox.hidden = false;
                return;
            }
            fetch('captcha_check.php?email=' + encodeURIComponent(email))
                .then(r => r.json())
                .then(data => {
                    captchaBox.hidden = !!data.verified;
                })
                .catch(() => {
                    // En cas d'erreur réseau, on garde le captcha visible par sécurité
                    captchaBox.hidden = false;
                });
        }

        [identifierInput, emailInputEl].forEach(input => {
            input.addEventListener('input', () => {
                clearTimeout(emailCheckTimer);
                emailCheckTimer = setTimeout(checkEmailCaptchaStatus, 500);
            });
            input.addEventListener('blur', checkEmailCaptchaStatus);
        });

        // Vérifie tout de suite si une valeur était déjà pré-remplie (rechargement après erreur)
        if (getCurrentEmailValue() !== '') {
            checkEmailCaptchaStatus();
        }
        } // fin du else (mode connexion/inscription normal)
    </script>

</body>

</html>