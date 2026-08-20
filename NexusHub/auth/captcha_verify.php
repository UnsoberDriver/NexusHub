<?php
/**
 * captcha_verify.php
 * - Vérification de la réponse reCAPTCHA auprès de Google
 * - Suivi des adresses email ayant déjà validé le captcha, pour ne plus
 *   le montrer une fois qu'il a été réussi une fois pour cette adresse.
 */

// Vérifie la réponse reCAPTCHA auprès de Google
function verifyRecaptcha(string $secret, string $response): bool
{
    if ($response === '') {
        return false;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $secret,
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

// Normalise une adresse email (pour comparaison / stockage cohérents)
function normalizeEmail(string $email): string
{
    return mb_strtolower(trim($email));
}

// Indique si cette adresse email a déjà validé le captcha par le passé
function isCaptchaVerifiedForEmail(PDO $pdo, string $email): bool
{
    $email = normalizeEmail($email);
    if ($email === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM captcha_verified_emails WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    return (bool) $stmt->fetch();
}

// Marque cette adresse email comme ayant validé le captcha
function markCaptchaVerifiedForEmail(PDO $pdo, string $email): void
{
    $email = normalizeEmail($email);
    if ($email === '') {
        return;
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO captcha_verified_emails (email, verified_at) VALUES (?, NOW())');
    $stmt->execute([$email]);
}