# jh-contact-endpoint

PHP contact form endpoint for [joshuaharbert.com](https://joshuaharbert.com). Receives POST submissions, validates them, verifies a Cloudflare Turnstile token, and sends an email via Gmail SMTP.

## Setup

```bash
composer install
cp .env.example .env
# Fill in real values in .env
```

## Local development

Set `TURNSTILE_ENABLED=false` in `.env`, then:

```bash
php -S localhost:8000
```

## Deployment

Push to `main` — GitHub Actions handles the rest. See `.github/workflows/deploy.yml`.

Server path: `/var/www/contact.joshuaharbert.com`

## Environment variables

See `.env.example` for all required variables.
