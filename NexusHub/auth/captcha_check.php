<?php
/**
 * captcha_check.php
 * Endpoint AJAX appelé depuis login.php en JS : indique si l'adresse
 * email saisie a déjà validé le captcha, pour savoir s'il faut
 * l'afficher ou non avant même d'envoyer le formulaire.
 */
require __DIR__ . '/error_handling.php';
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/captcha_verify.php';

header('Content-Type: application/json');

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');

echo json_encode([
    'verified' => isCaptchaVerifiedForEmail($pdo, $email),
]);