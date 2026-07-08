<?php
session_start();
require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
$currentUser   = $_SESSION['username'];

// ============================================
// Fonctions utilitaires
// ============================================

function getGeneralConversationId(PDO $pdo): int {
    $row = $pdo->query("SELECT id FROM conversations WHERE type = 'general' LIMIT 1")->fetch();
    if ($row) {
        return (int) $row['id'];
    }
    $pdo->exec("INSERT INTO conversations (type) VALUES ('general')");
    return (int) $pdo->lastInsertId();
}

// Retrouve la conversation privée entre 2 utilisateurs, et la crée si besoin
function getOrCreatePrivateConversation(PDO $pdo, int $userA, int $userB, bool $create = true): ?int {
    $stmt = $pdo->prepare("
        SELECT cp1.conversation_id
        FROM conversation_participants cp1
        JOIN conversation_participants cp2 ON cp1.conversation_id = cp2.conversation_id
        JOIN conversations c ON c.id = cp1.conversation_id
        WHERE cp1.user_id = ? AND cp2.user_id = ? AND c.type = 'private'
        LIMIT 1
    ");
    $stmt->execute([$userA, $userB]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['conversation_id'];
    }
    if (!$create) {
        return null;
    }

    $pdo->exec("INSERT INTO conversations (type) VALUES ('private')");
    $conversationId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)');
    $stmt->execute([$conversationId, $userA]);
    $stmt->execute([$conversationId, $userB]);

    return $conversationId;
}

function fetchMessages(PDO $pdo, int $conversationId, string $table = 'general_messages', int $limit = 50): array {
    // $table doit toujours être 'general_messages' ou 'private_messages'
    // (jamais une valeur venant directement de l'utilisateur) pour éviter toute injection SQL.
    $table = $table === 'private_messages' ? 'private_messages' : 'general_messages';

    $stmt = $pdo->prepare("
        SELECT m.id, m.content, m.created_at, u.username, m.reply_to_id,
               ru.username AS reply_username, rm.content AS reply_content
        FROM {$table} m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN {$table} rm ON rm.id = m.reply_to_id
        LEFT JOIN users ru ON ru.id = rm.user_id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC, m.id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Vérifie qu'un message existe bien dans cette conversation (pour valider un "reply_to")
function findReplyTarget(PDO $pdo, string $table, int $conversationId, int $messageId): ?int {
    if ($messageId <= 0) {
        return null;
    }
    $table = $table === 'private_messages' ? 'private_messages' : 'general_messages';
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND conversation_id = ?");
    $stmt->execute([$messageId, $conversationId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

// Tronque un texte pour l'aperçu de la réponse citée
function replyPreviewText(string $content): string {
    if (str_starts_with($content, 'IMAGE:')) {
        return '📷 Image';
    }
    return mb_strlen($content) > 80 ? mb_substr($content, 0, 80) . '…' : $content;
}

$generalConvId = getGeneralConversationId($pdo);

// S'assure que l'utilisateur courant participe bien au chat général
$stmt = $pdo->prepare('INSERT IGNORE INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)');
$stmt->execute([$generalConvId, $currentUserId]);

// ============================================
// Upload d'images
// ============================================
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');
define('AVATAR_DIR', __DIR__ . '/avatars/');
define('AVATAR_URL', 'avatars/');

// S'assure que la colonne "avatar" existe dans la table users
// (approche tolérante : si elle existe déjà, l'erreur est simplement ignorée).
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL");
} catch (\PDOException $e) {
    // La colonne existe déjà (ou la base ne le permet pas) : on continue normalement.
}

// S'assure que la colonne "bio" existe dans la table users
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN bio VARCHAR(280) NULL");
} catch (\PDOException $e) {
    // La colonne existe déjà (ou la base ne le permet pas) : on continue normalement.
}

// ============================================
// Table des réactions (emoji sur les messages)
// ============================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS message_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            emoji VARCHAR(10) NOT NULL,
            message_type VARCHAR(20) NOT NULL DEFAULT 'general',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_reaction (message_id, user_id, emoji, message_type),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (\PDOException $e) {
    // La table existe déjà
}

// ============================================
// Gestion des réactions (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_reaction') {
    header('Content-Type: application/json');

    $messageId = (int) ($_POST['message_id'] ?? 0);
    $emoji = trim($_POST['emoji'] ?? '');
    $messageType = ($_POST['message_type'] ?? '') === 'private' ? 'private' : 'general';

    if ($messageId <= 0 || !$emoji) {
        echo json_encode(['status' => 'error', 'message' => 'Données invalides']);
        exit;
    }

    $stmt = $pdo->prepare('
        INSERT INTO message_reactions (message_id, user_id, emoji, message_type)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$messageId, $currentUserId, $emoji, $messageType]);

    echo json_encode(['status' => 'ok']);
    exit;
}

// Récupération des réactions pour un message (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_reactions') {
    header('Content-Type: application/json');

    $messageId = (int) ($_GET['message_id'] ?? 0);
    $messageType = ($_GET['message_type'] ?? '') === 'private' ? 'private' : 'general';

    if ($messageId <= 0) {
        echo json_encode([]);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT emoji, COUNT(*) as count, GROUP_CONCAT(DISTINCT user_id) as user_ids
        FROM message_reactions
        WHERE message_id = ? AND message_type = ?
        GROUP BY emoji
        ORDER BY created_at DESC
    ');
    $stmt->execute([$messageId, $messageType]);
    $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($reactions ?: []);
    exit;
}

// ============================================
// Modification de la bio
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_bio') {
    header('Content-Type: application/json');

    $bio = trim($_POST['bio'] ?? '');
    if (mb_strlen($bio) > 280) {
        $bio = mb_substr($bio, 0, 280);
    }

    $stmt = $pdo->prepare('UPDATE users SET bio = ? WHERE id = ?');
    $stmt->execute([$bio !== '' ? $bio : null, $currentUserId]);

    echo json_encode(['status' => 'ok', 'bio' => $bio]);
    exit;
}

