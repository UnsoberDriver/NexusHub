<?php
// Exemple à appliquer dans index.php, autour des INSERT d'envoi de message
// (bloc action=send et action=send_image), pour éviter qu'une exception
// PDO non attrapée ne fasse échouer silencieusement l'envoi côté front.

try {
    $stmt = $pdo->prepare('INSERT INTO general_messages (conversation_id, user_id, content) VALUES (?, ?, ?)');
    $stmt->execute([$generalConvId, $currentUserId, $content]);
} catch (PDOException $e) {
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => "Erreur lors de l'envoi."]);
        exit;
    }
}
