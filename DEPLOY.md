# Upgrading journal.emro.uz without losing data

Checked on the live site (14 Nov 2026):

- The site is served from the project folder, so URLs look like `https://journal.emro.uz/public/...`. That stays the same.
- The data (issues, articles, authors, page texts, admins) lives only in the production database. The upgrade **adds** tables and columns and never drops, renames or rewrites existing rows. This was rehearsed on a copy of the database: article and issue checksums were identical before and after.
- PDFs live in `public/storage/pdfs/` on the server. They are **not** in the upload package and must not be deleted, because Google Scholar links point to them.
- ⚠️ Anyone can currently download `journal.sql` (admin password hashes), `storage/logs/laravel.log`, `composer.json` and `vendor/`, and `/database/` lists its files. The new root `.htaccess` closes this (step 4).

## 0. Before you start

- **PHP 8.1 or 8.2** is required. Select it in the hosting panel for journal.emro.uz (the old code ran on 8.0).
- You only need the cPanel File Manager and phpMyAdmin. **No terminal is needed**: the admin panel has a **System** page (`/public/panel/system`) with buttons for the database update, Telegram webhook, copying PDFs to Telegram and clearing the cache, and an **Account** page for passwords.

## 1. Back up (do not skip)

1. **Database**: phpMyAdmin → database → *Export* → *Quick* → SQL. Keep the file.
2. **Files**: download at least `.env`, `public/storage/` (all PDFs) and the site's root `index.html`.

## 2. Turn on maintenance mode (optional)

With a terminal: `php artisan down`. Without one, just do steps 3–6 quickly.

## 3. Upload the new code

Upload the contents of `jurnal-upgrade.zip` into the site folder and **overwrite** existing files. The package contains:

`app/  bootstrap/  config/  database/  lang/  resources/  routes/  vendor/  public/css/  public/index.php  public/.htaccess  .htaccess  artisan  composer.json  composer.lock  README.md  DEPLOY.md`

It deliberately does **not** contain, so they are left untouched:

| Kept on the server | Why |
|---|---|
| `.env` | production passwords and keys |
| `public/storage/` | all existing PDFs |
| `public/admin/` | logo and favicon images (already there) |
| `storage/` | sessions, logs, uploaded files |
| `index.html` in the site root | redirect to `/public/eng` |

Then delete these old cache files if they exist (they are regenerated automatically):
`bootstrap/cache/config.php`, `bootstrap/cache/routes-v7.php`, `bootstrap/cache/events.php`.

Optional cleanup of the old code (it is no longer used): `resources/views/client/`, `resources/views/admin/uz|eng|ru/`, and in `app/Http/Controllers/` every `About*`, `Aim*`, `Team*`, `Instruction*`, `Plagarism*`, `Submission*`, `Qidiruv*` controller, plus `MaqolaController.php`, `VolumeController.php`, `AuthorController.php` and `ArticleController.php` **in the root Controllers folder only** (keep `Controllers/Admin/`).

## 4. Close the security holes

1. Delete `journal.sql` from the server.
2. Check that the new root `.htaccess` is active. All of these must answer **403**:
   - `https://journal.emro.uz/journal.sql`
   - `https://journal.emro.uz/storage/logs/laravel.log`
   - `https://journal.emro.uz/composer.json`
   - `https://journal.emro.uz/.env`

   If they still download, ask the hosting provider to set the document root to the `public` folder, or to allow `.htaccess` overrides.

## 5. Add the new settings to the server's `.env`

Keep everything that is already there and add:

```
APP_URL=https://journal.emro.uz/public
APP_DEBUG=false

MAIL_MAILER=smtp            # working settings on this hosting (ahost.uz), tested 14 Nov 2026:
MAIL_HOST=uz03.ahost.uz     # not mail.emro.uz: its SSL certificate is issued for *.ahost.uz
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=editor@journal.emro.uz
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=editor@journal.emro.uz   # must be an existing mailbox, same as MAIL_USERNAME (else "530 Relaying not allowed")

TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_STORAGE_CHAT_ID=
TELEGRAM_ADMIN_CHAT_IDS=
TELEGRAM_WEBHOOK_SECRET=    # long random string

JOURNAL_ISSN=
JOURNAL_EMAIL=
```

The site works before Telegram is configured: files are then kept on the server, and the bot is simply off.

## 6. Update the database

Without a terminal: log in and open **Panel → System → Run database update**, or paste `database/sql/upgrade-2026-11-14.sql` into phpMyAdmin → SQL.

With a terminal, in the site folder:

```bash
php artisan migrate --pretend   # shows the SQL first: only CREATE TABLE / ALTER TABLE ... ADD, nothing destructive
php artisan migrate --force
php artisan optimize:clear
php artisan up                  # if you used maintenance mode
```

What it does: creates `papers`, `certificates`, `telegram_chats`, `teameng`, `teamru` (copied from the current `team` text), adds an empty `pdf_file_id` column to `article` and `maqola`, and makes `maqola.pdf` optional. Missing tables are created, and existing ones are skipped.

## 7. Secure the admin accounts

Old password hashes were public (in `journal.sql` on the site and in the public GitHub repo), so set new passwords in **Panel → Account**: change your own, and type a new one for every other editor in the *Editors* table.

With a terminal instead:

```bash
php artisan admin:create admin@example.com      # asks for a new password (min 8 characters)
```

Do the same for every other admin login. Logins are now at `https://journal.emro.uz/public/login`, and the panel is at `/public/panel`.

If production uses the same `APP_KEY` as the `.env` in the GitHub repo, generate a new one with `php artisan key:generate --force`. This logs everyone out once.

## 8. Check the site

- `https://journal.emro.uz/public/eng`, `/public/mvolumeeng`, an issue, an article, and its PDF button.
- An old link, e.g. `https://journal.emro.uz/public/maqolaeng/35`.
- `/public/submiteng`: send a test paper and check that the certificate arrives. Then delete the test paper in *Panel → Submissions*.

Test with **Panel → System → E-mail → Test**.

## 9. Telegram (when ready)

See README → *Telegram setup*. Then **Panel → System → Connect webhook** and **Copy PDFs to Telegram**. With a terminal:

```bash
php artisan telegram:webhook                 # uses APP_URL → https://journal.emro.uz/public/telegram/webhook/<secret>
php artisan files:to-telegram --dry-run      # see which legacy PDFs would be copied
php artisan files:to-telegram                # copies PDFs up to 20 MB; local files are never deleted
```

## Rolling back

Restore the files you backed up in step 1 and import the SQL export. The new tables can stay; the old code ignores them.