// ============================================
// Modification de la photo de profil
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_avatar') {
    header('Content-Type: application/json');

    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => "Aucun fichier reçu."]);
        exit;
    }

    // On vérifie le vrai type MIME du fichier (pas juste l'extension donnée par le navigateur)
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowedTypes[$mime])) {
        echo json_encode(['status' => 'error', 'message' => 'Format non supporté (JPG, PNG, GIF ou WEBP uniquement).']);
        exit;
    }

    if ($_FILES['avatar']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['status' => 'error', 'message' => 'Image trop lourde (5 Mo maximum).']);
        exit;
    }

    if (!is_dir(AVATAR_DIR)) {
        mkdir(AVATAR_DIR, 0755, true);
    }

    // Nom de fichier généré côté serveur (jamais celui envoyé par le client)
    $filename = 'avatar_' . $currentUserId . '_' . uniqid('', true) . '.' . $allowedTypes[$mime];

    if (!move_uploaded_file($_FILES['avatar']['tmp_name'], AVATAR_DIR . $filename)) {
        echo json_encode(['status' => 'error', 'message' => "Échec de l'enregistrement du fichier."]);
        exit;
    }

    // Récupère l'ancien avatar pour le supprimer une fois le nouveau enregistré
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ?');
    $stmt->execute([$currentUserId]);
    $oldAvatar = $stmt->fetchColumn();

    $newAvatarUrl = AVATAR_URL . $filename;
    $stmt = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
    $stmt->execute([$newAvatarUrl, $currentUserId]);

    if ($oldAvatar && str_starts_with($oldAvatar, AVATAR_URL)) {
        $oldPath = AVATAR_DIR . basename($oldAvatar);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    echo json_encode(['status' => 'ok', 'avatar' => $newAvatarUrl]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_image') {
    header('Content-Type: application/json');

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => "Aucun fichier reçu."]);
        exit;
    }

    // On vérifie le vrai type MIME du fichier (pas juste l'extension donnée par le navigateur)
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowedTypes[$mime])) {
        echo json_encode(['status' => 'error', 'message' => 'Format non supporté (JPG, PNG, GIF ou WEBP uniquement).']);
        exit;
    }

    if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['status' => 'error', 'message' => 'Image trop lourde (5 Mo maximum).']);
        exit;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    // Nom de fichier généré côté serveur (jamais celui envoyé par le client)
    $filename = uniqid('img_', true) . '.' . $allowedTypes[$mime];

    if (!move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . $filename)) {
        echo json_encode(['status' => 'error', 'message' => "Échec de l'enregistrement du fichier."]);
        exit;
    }

    $content   = 'IMAGE:' . UPLOAD_URL . $filename;
    $type      = $_POST['type'] ?? 'general';
    $replyToId = (int) ($_POST['reply_to'] ?? 0);

    if ($type === 'mp') {
        $toId = (int) ($_POST['to'] ?? 0);
        if ($toId > 0 && $toId !== $currentUserId) {
            $conversationId = getOrCreatePrivateConversation($pdo, $currentUserId, $toId);
            $replyTo = findReplyTarget($pdo, 'private_messages', $conversationId, $replyToId);
            $stmt = $pdo->prepare('INSERT INTO private_messages (conversation_id, user_id, content, reply_to_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$conversationId, $currentUserId, $content, $replyTo]);
        }
    } else {
        $replyTo = findReplyTarget($pdo, 'general_messages', $generalConvId, $replyToId);
        $stmt = $pdo->prepare('INSERT INTO general_messages (conversation_id, user_id, content, reply_to_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$generalConvId, $currentUserId, $content, $replyTo]);
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

// ============================================
// Endpoints AJAX
// ============================================

// Liste des contacts disponibles pour les MP
if (($_GET['action'] ?? '') === 'contacts') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id != ? ORDER BY username');
    $stmt->execute([$currentUserId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// Envoi d'un message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $type      = $_POST['type'] ?? 'general';
    $content   = trim($_POST['message'] ?? '');
    $replyToId = (int) ($_POST['reply_to'] ?? 0);

    if ($content !== '') {
        if ($type === 'mp') {
            $toId = (int) ($_POST['to'] ?? 0);
            if ($toId > 0 && $toId !== $currentUserId) {
                $conversationId = getOrCreatePrivateConversation($pdo, $currentUserId, $toId);
                $replyTo = findReplyTarget($pdo, 'private_messages', $conversationId, $replyToId);
                $stmt = $pdo->prepare('INSERT INTO private_messages (conversation_id, user_id, content, reply_to_id) VALUES (?, ?, ?, ?)');
                $stmt->execute([$conversationId, $currentUserId, $content, $replyTo]);
            }
        } else {
            $replyTo = findReplyTarget($pdo, 'general_messages', $generalConvId, $replyToId);
            $stmt = $pdo->prepare('INSERT INTO general_messages (conversation_id, user_id, content, reply_to_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$generalConvId, $currentUserId, $content, $replyTo]);
        }
    }

    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    header('Location: index.php');
    exit;
}

// Récupération des messages (polling AJAX)
if (($_GET['action'] ?? '') === 'fetch') {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? 'general';

    if ($type === 'mp') {
        $toId = (int) ($_GET['to'] ?? 0);
        $conversationId = $toId > 0
            ? getOrCreatePrivateConversation($pdo, $currentUserId, $toId, false)
            : null;
        $messages = $conversationId ? fetchMessages($pdo, $conversationId, 'private_messages') : [];
    } else {
        $messages = fetchMessages($pdo, $generalConvId, 'general_messages');
    }

    echo json_encode($messages);
    exit;
}

// ============================================
// Données pour le rendu initial de la page
// ============================================
$generalMessages = fetchMessages($pdo, $generalConvId, 'general_messages');

$stmt = $pdo->prepare('SELECT id, username FROM users WHERE id != ? ORDER BY username');
$stmt->execute([$currentUserId]);
$contacts = $stmt->fetchAll();

// Infos complémentaires pour le panneau profil (MP). La colonne created_at est
// optionnelle : si elle n'existe pas dans la table users, on s'en passe simplement.
$contactProfiles = [];
foreach ($contacts as $c) {
    $contactProfiles[(int) $c['id']] = ['since' => null, 'avatar' => null, 'bio' => null];
}
try {
    $stmt = $pdo->query('SELECT id, created_at FROM users');
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['id'];
        if (isset($contactProfiles[$id]) && !empty($row['created_at'])) {
            $contactProfiles[$id]['since'] = date('d/m/Y', strtotime($row['created_at']));
        }
    }
} catch (\PDOException $e) {
    // Colonne created_at absente : le panneau affichera simplement "—".
}

// Avatar de l'utilisateur courant + avatars des contacts (pour la liste d'amis et le panneau profil)
$currentUserAvatar = null;
$currentUserBio    = '';
$currentUserSince  = null;
try {
    $stmt = $pdo->prepare('SELECT avatar, bio FROM users WHERE id = ?');
    $stmt->execute([$currentUserId]);
    $me = $stmt->fetch();
    $currentUserAvatar = $me['avatar'] ?? null;
    $currentUserBio    = $me['bio'] ?? '';

    $stmt = $pdo->query('SELECT id, avatar, bio FROM users');
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['id'];
        if (isset($contactProfiles[$id])) {
            if (!empty($row['avatar'])) {
                $contactProfiles[$id]['avatar'] = $row['avatar'];
            }
            if (!empty($row['bio'])) {
                $contactProfiles[$id]['bio'] = $row['bio'];
            }
        }
    }
} catch (\PDOException $e) {
    // Colonnes avatar/bio absentes pour une raison quelconque : on affiche les valeurs par défaut.
}
try {
    $stmt = $pdo->prepare('SELECT created_at FROM users WHERE id = ?');
    $stmt->execute([$currentUserId]);
    $createdAt = $stmt->fetchColumn();
    if ($createdAt) {
        $currentUserSince = date('d/m/Y', strtotime($createdAt));
    }
} catch (\PDOException $e) {
    // Colonne created_at absente : on affichera simplement "—".
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chat - Général & MP</title>
<link rel="stylesheet" href="styles.css">
</head>
<body>

<nav class="navbar">
    <div class="navbar-inner">
        <a href="index.php" class="navbar-brand">Torme</a>
    </div>
</nav>

<input type="file" id="navbar-avatar-input" accept="image/*" hidden>

<!-- MENU CONTEXTUEL (clic droit sur un message) : répondre / copier -->
<div class="msg-context-menu" id="msg-context-menu" hidden>
    <button type="button" class="msg-context-item" data-action="reply">↩️ Répondre</button>
    <button type="button" class="msg-context-item" data-action="copy">📋 Copier le message</button>
</div>

<!-- TOOLBAR EMOJI (au survol d'un message) -->
<div class="msg-toolbar" id="msg-toolbar" hidden>
    <button type="button" class="msg-reaction-btn" data-emoji="❤️" title="J'aime">❤️</button>
    <button type="button" class="msg-reaction-btn" data-emoji="😂" title="Marrant">😂</button>
    <button type="button" class="msg-reaction-btn" data-emoji="😮" title="Wow">😮</button>
    <button type="button" class="msg-reaction-btn" data-emoji="😢" title="Triste">😢</button>
    <button type="button" class="msg-reaction-btn" data-emoji="👍" title="Bien">👍</button>
    <button type="button" class="msg-reaction-btn" data-emoji="👎" title="Pas bien">👎</button>
</div>

<!-- MODALE : MON PROFIL -->
<div class="own-profile-overlay" id="own-profile-overlay" hidden>
    <aside class="mp-profile own-profile-card" id="own-profile-card">
        <button type="button" class="mp-profile-close" id="own-profile-close" aria-label="Fermer le profil">✕</button>

        <button type="button" class="mp-profile-avatar own-profile-avatar-btn" id="own-profile-avatar-btn" title="Modifier ma photo de profil" aria-label="Modifier ma photo de profil">
            <?php if ($currentUserAvatar): ?>
                <img src="<?= htmlspecialchars($currentUserAvatar) ?>" alt="Ma photo de profil" id="own-profile-avatar-img">
            <?php else: ?>
                <span id="own-profile-avatar-initial"><?= htmlspecialchars(mb_strtoupper(mb_substr($currentUser, 0, 1))) ?></span>
            <?php endif; ?>
            <span class="own-profile-avatar-edit">📷</span>
        </button>

        <div class="mp-profile-username"><?= htmlspecialchars($currentUser) ?></div>
        <div class="mp-profile-status">
            <span class="mp-profile-status-dot"></span>
            <span>En ligne</span>
        </div>

        <div class="mp-profile-section">
            <div class="mp-profile-section-title own-profile-section-title">
                À propos
                <button type="button" class="own-profile-edit-btn" id="own-profile-bio-edit-btn" aria-label="Modifier ma bio">✎</button>
            </div>
            <div class="mp-profile-section-body" id="own-profile-bio-display" data-raw="<?= htmlspecialchars($currentUserBio) ?>"><?= $currentUserBio !== '' ? htmlspecialchars($currentUserBio) : "Aucune bio pour l'instant." ?></div>
            <div class="own-profile-bio-edit" id="own-profile-bio-edit" hidden>
                <textarea id="own-profile-bio-textarea" maxlength="280" rows="3" placeholder="Parle un peu de toi..."><?= htmlspecialchars($currentUserBio) ?></textarea>
                <div class="own-profile-bio-actions">
                    <span class="navbar-bio-count" id="own-profile-bio-count"><?= mb_strlen($currentUserBio) ?>/280</span>
                    <button type="button" class="navbar-bio-cancel" id="own-profile-bio-cancel">Annuler</button>
                    <button type="button" class="navbar-bio-save" id="own-profile-bio-save">Enregistrer</button>
                </div>
            </div>
        </div>

        <div class="mp-profile-section">
            <div class="mp-profile-section-title">Membre depuis</div>
            <div class="mp-profile-section-body"><?= $currentUserSince ? htmlspecialchars($currentUserSince) : '—' ?></div>
        </div>
    </aside>
</div>

<div class="page-content">
<div class="chat-app">

    <div class="chat-body">

        <nav class="chat-tabs">
            <button class="tab-btn active" data-tab="general" title="Chat général">💬</button>
            <button class="tab-btn" data-tab="mp" title="Messages privés">✉️</button>
        </nav>

        <aside class="friends-list" id="friends-list">
            <div class="friends-list-header">Amis</div>
            <div class="friends-list-items">
                <?php foreach ($contacts as $c): ?>
                    <?php $cAvatar = $contactProfiles[(int) $c['id']]['avatar'] ?? null; ?>
                    <button type="button" class="friend-item" data-id="<?= (int) $c['id'] ?>" data-username="<?= htmlspecialchars($c['username'], ENT_QUOTES) ?>">
                        <span class="friend-avatar">
                            <?php if ($cAvatar): ?>
                                <img src="<?= htmlspecialchars($cAvatar) ?>" alt="">
                            <?php else: ?>
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($c['username'], 0, 1))) ?>
                            <?php endif; ?>
                        </span>
                        <span class="friend-name"><?= htmlspecialchars($c['username']) ?></span>
                    </button>
                <?php endforeach; ?>
                <?php if (empty($contacts)): ?>
                    <div class="friends-list-empty">Aucun ami pour l'instant</div>
                <?php endif; ?>
            </div>

            <div class="user-panel">
                <button type="button" class="user-panel-trigger" id="navbar-profile-trigger" title="Mon profil" aria-label="Mon profil">
                    <span class="navbar-avatar" id="navbar-avatar-display">
                        <?php if ($currentUserAvatar): ?>
                            <img src="<?= htmlspecialchars($currentUserAvatar) ?>" alt="Ma photo de profil" id="navbar-avatar-img">
                        <?php else: ?>
                            <span id="navbar-avatar-initial"><?= htmlspecialchars(mb_strtoupper(mb_substr($currentUser, 0, 1))) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="user-panel-name"><?= htmlspecialchars($currentUser) ?></span>
                </button>
                <button type="button" class="user-panel-theme-toggle" id="theme-toggle" title="Basculer le mode sombre" aria-label="Basculer le mode sombre">🌙</button>
                <a href="logout.php" class="user-panel-logout" title="Déconnexion" aria-label="Déconnexion">⏻</a>
            </div>
        </aside>

        <div class="chat-panels">

        <!-- ONGLET CHAT GÉNÉRAL -->
        <section id="tab-general" class="tab-content active">
            <div class="panel-header">
                <button type="button" class="btn-back" aria-label="Retour à la liste des discussions">←</button>
                <span class="panel-title">💬 Chat général</span>
            </div>
            <div class="messages" id="general-messages">
                <?php foreach ($generalMessages as $m): ?>
                    <div class="message <?= $m['username'] === $currentUser ? 'own' : '' ?>" data-id="<?= (int) $m['id'] ?>">
                        <span class="msg-user"><?= htmlspecialchars($m['username']) ?></span>
                        <?php if ($m['reply_to_id']): ?>
                            <div class="msg-reply-preview" data-goto-id="<?= (int) $m['reply_to_id'] ?>">
                                <span class="msg-reply-preview-user"><?= htmlspecialchars($m['reply_username'] ?? '?') ?></span>
                                <span class="msg-reply-preview-text"><?= htmlspecialchars(replyPreviewText($m['reply_content'] ?? '')) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (str_starts_with($m['content'], 'IMAGE:')): ?>
                            <a href="<?= htmlspecialchars(substr($m['content'], 6)) ?>" target="_blank" rel="noopener" class="msg-image-link">
                                <img src="<?= htmlspecialchars(substr($m['content'], 6)) ?>" alt="Image envoyée" class="msg-image" loading="lazy">
                            </a>
                        <?php else: ?>
                            <span class="msg-text"><?= htmlspecialchars($m['content']) ?></span>
                        <?php endif; ?>
                        <span class="msg-time"><?php 
                            $dt = new DateTime($m['created_at']);
                            echo $dt->format('H:i:s');
                        ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="reply-bar" id="reply-bar-general" hidden>
                <div class="reply-bar-info">
                    <span class="reply-bar-label">Réponse à <span class="reply-bar-user"></span></span>
                    <span class="reply-bar-text"></span>
                </div>
                <button type="button" class="reply-bar-cancel" aria-label="Annuler la réponse">✕</button>
            </div>
            <form class="chat-form" id="form-general">
                <input type="hidden" name="type" value="general">
                <input type="hidden" name="reply_to" value="">
                <button type="button" class="btn-attach" title="Envoyer une image" aria-label="Envoyer une image">+</button>
                <input type="file" class="file-input" accept="image/*" hidden>
                <input type="text" name="message" placeholder="Écrire dans le chat général..." autocomplete="off" required>
                <div class="gif-wrapper">
                    <button type="button" class="btn-gif" aria-label="Envoyer un GIF">GIF</button>
                    <div class="gif-picker" hidden>
                        <input type="text" class="gif-search" placeholder="Rechercher un GIF..." autocomplete="off">
                        <div class="gif-results"></div>
                    </div>
                </div>
                <div class="emoji-wrapper">
                    <button type="button" class="btn-emoji" aria-label="Insérer un émoji">😊</button>
                    <div class="emoji-picker" hidden>
                        <button type="button">😀</button>
                        <button type="button">😂</button>
                        <button type="button">😍</button>
                        <button type="button">😢</button>
                        <button type="button">😮</button>
                        <button type="button">😡</button>
                        <button type="button">👍</button>
                        <button type="button">👎</button>
                        <button type="button">🙏</button>
                        <button type="button">🎉</button>
                        <button type="button">❤️</button>
                        <button type="button">🔥</button>
                        <button type="button">💯</button>
                        <button type="button">🥳</button>
                        <button type="button">😴</button>
                        <button type="button">🤔</button>
                        <button type="button">👋</button>
                        <button type="button">✅</button>
                        <button type="button">😅</button>
                        <button type="button">😎</button>
                        <button type="button">🥰</button>
                        <button type="button">😭</button>
                        <button type="button">👏</button>
                        <button type="button">🚀</button>
                    </div>
                </div>
                <button type="submit">Envoyer</button>
            </form>
        </section>

        <!-- ONGLET MESSAGES PRIVÉS -->
        <section id="tab-mp" class="tab-content">
            <div class="panel-header">
                <button type="button" class="btn-back" aria-label="Retour à la liste des discussions">←</button>
                <span class="panel-title">✉️ Messages privés</span>
                <button type="button" class="btn-profile-toggle" id="mp-profile-toggle" title="Infos du profil" aria-label="Infos du profil" hidden>ⓘ</button>
            </div>

            <div class="mp-layout">
                <div class="mp-main">
                    <div class="mp-contact-select">
                        <select id="mp-contact">
                            <option value="">— Choisir un contact —</option>
                            <?php foreach ($contacts as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="messages" id="mp-messages"></div>

                    <div class="reply-bar" id="reply-bar-mp" hidden>
                        <div class="reply-bar-info">
                            <span class="reply-bar-label">Réponse à <span class="reply-bar-user"></span></span>
                            <span class="reply-bar-text"></span>
                        </div>
                        <button type="button" class="reply-bar-cancel" aria-label="Annuler la réponse">✕</button>
                    </div>
                    <form class="chat-form" id="form-mp">
                        <input type="hidden" name="type" value="mp">
                        <input type="hidden" name="to" id="mp-to-input" value="">
                        <input type="hidden" name="reply_to" value="">
                        <button type="button" class="btn-attach" title="Envoyer une image" aria-label="Envoyer une image" disabled>+</button>
                        <input type="file" class="file-input" accept="image/*" hidden>
                        <input type="text" name="message" placeholder="Écrire un message privé..." autocomplete="off" required disabled>
                        <div class="gif-wrapper">
                            <button type="button" class="btn-gif" aria-label="Envoyer un GIF" disabled>GIF</button>
                            <div class="gif-picker" hidden>
                                <input type="text" class="gif-search" placeholder="Rechercher un GIF..." autocomplete="off">
                                <div class="gif-results"></div>
                            </div>
                        </div>
                        <div class="emoji-wrapper">
                            <button type="button" class="btn-emoji" aria-label="Insérer un émoji" disabled>😊</button>
                            <div class="emoji-picker" hidden>
                                <button type="button">😀</button>
                                <button type="button">😂</button>
                                <button type="button">😍</button>
                                <button type="button">😢</button>
                                <button type="button">😮</button>
                                <button type="button">😡</button>
                                <button type="button">👍</button>
                                <button type="button">👎</button>
                                <button type="button">🙏</button>
                                <button type="button">🎉</button>
                                <button type="button">❤️</button>
                                <button type="button">🔥</button>
                                <button type="button">💯</button>
                                <button type="button">🥳</button>
                                <button type="button">😴</button>
                                <button type="button">🤔</button>
                                <button type="button">👋</button>
                                <button type="button">✅</button>
                                <button type="button">😅</button>
                                <button type="button">😎</button>
                                <button type="button">🥰</button>
                                <button type="button">😭</button>
                                <button type="button">👏</button>
                                <button type="button">🚀</button>
                            </div>
                        </div>
                        <button type="submit" disabled>Envoyer</button>
                    </form>
                </div>

                <aside class="mp-profile" id="mp-profile" hidden>
                    <button type="button" class="mp-profile-close" id="mp-profile-close" aria-label="Fermer le profil">✕</button>
                    <div class="mp-profile-avatar" id="mp-profile-avatar"></div>
                    <div class="mp-profile-username" id="mp-profile-username"></div>
                    <div class="mp-profile-status">
                        <span class="mp-profile-status-dot"></span>
                        <span id="mp-profile-status-text">En ligne</span>
                    </div>

                    <div class="mp-profile-section">
                        <div class="mp-profile-section-title">À propos</div>
                        <div class="mp-profile-section-body" id="mp-profile-about">Aucune bio pour l'instant.</div>
                    </div>

                    <div class="mp-profile-section">
                        <div class="mp-profile-section-title">Membre depuis</div>
                        <div class="mp-profile-section-body" id="mp-profile-since">—</div>
                    </div>
                </aside>
            </div>
        </section>

        </div>
    </div>
</div>
</div>

<script>
const currentUser = <?= json_encode($currentUser) ?>;
const contactProfiles = <?= json_encode($contactProfiles) ?>;

// --- Modale "Mon profil" (photo + bio) ---
const ownProfileTrigger  = document.getElementById('navbar-profile-trigger');
const ownProfileOverlay  = document.getElementById('own-profile-overlay');
const ownProfileCard     = document.getElementById('own-profile-card');
const ownProfileClose    = document.getElementById('own-profile-close');
const ownProfileAvatarBtn = document.getElementById('own-profile-avatar-btn');
const navbarAvatarInput  = document.getElementById('navbar-avatar-input');

function openOwnProfile() {
    ownProfileOverlay.hidden = false;
}

function closeOwnProfile() {
    ownProfileOverlay.hidden = true;
    closeOwnBioEdit();
}

ownProfileTrigger.addEventListener('click', openOwnProfile);
ownProfileClose.addEventListener('click', closeOwnProfile);

ownProfileOverlay.addEventListener('click', (e) => {
    if (e.target === ownProfileOverlay) closeOwnProfile();
});

// Changement de photo depuis la modale
ownProfileAvatarBtn.addEventListener('click', () => {
    navbarAvatarInput.click();
});

navbarAvatarInput.addEventListener('change', async () => {
    const file = navbarAvatarInput.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('action', 'update_avatar');
    formData.append('avatar', file);

    try {
        const res = await fetch('index.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.status !== 'ok') {
            alert(data.message || "Erreur lors de l'envoi de la photo.");
            return;
        }

        const newSrc = data.avatar + '?t=' + Date.now();

        // Met à jour la photo dans la modale
        let modalImg = document.getElementById('own-profile-avatar-img');
        const modalInitial = document.getElementById('own-profile-avatar-initial');
        if (!modalImg) {
            modalImg = document.createElement('img');
            modalImg.id = 'own-profile-avatar-img';
            modalImg.alt = 'Ma photo de profil';
            ownProfileAvatarBtn.insertBefore(modalImg, ownProfileAvatarBtn.firstChild);
            if (modalInitial) modalInitial.remove();
        }
        modalImg.src = newSrc;

        // Met à jour la photo dans la navbar
        const navbarAvatarDisplay = document.getElementById('navbar-avatar-display');
        let navImg = document.getElementById('navbar-avatar-img');
        const navInitial = document.getElementById('navbar-avatar-initial');
        if (!navImg) {
            navImg = document.createElement('img');
            navImg.id = 'navbar-avatar-img';
            navImg.alt = 'Ma photo de profil';
            navbarAvatarDisplay.insertBefore(navImg, navbarAvatarDisplay.firstChild);
            if (navInitial) navInitial.remove();
        }
        navImg.src = newSrc;
    } catch (err) {
        console.error('Erreur envoi avatar :', err);
        alert("Erreur lors de l'envoi de la photo : " + err.message);
    } finally {
        navbarAvatarInput.value = '';
    }
});

// Édition de la bio depuis la modale
const ownProfileBioDisplay  = document.getElementById('own-profile-bio-display');
const ownProfileBioEditBtn  = document.getElementById('own-profile-bio-edit-btn');
const ownProfileBioEdit     = document.getElementById('own-profile-bio-edit');
const ownProfileBioTextarea = document.getElementById('own-profile-bio-textarea');
const ownProfileBioCount    = document.getElementById('own-profile-bio-count');
const ownProfileBioCancel   = document.getElementById('own-profile-bio-cancel');
const ownProfileBioSave     = document.getElementById('own-profile-bio-save');

function openOwnBioEdit() {
    ownProfileBioDisplay.hidden = true;
    ownProfileBioEdit.hidden = false;
    ownProfileBioTextarea.focus();
}

function closeOwnBioEdit() {
    ownProfileBioEdit.hidden = true;
    ownProfileBioDisplay.hidden = false;
}

ownProfileBioEditBtn.addEventListener('click', openOwnBioEdit);
ownProfileBioCancel.addEventListener('click', () => {
    ownProfileBioTextarea.value = ownProfileBioDisplay.dataset.raw || '';
    closeOwnBioEdit();
});

ownProfileBioTextarea.addEventListener('input', () => {
    ownProfileBioCount.textContent = ownProfileBioTextarea.value.length + '/280';
});

ownProfileBioSave.addEventListener('click', async () => {
    const bio = ownProfileBioTextarea.value.trim();
    ownProfileBioSave.disabled = true;

    const formData = new FormData();
    formData.append('action', 'update_bio');
    formData.append('bio', bio);

    try {
        const res = await fetch('index.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status !== 'ok') {
            alert(data.message || "Erreur lors de l'enregistrement de la bio.");
            return;
        }
        ownProfileBioDisplay.textContent = data.bio !== '' ? data.bio : "Aucune bio pour l'instant.";
        ownProfileBioDisplay.dataset.raw = data.bio;
        ownProfileBioTextarea.value = data.bio;
        ownProfileBioCount.textContent = data.bio.length + '/280';
        closeOwnBioEdit();
    } catch (err) {
        console.error('Erreur enregistrement bio :', err);
        alert("Erreur lors de l'enregistrement de la bio : " + err.message);
    } finally {
        ownProfileBioSave.disabled = false;
    }
});

// --- Gestion des onglets ---
const tabBtns = document.querySelectorAll('.tab-btn');
const tabContents = document.querySelectorAll('.tab-content');
const chatApp = document.querySelector('.chat-app');

function openTab(tab) {
    tabBtns.forEach(b => b.classList.remove('active'));
    tabContents.forEach(c => c.classList.remove('active'));
    document.querySelector('.tab-btn[data-tab="' + tab + '"]').classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
    chatApp.classList.add('discussion-open');
}

tabBtns.forEach(btn => {
    btn.addEventListener('click', () => openTab(btn.dataset.tab));
});

document.querySelectorAll('.btn-back').forEach(btn => {
    btn.addEventListener('click', () => {
        chatApp.classList.remove('discussion-open');
    });
});

// --- Sélection du contact pour les MP ---
const mpSelect    = document.getElementById('mp-contact');
const mpToInput   = document.getElementById('mp-to-input');
const mpForm      = document.getElementById('form-mp');
const mpInput     = mpForm.querySelector('input[name="message"]');
const mpButton    = mpForm.querySelector('button[type="submit"]');
const mpEmojiBtn  = mpForm.querySelector('.btn-emoji');
const mpAttachBtn = mpForm.querySelector('.btn-attach');
const mpGifBtn    = mpForm.querySelector('.btn-gif');

mpSelect.addEventListener('change', () => {
    mpToInput.value = mpSelect.value;
    const enabled = mpSelect.value !== '';
    mpInput.disabled = !enabled;
    mpButton.disabled = !enabled;
    mpEmojiBtn.disabled = !enabled;
    mpAttachBtn.disabled = !enabled;
    mpGifBtn.disabled = !enabled;
    clearReplyTarget('form-mp');
    updateMpProfile();
    refreshMessages();
});

// --- Panneau profil du contact sélectionné (MP) ---
const mpProfile        = document.getElementById('mp-profile');
const mpProfileAvatar  = document.getElementById('mp-profile-avatar');
const mpProfileName    = document.getElementById('mp-profile-username');
const mpProfileAbout   = document.getElementById('mp-profile-about');
const mpProfileSince   = document.getElementById('mp-profile-since');
const mpProfileToggle  = document.getElementById('mp-profile-toggle');
const mpProfileClose   = document.getElementById('mp-profile-close');

function updateMpProfile() {
    const id = mpSelect.value;
    if (!id) {
        mpProfile.hidden = true;
        mpProfileToggle.hidden = true;
        return;
    }

    const username = mpSelect.options[mpSelect.selectedIndex].textContent;
    const profile = contactProfiles[id];

    mpProfileAvatar.innerHTML = '';
    if (profile && profile.avatar) {
        const img = document.createElement('img');
        img.src = profile.avatar;
        img.alt = '';
        mpProfileAvatar.appendChild(img);
    } else {
        mpProfileAvatar.textContent = username.charAt(0).toUpperCase();
    }
    mpProfileName.textContent = username;

    mpProfileAbout.textContent = (profile && profile.bio) ? profile.bio : "Aucune bio pour l'instant.";
    mpProfileSince.textContent = (profile && profile.since) ? profile.since : '—';

    mpProfileToggle.hidden = false;
    // Sur desktop le panneau reste ouvert par défaut ; sur mobile on le garde fermé
    // jusqu'à ce que l'utilisateur clique sur le bouton ⓘ.
    mpProfile.hidden = window.matchMedia('(max-width: 900px)').matches;
}

mpProfileToggle.addEventListener('click', () => {
    mpProfile.hidden = !mpProfile.hidden;
});

mpProfileClose.addEventListener('click', () => {
    mpProfile.hidden = true;
});

// --- Liste d'amis : clic = ouvre la conversation privée avec ce contact ---
document.querySelectorAll('.friend-item').forEach(item => {
    item.addEventListener('click', () => {
        const id = item.dataset.id;
        mpSelect.value = id;
        mpSelect.dispatchEvent(new Event('change'));
        openTab('mp');
    });
});

// --- Sélecteur d'émojis ---
document.querySelectorAll('.emoji-wrapper').forEach(wrapper => {
    const toggleBtn = wrapper.querySelector('.btn-emoji');
    const picker = wrapper.querySelector('.emoji-picker');
    const form = wrapper.closest('form');
    const input = form.querySelector('input[name="message"]');

    toggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const wasOpen = !picker.hidden;
        document.querySelectorAll('.emoji-picker').forEach(p => p.hidden = true);
        picker.hidden = wasOpen;
    });

    picker.addEventListener('click', (e) => e.stopPropagation());

    picker.querySelectorAll('button').forEach(emojiBtn => {
        emojiBtn.addEventListener('click', () => {
            input.value += emojiBtn.textContent;
            input.focus();
            picker.hidden = true;
        });
    });
});

document.addEventListener('click', () => {
    document.querySelectorAll('.emoji-picker, .gif-picker').forEach(p => p.hidden = true);
});

// --- Toolbar emoji au survol + Menu contextuel (clic droit) ---
const contextMenu = document.getElementById('msg-context-menu');
const toolbar = document.getElementById('msg-toolbar');
let contextMenuTarget = null;
let toolbarTimeout = null;

async function copyMessageContent(messageDiv) {
    const img = messageDiv.querySelector('.msg-image');
    const textSpan = messageDiv.querySelector('.msg-text');
    const text = img ? img.src : (textSpan ? textSpan.textContent : '');

    try {
        await navigator.clipboard.writeText(text);
    } catch (err) {
        const tmp = document.createElement('textarea');
        tmp.value = text;
        tmp.style.position = 'fixed';
        tmp.style.opacity = '0';
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
    }
}

function closeContextMenu() {
    contextMenu.hidden = true;
    contextMenuTarget = null;
}

function closeToolbar() {
    toolbar.hidden = true;
}

function openContextMenu(x, y, messageDiv, formId) {
    contextMenuTarget = { messageDiv, formId };
    contextMenu.hidden = false;
    closeToolbar();

    const rect = contextMenu.getBoundingClientRect();
    const maxX = window.innerWidth - rect.width - 6;
    const maxY = window.innerHeight - rect.height - 6;
    contextMenu.style.left = Math.max(6, Math.min(x, maxX)) + 'px';
    contextMenu.style.top = Math.max(6, Math.min(y, maxY)) + 'px';
}

function openToolbar(messageDiv) {
    closeContextMenu();
    toolbar.hidden = false;

    // Force le reflow pour que la toolbar soit rendred et qu'on puisse obtenir ses dimensions
    void toolbar.offsetHeight;

    // Positionnement : en haut à droite du message
    const msgRect = messageDiv.getBoundingClientRect();
    const toolbarRect = toolbar.getBoundingClientRect();
    
    // X : aligné à droite du message
    let x = msgRect.right - toolbarRect.width - 4;
    // Y : en haut du message
    let y = msgRect.top - 4;

    // Reste dans les limites de l'écran
    x = Math.max(6, Math.min(x, window.innerWidth - toolbarRect.width - 6));
    y = Math.max(6, y);

    toolbar.style.left = x + 'px';
    toolbar.style.top = y + 'px';
}

// Afficher la toolbar au survol du message
document.addEventListener('mouseover', (e) => {
    const messageDiv = e.target.closest('.message');
    if (!messageDiv) return;

    clearTimeout(toolbarTimeout);
    openToolbar(messageDiv);
});

// Masquer la toolbar au départ de la souris du message ET de la toolbar
document.addEventListener('mouseout', (e) => {
    const messageDiv = e.target.closest('.message');
    const toolbarEl = e.target.closest('.msg-toolbar');
    
    // Si on sort du message ET pas vers la toolbar
    if (messageDiv && !toolbarEl) {
        clearTimeout(toolbarTimeout);
        toolbarTimeout = setTimeout(() => {
            closeToolbar();
        }, 100);
    }
    
    // Si on sort de la toolbar AND pas vers un message
    if (toolbarEl && !e.target.closest('.message')) {
        clearTimeout(toolbarTimeout);
        toolbarTimeout = setTimeout(() => {
            closeToolbar();
        }, 100);
    }
});

// Clic droit sur un message
document.querySelectorAll('.messages').forEach(container => {
    container.addEventListener('contextmenu', (e) => {
        const messageDiv = e.target.closest('.message');
        if (!messageDiv) return;
        e.preventDefault();
        const formId = container.id === 'mp-messages' ? 'form-mp' : 'form-general';
        openContextMenu(e.clientX, e.clientY, messageDiv, formId);
    });
});

// Clic sur un item du menu contextuel
contextMenu.addEventListener('click', async (e) => {
    const item = e.target.closest('.msg-context-item');
    if (!item || !contextMenuTarget) return;

    const { messageDiv, formId } = contextMenuTarget;

    if (item.dataset.action === 'reply') {
        setReplyTarget(formId, messageDiv);
    } else if (item.dataset.action === 'copy') {
        await copyMessageContent(messageDiv);
    }

    closeContextMenu();
});

// Clic sur un emoji de la toolbar
toolbar.addEventListener('click', (e) => {
    const btn = e.target.closest('.msg-reaction-btn');
    if (!btn) return;

    const emoji = btn.dataset.emoji;
    
    // Trouver le message associé (on regarde le dernier message survolé)
    const messageDiv = document.querySelector('.message:hover');
    if (!messageDiv) return;
    
    const messageId = parseInt(messageDiv.dataset.id);
    const container = messageDiv.closest('.messages');
    const messageType = container && container.id === 'mp-messages' ? 'private' : 'general';
    
    // Ajouter la réaction
    addReaction(messageId, emoji, messageType);
    
    // Feedback visuel
    const original = btn.style.background;
    btn.style.background = '#f0f2f5';
    setTimeout(() => {
        btn.style.background = original;
    }, 200);
});

// Fermer menus au clic ailleurs
document.addEventListener('click', (e) => {
    if (!contextMenu.hidden && !contextMenu.contains(e.target) && !e.target.closest('.message')) {
        closeContextMenu();
    }
    if (!toolbar.hidden && !toolbar.contains(e.target) && !e.target.closest('.message')) {
        closeToolbar();
    }
});

// Fermer menus au scroll
document.addEventListener('scroll', () => {
    closeContextMenu();
    closeToolbar();
}, true);

// Fermer menus à l'Échap
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeContextMenu();
        closeToolbar();
    }
});

