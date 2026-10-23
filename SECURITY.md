# Security guide

Read this before installing the app for a customer.

## Before going live (every install)

1. **Use HTTPS only.** Enable the host's free certificate (cPanel: AutoSSL) and redirect HTTP to HTTPS.
2. **Set a strong `ADMIN_PASSWORD`** (8+ characters) in `.env` before the first start. If you do not, the app creates a
   random one and saves it in `php/storage/first_run_admin_password.txt`. Sign in, change it, then delete that file.
3. **Leave `SECRET_KEY` empty or set it to a random 32+ character value.** Never reuse a key between customers. If it is
   empty, the app creates a unique one in `php/storage/.app_secret`. Keys that were ever published in the source are refused.
4. **Never copy `.env`, `php/storage/*`, database files, `cpanel_backup/` or `Cpanel-RSAkey/` between customers.**
   Build customer packages with `python package_cpanel.py`; it leaves these out.
5. **Point the domain at `php/public/` if your host allows it.** If the whole project folder is the web root, the root
   `.htaccess` blocks `php/scripts`, `php/tests`, `php/storage`, `whatsapp-service` and backups. Open
   `https://your-domain/php/scripts/reset_admin_password.php` and `https://your-domain/.env` after install: both must be refused.
6. **Folders `php/storage/` and `php/public/static/uploads/` must be writable by PHP but must not run scripts.**
   Use permissions 755 (folders) and 644 (files), not 777.

## Things the app now enforces

- Sign-in is limited to 5 failed attempts per account (and 20 per address) per 15 minutes.
- Passwords need 8+ characters. Changing your own password needs the current one.
- Login tokens are signed with an install-specific key and expire after `ACCESS_TOKEN_EXPIRE_MINUTES`.
- Every data endpoint checks the user's module permissions (view / create / edit / delete). Purchase (cost) prices are hidden from staff without `products.view`.
- Logo uploads accept only real PNG/JPG/GIF/WebP images (2 MB max); nothing in `uploads/` can run as a script.
- The Google Sheet importer accepts only `https://docs.google.com` links and checks TLS certificates.
- The WhatsApp service only answers requests carrying the shared secret; the WhatsApp agent only sends an invoice or quotation to the customer it belongs to, matched by phone number.
- Maintenance scripts and tests refuse to run unless started from the command line.

## Mail (SMTP)

The mail server's certificate is verified. If sending fails with a certificate error, use the mail server's real host name
(the one on its certificate). Only as a last resort set `SMTP_VERIFY_TLS=false` in `.env`.

## Emergency recovery (needs access to the server's files)

- `php php/scripts/reset_admin_password.php "NewStrongPassword"` from cPanel Terminal or a one-off cron job.
- Or create `php/storage/reset_password.txt` containing the new password; the next request resets every admin and deletes the file.
- Setting `ADMIN_PASSWORD` in `.env` also lets the main admin account sign in with it.

Anyone who can write files on the server can use these, so protect your cPanel/FTP/SSH logins with strong passwords and 2-factor authentication.

## Reporting a problem

Treat a suspected leak as urgent: change the admin password, rotate `SECRET_KEY` (delete `php/storage/.app_secret` or change
the value in `.env`; everyone must sign in again), the mail password and the database password.

## Run the checks

```
php php/tests/test_security_hardening.php
```

Starts the app on a temporary port with a temporary database and tries the attacks listed above. It never touches real data.
