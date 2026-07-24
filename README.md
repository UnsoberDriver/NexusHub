WIP
## Réorganisation proposée

```
/
├── .env                          # Variables sensibles (DB, clés API, secrets) — hors public si possible
├── config/
│   ├── config.php                  # Config générale de l'app
│   ├── env.php                     # Chargement des variables d'environnement
│   └── init-db.php                 # Initialisation / connexion base de données
│
├── includes/                     # Fonctions techniques réutilisées partout
│   ├── error_handling.php
│   ├── error_handling_snippet.php
│   ├── csrf.php                    # Protection CSRF
│   ├── fix_legacy_images.php        # Script de maintenance images
│   └── migrate_recompress_images.php # Script de migration/compression images
│
├── auth/                         # Authentification & sécurité compte
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
├── features/                     # Fonctionnalités métier de l'app
│   ├── contacts.php
│   ├── call.php
│   ├── seen.php                    # Statut "vu" des messages
│   └── insta.php                   # Intégration Instagram (?)
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
├── data/                          # Fichiers de cache/données non-DB
│   ├── messages.json
│   └── insta_quota_cache.json
│
├── storage/                       # Fichiers utilisateurs
│   ├── uploads/
│   ├── downloads/
│   └── avatars/
│
├── favicon.ico
├── index.php                      # Point d'entrée principal
└── .htaccess                      # Règles Apache (réécriture, sécurité)
```