// Répondre à un message ---
// Associe chaque formulaire à sa barre de réponse (id du form -> id de la reply-bar)
const replyBarIds = { 'form-general': 'reply-bar-general', 'form-mp': 'reply-bar-mp' };

function getReplyBar(formId) {
    return document.getElementById(replyBarIds[formId]);
}

function setReplyTarget(formId, messageDiv) {
    const form = document.getElementById(formId);
    const bar = getReplyBar(formId);
    if (!form || !bar) return;

    const username = messageDiv.querySelector('.msg-user')?.textContent || '?';
    const img = messageDiv.querySelector('.msg-image');
    const textSpan = messageDiv.querySelector('.msg-text');
    const text = img ? '📷 Image' : (textSpan ? textSpan.textContent : '');

    form.querySelector('input[name="reply_to"]').value = messageDiv.dataset.id;
    bar.querySelector('.reply-bar-user').textContent = username;
    bar.querySelector('.reply-bar-text').textContent = text;
    bar.hidden = false;

    form.querySelector('input[name="message"]')?.focus();
}

function clearReplyTarget(formId) {
    const form = document.getElementById(formId);
    const bar = getReplyBar(formId);
    if (!form || !bar) return;

    form.querySelector('input[name="reply_to"]').value = '';
    bar.hidden = true;
}

