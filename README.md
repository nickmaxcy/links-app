# Links Directory

A self-hosted directory for tracking device connection info (video
conferencing links, IP addresses, credentials) organized by
Entity → School → Device. Built with plain PHP + MySQL, running in Docker.

## Features

- **Role-based accounts** — Viewer (read-only), Editor (manage Devices,
  Locations, and Platforms, optionally restricted to specific Entities),
  Admin (full access)
- **Custom platforms** — Zoom/Teams/Meet/Polycom ship as defaults; define
  your own platforms with their own custom fields, including per-field
  "hide from viewer" visibility control
- **CSV import** — bulk import Platforms, Devices, and Users
- **Forgot Password** — email-based reset flow via SMTP
- **Two-Factor Authentication** — TOTP-based (compatible with Google
  Authenticator, Microsoft Authenticator, Authy, etc.), with both QR code
  and manual key entry
- **Single Sign-On** — Google and/or Microsoft, multiple connections
  supported simultaneously, each with its own allowed email domain and
  optional per-email-prefix role assignment
- **Audit log**, session timeout with a live countdown, dark mode

## Quick Start

See [INSTALL.txt](INSTALL.txt) for full setup instructions, including:
- Docker Compose stack setup
- Environment variable configuration
- TLS via Certbot (DNS-01 challenge)
- The Composer step required for the 2FA QR code to work
- SSO provider registration (Google Cloud Console / Microsoft Entra)

## Tech Stack

- PHP 8.2, vanilla (no framework)
- MySQL 8.0
- nginx + PHP-FPM (`richarvey/nginx-php-fpm` base image)
- Docker Compose
- [endroid/qr-code](https://github.com/endroid/qr-code) (the only real
  external dependency) for 2FA QR code generation

## Security Notes

- `.env` holds all secrets and is never committed — see `.env.example`
  for the required variables
- SSO client secrets are encrypted at rest using `APP_ENCRYPTION_KEY`
  (a key that lives only in `.env`, never in the database)
- Login, 2FA verification, and password-reset requests are all rate
  limited per IP/account
- Passwords are hashed with PHP's `password_hash()` (bcrypt)

## License

See [LICENSE](LICENSE).
