<?php
/**
 * Script d'initialisation de la base de données pour Nexus Pulse
 * À exécuter une seule fois après le déploiement sur Render
 * 
 * URL : https://ton-app.onrender.com/init-db.php
 */

require __DIR__ . '/config.php';

try {
    // ============================================
    // Création des tables
    // ============================================
    
    // Table des utilisateurs
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(255) UNIQUE NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        avatar_url VARCHAR(500),
        birth_date DATE NULL,
        status VARCHAR(50) DEFAULT 'offline',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table des conversations
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        type ENUM('general', 'private') DEFAULT 'general',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table des participants de conversations
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversation_participants (
        id INT PRIMARY KEY AUTO_INCREMENT,
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_participant (conversation_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table des messages généraux
    $pdo->exec("CREATE TABLE IF NOT EXISTS general_messages (
        id INT PRIMARY KEY AUTO_INCREMENT,
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        content LONGTEXT,
        reply_to_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reply_to_id) REFERENCES general_messages(id) ON DELETE SET NULL,
        INDEX (conversation_id),
        INDEX (user_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table des messages privés
    $pdo->exec("CREATE TABLE IF NOT EXISTS private_messages (
        id INT PRIMARY KEY AUTO_INCREMENT,
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        content LONGTEXT,
        reply_to_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reply_to_id) REFERENCES private_messages(id) ON DELETE SET NULL,
        INDEX (conversation_id),
        INDEX (user_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table des réactions aux messages
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        message_id INT NOT NULL,
        message_type ENUM('general', 'private') NOT NULL,
        user_id INT NOT NULL,
        emoji VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_reaction (message_id, message_type, user_id, emoji),
        INDEX (message_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table pour les fichiers/images
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_attachments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        message_id INT NOT NULL,
        message_type ENUM('general', 'private') NOT NULL,
        file_url VARCHAR(500) NOT NULL,
        file_type VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (message_id),
        INDEX (message_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table pour les amis
    $pdo->exec("CREATE TABLE IF NOT EXISTS friendships (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        friend_id INT NOT NULL,
        status ENUM('pending', 'accepted', 'blocked') DEFAULT 'accepted',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_friendship (user_id, friend_id),
        INDEX (user_id),
        INDEX (friend_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Table de signalisation WebRTC (appels vocaux)
    $pdo->exec("CREATE TABLE IF NOT EXISTS webrtc_signals (
        id          INT PRIMARY KEY AUTO_INCREMENT,
        from_user   INT NOT NULL,
        to_user     INT NOT NULL,
        type        ENUM('offer','answer','ice','hangup','reject','busy') NOT NULL,
        payload     LONGTEXT NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (to_user, created_at),
        INDEX (from_user, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Table de l'historique des appels (log persistant, séparé de la signalisation webrtc_signals)
    $pdo->exec("CREATE TABLE IF NOT EXISTS call_logs (
        id           INT PRIMARY KEY AUTO_INCREMENT,
        caller_id    INT NOT NULL,
        callee_id    INT NOT NULL,
        video        TINYINT(1) NOT NULL DEFAULT 0,
        status       ENUM('ringing','completed','missed','declined') NOT NULL DEFAULT 'ringing',
        started_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        answered_at  DATETIME NULL,
        ended_at     DATETIME NULL,
        duration     INT NULL,
        FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (callee_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX (caller_id, started_at),
        INDEX (callee_id, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Table de suivi : une ligne = une adresse email ayant déjà validé le captcha
    $pdo->exec("CREATE TABLE IF NOT EXISTS captcha_verified_emails (
        id INT PRIMARY KEY AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL,
        verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration : ajoute birth_date si la table users existait déjà sans cette colonne
    // (CREATE TABLE IF NOT EXISTS ne modifie pas une table déjà existante)
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'birth_date'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN birth_date DATE NULL AFTER avatar_url");
        echo "<p>Colonne <code>birth_date</code> ajoutée à la table users existante.</p>";
    }

    echo "<h2 style='color: green;'>✅ Base de données initialisée avec succès !</h2>";
    echo "<p>Toutes les tables ont été créées.</p>";
    echo "<p><a href='login.php'>Aller à la page de login</a></p>";
    
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>❌ Erreur lors de l'initialisation :</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    exit(1);
}