document.querySelectorAll('.reply-bar-cancel').forEach(btn => {
    btn.addEventListener('click', () => {
        const bar = btn.closest('.reply-bar');
        const formId = Object.keys(replyBarIds).find(key => replyBarIds[key] === bar.id);
        if (formId) clearReplyTarget(formId);
    });
});

// --- Clic sur une citation : défile jusqu'au message original (s'il est encore chargé) ---
document.addEventListener('click', (e) => {
    const preview = e.target.closest('.msg-reply-preview');
    if (!preview) return;

    const container = preview.closest('.messages');
    const target = container?.querySelector(`.message[data-id="${preview.dataset.gotoId}"]`);
    if (!target) return;

    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.classList.add('highlight');
    setTimeout(() => target.classList.remove('highlight'), 1200);
});

// --- Envoi AJAX des messages ---
function setupForm(formId) {
    const form = document.getElementById(formId);
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        formData.append('action', 'send');
        formData.append('ajax', '1');

        await fetch('index.php', { method: 'POST', body: formData });
        form.querySelector('input[name="message"]').value = '';
        clearReplyTarget(formId);
        await refreshMessages();
    });
}

setupForm('form-general');
setupForm('form-mp');

// --- Envoi d'images depuis la galerie ---
function setupImageUpload(formId) {
    const form = document.getElementById(formId);
    const attachBtn = form.querySelector('.btn-attach');
    const fileInput = form.querySelector('.file-input');
    const textInput = form.querySelector('input[name="message"]');

    attachBtn.addEventListener('click', () => fileInput.click());

    async function sendImageFile(file) {
        const formData = new FormData();
        formData.append('action', 'send_image');
        formData.append('type', form.querySelector('input[name="type"]').value);
        const toInput = form.querySelector('input[name="to"]');
        if (toInput) {
            formData.append('to', toInput.value);
        }
        const replyInput = form.querySelector('input[name="reply_to"]');
        if (replyInput) {
            formData.append('reply_to', replyInput.value);
        }
        formData.append('image', file);

        attachBtn.disabled = true;
        attachBtn.textContent = '…';

        try {
            const res = await fetch('index.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status !== 'ok') {
                alert(data.message || "Erreur lors de l'envoi de l'image.");
            } else {
                clearReplyTarget(formId);
                await refreshMessages();
            }
        } catch (err) {
            alert("Erreur lors de l'envoi de l'image.");
        } finally {
            attachBtn.disabled = false;
            attachBtn.textContent = '+';
            fileInput.value = '';
        }
    }

    fileInput.addEventListener('change', async () => {
        const file = fileInput.files[0];
        if (!file) return;
        await sendImageFile(file);
    });

    // --- Coller une image depuis le presse-papier (Ctrl+V) ---
    textInput.addEventListener('paste', async (e) => {
        const items = e.clipboardData?.items;
        if (!items) return;

        for (const item of items) {
            if (item.type.startsWith('image/')) {
                e.preventDefault();
                const file = item.getAsFile();
                if (file) {
                    await sendImageFile(file);
                }
                return; // on ne traite que la première image trouvée
            }
        }
        // Sinon (texte), on laisse le comportement de collage par défaut du navigateur
    });
}

