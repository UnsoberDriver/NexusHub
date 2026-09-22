# NexusHub

A recipe site I coded in pure PHP to improve my web dev skills (no framework, I wanted to understand what's happening under the hood). There's a public area to browse recipes, an account system, and an admin dashboard to manage everything.

## What it does

* Recipe list filterable by category, with a detailed page per recipe (ingredients, steps, time, difficulty)
* User accounts: sign up / log in, with a "stay logged in" option (remember-me secured by token)
* Admin dashboard to ban reported users
* Contact form in a popup (AJAX, protected by a CSRF token)
* will add Bilingual FR/EN site, auto-detected based on browser language

## Stack

Native PHP, MySQL/PDO, vanilla HTML/CSS/JS. No framework, no build tool. 

## Project structure
```
/
├── .bash_history
├── .env
└── www/
    │
    ├── config/
    │   ├── config.php                    # Config générale de l'app
    │   ├── env.php                       # Chargement des variables d'environnement
    │   └── init-db.php                   # Initialisation / connexion base de données
    │
    ├── includes/                         # Fonctions techniques réutilisées partout
    │   ├── error_handling.php
    │   ├── error_handling_snippet.php
    │   ├── csrf.php                      # Protection CSRF
    │   ├── fix_legacy_images.php         # Script de maintenance images
    │   └── migrate_recompress_images.php # Script de migration/compression images
    │
    ├── auth/                             # Authentification & sécurité compte
    │   ├── login.php
    │   ├── logout.php
    │   ├── forgot_password.php
    │   ├── reset_password.php
    │   ├── remember_me.php
    │   ├── session_config.php
    │   ├── captcha_check.php
    │   ├── captcha_verify.php
    │   └── recaptcha_config.php
    │
    ├── features/                         # Fonctionnalités métier de l'app
    │   ├── contacts.php
    │   ├── call.php
    │   ├── seen.php                      # Statut "vu" des messages
    │   └── insta.php                     # Intégration Instagram (?)
    │
    ├── assets/
    │   ├── css/
    │   │   ├── styles.css
    │   │   └── mobile-overrides.css
    │   └── js/
    │       ├── app.js
    │       ├── calls.js
    │       ├── group-calls.js
    │       ├── contacts.js
    │       ├── gif-favorites.js
    │       └── cache.js
    │
    ├── data/                             # Fichiers de cache/données non-DB
    │   ├── messages.json
    │   └── insta_quota_cache.json
    │
    ├── storage/                          # Fichiers utilisateurs
    │   ├── uploads/
    │   ├── downloads/
    │   └── avatars/
    │
    ├── api/                          # App mobile
    │   ├── contact.php
    │   ├── profil.php
    │   ├── csrf_tocken.php
    │   ├── screenshare.php
    │   └── mobile_login.php
    │
    ├── favicon.ico
    ├── index.php                         # Point d'entrée principal
    └── .htaccess                         # Règles Apache (réécriture, sécurité)
```
This project is proprietary — all rights reserved. See the LICENSE file for details.

See also NOTICE.md for additional usage restrictions (including AI training).

© 2026 Nicolas Boulloud.
