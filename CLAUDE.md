# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A single-file PHP contact form endpoint for joshuaharbert.com. It receives POST requests, validates them, verifies a Cloudflare Turnstile token, and sends an email via Gmail SMTP using PHPMailer. No framework — just `index.php` + Composer.

Production path on the VPS: `/var/www/contact.joshuaharbert.com`

## Local development

```bash
composer install
cp .env.example .env  # then set TURNSTILE_ENABLED=false
php -S localhost:8000
```

Test with curl:
```bash
curl -X POST http://localhost:8000 \
  -d "name=Test&email=test@example.com&message=Hello"
```

With `TURNSTILE_ENABLED=false`, Turnstile validation is skipped and localhost CORS origins are permitted.

## Deployment

Push to `main` — GitHub Actions SSHes into the VPS, runs `git pull origin main` and `composer install --no-dev --optimize-autoloader`. Required repository secrets: `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY` (and optionally `SSH_PORT`).

## Architecture

All logic lives sequentially in `index.php` in this order:

1. Load `.env` via phpdotenv
2. Set CORS headers (production: `https://joshuaharbert.com` only; dev: also `localhost`)
3. Handle OPTIONS preflight / reject non-POST
4. Rate limit: file-based rolling window in `sys_get_temp_dir()/jh_contact_rate_limits/`, max 10/IP/hour
5. Validate and sanitize `name`, `email`, `message`, `cf-turnstile-response`
6. Verify Turnstile token against Cloudflare's siteverify API (skipped when `TURNSTILE_ENABLED=false`)
7. Send email via PHPMailer (STARTTLS, port 587)
8. Append timestamp to rate-limit file, return `{"success": true}`

All responses are JSON. Errors return `{"success": false, "error": "..."}` with an appropriate HTTP status code. PHPMailer errors are written to `error_log` only — never exposed to the caller.

## Key env vars

| Variable | Purpose |
|---|---|
| `MAIL_FROM_ADDRESS` | Envelope from address |
| `MAIL_USERNAME` | Gmail address (also the To address) |
| `MAIL_PASSWORD` | Gmail app password |
| `TURNSTILE_ENABLED` | Set `false` for local dev to skip token validation |
| `TURNSTILE_SECRET_KEY` | Server-side Cloudflare key |