setupImageUpload('form-general');
setupImageUpload('form-mp');


// --- GIFs (Giphy) ---
// Clé publique de démo Giphy (limitée en volume). Pour la production,
// créez votre propre clé gratuite sur https://developers.giphy.com/ et remplacez-la ici.
const GIPHY_API_KEY = '0blXQa1uUs3pf9UwEx2KVjmKmSuQFLuw';

function setupGifPicker(formId) {
    const form = document.getElementById(formId);
    const wrapper = form.querySelector('.gif-wrapper');
    const toggleBtn = wrapper.querySelector('.btn-gif');
    const picker = wrapper.querySelector('.gif-picker');
    const searchInput = wrapper.querySelector('.gif-search');
    const resultsBox = wrapper.querySelector('.gif-results');

    let searchTimeout = null;

    async function searchGifs(query) {
        resultsBox.innerHTML = '<div class="gif-loading">⚠️ Les GIFs ne sont pas disponibles en HTTP. Veuillez utiliser HTTPS.</div>';
        return;

            resultsBox.innerHTML = '';
            if (!data.data || data.data.length === 0) {
                resultsBox.innerHTML = '<div class="gif-loading">Aucun résultat</div>';
                return;
            }

            data.data.forEach(gif => {
                const thumbUrl = gif.images.fixed_width_small?.url || gif.images.fixed_width.url;
                const fullUrl = gif.images.original.url;

                const img = document.createElement('img');
                img.src = thumbUrl;
                img.alt = gif.title || 'GIF';
                img.loading = 'lazy';
                img.addEventListener('click', () => sendGif(form, fullUrl));

                resultsBox.appendChild(img);
            });
        } catch (err) {
            resultsBox.innerHTML = '<div class="gif-loading">Erreur de chargement</div>';
        }
    }

    async function sendGif(form, url) {
        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('ajax', '1');
        formData.append('type', form.querySelector('input[name="type"]').value);
        const toInput = form.querySelector('input[name="to"]');
        if (toInput) {
            formData.append('to', toInput.value);
        }
        const replyInput = form.querySelector('input[name="reply_to"]');
        if (replyInput) {
            formData.append('reply_to', replyInput.value);
        }
        formData.append('message', 'IMAGE:' + url);

        try {
            const res = await fetch('index.php', { method: 'POST', body: formData });
            if (!res.ok) {
                console.error('Erreur envoi GIF, statut HTTP :', res.status);
                alert('Erreur lors de l\'envoi du GIF (statut ' + res.status + ')');
                return;
            }
            picker.hidden = true;
            clearReplyTarget(form.id);
            await refreshMessages();
        } catch (err) {
            console.error('Erreur envoi GIF :', err);
            alert('Erreur lors de l\'envoi du GIF : ' + err.message);
        }
    }

    toggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const wasOpen = !picker.hidden;
        document.querySelectorAll('.emoji-picker, .gif-picker').forEach(p => p.hidden = true);
        picker.hidden = wasOpen;
        if (!wasOpen) {
            searchInput.value = '';
            searchInput.focus();
            searchGifs('');
        }
    });

    picker.addEventListener('click', (e) => e.stopPropagation());

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => searchGifs(searchInput.value.trim()), 400);
    });
}

