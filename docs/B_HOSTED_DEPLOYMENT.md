# Game B hosted deployment

Builds run on GitHub-hosted Ubuntu24.04; B only receives the locked production runtime. No A runner, deploy.php, PM2 or notification hook is used.

This repository uses its own forced-command SSH account and encrypted B_GAME_API_DEPLOY_SSH_KEY/B_GAME_API_DEPLOY_KNOWN_HOSTS. The account cannot open a terminal or directly read runtime .env/cache, modify Nginx/units or manage other applications. It can upload/verify one bounded runtime and restart only the listed application units. Program files and private runtime state remain separate.

The publisher verifies manifest paths and file hashes, changes current atomically, checks real HTTPS paths, and keeps one version after success. A failed startup reverts code before removing the rejected package. No database DDL, queue flush or persistent-file replacement is performed.

Frontend requires the six original public build settings in B_GAME_WEB_PUBLIC_BUILD_ENV; GAME_API_ORIGIN stays http://127.0.0.1:8002. The internal port is a production dependency of compiled Next rewrites, not a disposable preview. Same-origin API and CSRF routes must remain healthy.

API packages include complete resources and optional root lang, including the welcome view. Only dedicated FPM ExecStartPre prepares private caches as the app user and checks the migration ledger read-only. FPM succeeds before queue and Reverb restart. Console processes verify the private prepared-release marker and never rebuild caches concurrently. Missing migrations require separate administrator work.

Manual workflow validate_only=true builds and packages on the hosted runner, then only checks B without uploading; validate_only=false publishes. The first infrastructure commit uses [skip ci], then a verified build/check precedes a real publication of unchanged business code. New SSH trust, Secrets and main changes require the user approval recorded by the migration task.
