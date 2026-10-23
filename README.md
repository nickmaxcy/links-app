# Links Directory

A self-hosted directory for tracking device connection info (video
conferencing links, IP addresses, credentials) organized by
Entity → School → Device. Built with plain PHP + MySQL, running in Docker.

## Features

- **Role-based accounts** — Viewer (read-only), Editor (manage Devices,
  Locations, and Platforms; can view everything but only edit/delete
  within Entities an admin has specifically assigned them), Admin (full
  access)
- **Custom platforms** — Zoom/Teams/Meet/Polycom ship as defaults; define
  your own platforms with their own custom fields, including per-field
  "hide from viewer" visibility control
- **CSV import** — bulk import Platforms, Devices, and Users
- **Forgot Password** — email-based reset flow via SMTP, rate limited per IP
- **Two-Factor Authentication** — TOTP-based (compatible with Google
  Authenticator, Microsoft Authenticator, Authy, etc.), with both QR code
  and manual key entry, rate limited against brute-force code guessing
- **Single Sign-On** — Google and/or Microsoft, multiple connections
  supported simultaneously, each with its own allowed email domain and
  optional per-email-prefix role assignment; client secrets encrypted at
  rest
- **Audit log**, session timeout with a live countdown, dark mode

## Quick Start

See [INSTALL.txt](INSTALL.txt) for full setup instructions.

## Tech Stack

- PHP 8.4-fpm (official PHP image) + nginx (official image) - two
  separate containers communicating over FastCGI, both on actively
  maintained upstream images
- MySQL 8.0
- Docker Compose
- [endroid/qr-code](https://github.com/endroid/qr-code) (the only real
  external dependency) for 2FA QR code generation

## Security Notes

- `.env` holds all secrets and is never committed — see `.env.example`
- SSO client secrets are encrypted at rest using `APP_ENCRYPTION_KEY`
  (lives only in `.env`, never in the database)
- Login, 2FA verification, and password-reset requests are all rate
  limited per IP/account
- Passwords are hashed with PHP's `password_hash()` (bcrypt)
- Built on official, actively-patched base images (not an abandoned
  third-party combined image) specifically so security updates keep
  landing via a routine `docker compose build --no-cache`

## License

See [LICENSE](LICENSE).