setupGifPicker('form-general');
setupGifPicker('form-mp');

// --- Mode sombre ---
const themeToggle = document.getElementById('theme-toggle');
const html = document.documentElement;
const body = document.body;

// Charger le thème sauvegardé depuis localStorage
function loadTheme() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    setTheme(savedTheme);
}

function setTheme(theme) {
    if (theme === 'dark') {
        body.classList.add('dark-mode');
        themeToggle.textContent = '☀️';
        localStorage.setItem('theme', 'dark');
    } else {
        body.classList.remove('dark-mode');
        themeToggle.textContent = '🌙';
        localStorage.setItem('theme', 'light');
    }
}

themeToggle.addEventListener('click', () => {
    const isDark = body.classList.contains('dark-mode');
    setTheme(isDark ? 'light' : 'dark');
});

// Charger le thème au démarrage
loadTheme();

// --- Gestion des réactions sur les messages ---
async function loadReactions(messageId, messageType, reactionsDiv) {
    try {
        const res = await fetch(`index.php?action=get_reactions&message_id=${messageId}&message_type=${messageType}`);
        const reactions = await res.json();
        
        reactionsDiv.innerHTML = '';
        reactions.forEach(r => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'msg-reaction';
            btn.dataset.emoji = r.emoji;
            btn.dataset.messageId = messageId;
            btn.dataset.messageType = messageType;
            btn.textContent = r.emoji + ' ' + r.count;
            reactionsDiv.appendChild(btn);
        });
    } catch (err) {
        console.error('Erreur chargement réactions :', err);
    }
}

async function addReaction(messageId, emoji, messageType) {
    try {
        const formData = new FormData();
        formData.append('action', 'add_reaction');
        formData.append('message_id', messageId);
        formData.append('emoji', emoji);
        formData.append('message_type', messageType);

        const res = await fetch('index.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.status === 'ok') {
            // Rafraîchir les réactions affichées
            const messageDiv = document.querySelector(`.message[data-id="${messageId}"]`);
            if (messageDiv) {
                const reactionsDiv = messageDiv.querySelector('.msg-reactions');
                if (reactionsDiv) {
                    loadReactions(messageId, messageType, reactionsDiv);
                }
            }
        }
    } catch (err) {
        console.error('Erreur ajout réaction :', err);
    }
}

// Clic sur un emoji existant = ajouter la même réaction (ou toggle)
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.msg-reaction');
    if (!btn) return;

    const emoji = btn.dataset.emoji;
    const messageId = parseInt(btn.dataset.messageId);
    const messageType = btn.dataset.messageType;
    
    addReaction(messageId, emoji, messageType);
});

// --- Rafraîchissement des messages ---
async function refreshMessages() {
    closeToolbar();
    closeContextMenu();
    
    const generalRes = await fetch('index.php?action=fetch&type=general');
    renderMessages('general-messages', await generalRes.json());

    if (mpSelect.value) {
        const mpRes = await fetch('index.php?action=fetch&type=mp&to=' + mpSelect.value);
        renderMessages('mp-messages', await mpRes.json());
    }
}

function replyPreviewText(content) {
    if (content.startsWith('IMAGE:')) return '📷 Image';
    return content.length > 80 ? content.slice(0, 80) + '…' : content;
}

function renderMessages(containerId, list) {
    const container = document.getElementById(containerId);
    container.innerHTML = '';
    list.forEach(m => {
        const div = document.createElement('div');
        div.className = 'message' + (m.username === currentUser ? ' own' : '');
        div.dataset.id = m.id;

        const userSpan = document.createElement('span');
        userSpan.className = 'msg-user';
        userSpan.textContent = m.username;

        const timeSpan = document.createElement('span');
        timeSpan.className = 'msg-time';
        // Parse le timestamp MySQL (format: YYYY-MM-DD HH:mm:ss) et formate l'heure correctement
        const dateObj = new Date(m.created_at.replace(' ', 'T'));
        const hours = String(dateObj.getHours()).padStart(2, '0');
        const minutes = String(dateObj.getMinutes()).padStart(2, '0');
        const seconds = String(dateObj.getSeconds()).padStart(2, '0');
        timeSpan.textContent = `${hours}:${minutes}:${seconds}`;

        div.append(userSpan);

        if (m.reply_to_id) {
            const preview = document.createElement('div');
            preview.className = 'msg-reply-preview';
            preview.dataset.gotoId = m.reply_to_id;

            const previewUser = document.createElement('span');
            previewUser.className = 'msg-reply-preview-user';
            previewUser.textContent = m.reply_username || '?';

            const previewText = document.createElement('span');
            previewText.className = 'msg-reply-preview-text';
            previewText.textContent = replyPreviewText(m.reply_content || '');

            preview.append(previewUser, previewText);
            div.appendChild(preview);
        }

        if (m.content.startsWith('IMAGE:')) {
            const url = m.content.slice('IMAGE:'.length);
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'msg-image-link';

            const img = document.createElement('img');
            img.src = url;
            img.alt = 'Image envoyée';
            img.className = 'msg-image';
            img.loading = 'lazy';

            link.appendChild(img);
            div.appendChild(link);
        } else {
            const textSpan = document.createElement('span');
            textSpan.className = 'msg-text';
            textSpan.textContent = m.content;
            div.appendChild(textSpan);
        }

        div.appendChild(timeSpan);
        
        // Zone pour afficher les réactions
        const reactionsDiv = document.createElement('div');
        reactionsDiv.className = 'msg-reactions';
        div.appendChild(reactionsDiv);
        
        // Charger les réactions existantes pour ce message
        const messageType = containerId === 'mp-messages' ? 'private' : 'general';
        loadReactions(m.id, messageType, reactionsDiv);
        
        container.appendChild(div);
    });
    container.scrollTop = container.scrollHeight;
}

// Rafraîchit toutes les 3 secondes (polling simple)
setInterval(refreshMessages, 3000);
</script>

</body>
</html>