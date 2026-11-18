# Emro Science — journal website + Telegram bot

Laravel 9 site for the *Emro Science and Education* journal (journal.emro.uz).

- Public site in **Uzbek / English / Russian**: landing page, archive, issues, articles (Google Scholar meta tags), search, author pages, editable info pages.
- **Paper submission** from the website or the **Telegram bot**. Every submission instantly gets a **PDF certificate with a QR code**. The QR code opens a public verification page. Certificates are delivered by e-mail and/or Telegram.
- **Admin panel** (`/panel`): review, accept (publish into an issue), reject, resend certificates, manage issues, articles, authors and page texts. Publication certificates can also be issued for papers published before the online system existed.
- **Every downloaded PDF carries the journal header and footer** (journal name, issue, publication date, permanent link, page number), stamped on the fly. Originals are never modified, and stamped copies are cached in `storage/app/stamped` (*Panel → System → Clear cache* rebuilds them). Word 2016+ PDFs with compressed object streams are handled by `PdfNormalizer`. Encrypted or broken PDFs, and files over 60 MB, are served unchanged.
- **Originality check for editors** (Panel → submission → *Originality*): runs automatically after each submission.
  - *Archive similarity*: winnowed 5-word fingerprints compared with every issue, article and earlier submission, with sources and matching passages.
  - *Web*: about 8 distinctive sentences searched as exact phrases via Brave Search API (`BRAVE_SEARCH_API_KEY`; $5 free credit a month ≈ 1,000 searches, capped by `JOURNAL_WEB_CHECK_MONTHLY_LIMIT`).
  - *AI-writing indicator*: built-in style statistics (sentence-length uniformity, stock phrases in en/ru/uz, connective openers, repetitive openings, vocabulary evenness). It runs on the server only, and it is a hint, not proof.
  - Build the archive index once with *Panel → System → Build index*.
  - *Authorship protection*: when a submission largely matches an indexed text (≥ `JOURNAL_AUTHORSHIP_MATCH_PERCENT`, default 25%), the authors are compared, across Latin/Cyrillic and spelling variants. Proceedings PDFs are checked against the names printed in the matched article's title block. If the text belongs to other authors, or most sentences are found on the web, the **submission certificate is held** until an editor clicks *Verified — issue certificate* (`JOURNAL_CERTIFICATE_AFTER_CHECK=true`, the default). Tested on 86 real articles: 86/86 outsiders stopped, 85/86 real authors cleared automatically.
  - *Uzbek publications corpus (free)*: titles, authors and abstracts of about 388,000 works by Uzbek researchers and in Uzbek journals since 2015, harvested from [OpenAlex](https://openalex.org) open data, with no key needed. A cPanel cron job calls `/cron/<CRON_SECRET>` every 5 minutes (or press *Panel → System → Download next batch*). The first download takes about 10 hours of cron runs and about 350 MB of database; after that, the last 120 days are refreshed daily. When a submission contains most of a published abstract (≥ `JOURNAL_CORPUS_MATCH_COVERAGE`, default 50%) and the submitter is not among that work's authors, it is an authorship conflict and the certificate is held. Tested on real works: 25/25 outsiders caught, 25/25 real authors cleared.
  - *antiplag.uz* has no API, and its terms forbid reselling access. Editors can record a manual antiplag.uz result (similarity %, AI %, report file) on each submission.
- **Files live in Telegram**: uploads are posted to a private storage channel and served back by `file_id`. If Telegram isn't configured, files fall back to the server disk.

Old URLs (`/maqola/5`, `/maqolaeng/5`, `/maqolavolumeru/3`, `/haqimiz` …) keep working.

## Setup

```bash
composer install
cp .env.example .env        # then fill in DB_*, MAIL_*, TELEGRAM_*
php artisan key:generate
php artisan migrate
php artisan admin:create you@example.com --name="Editor"   # asks for a password
```

Requires PHP 8.1+ with `gd`, `mbstring`, `fileinfo`, `pdo_mysql`.

> Composer blocks Laravel 9 because of published security advisories, so `composer.json` sets `audit.block-insecure: false`.
> The proper fix is moving to PHP 8.2+ and Laravel 11/12.

## Telegram setup

1. **Create the bot**: talk to [@BotFather](https://t.me/BotFather), `/newbot`, then copy the token into `TELEGRAM_BOT_TOKEN` and the username (without `@`) into `TELEGRAM_BOT_USERNAME`.
2. **Storage channel** (where every file is kept): create a *private* channel, add the bot as **administrator**, post anything, and get its id (it looks like `-100…`). One way to get the id: forward a post from the channel to [@userinfobot](https://t.me/userinfobot). Put it in `TELEGRAM_STORAGE_CHAT_ID`.
3. **Editors**: put the Telegram user ids (or a group id) of editors in `TELEGRAM_ADMIN_CHAT_IDS`, comma separated. They receive every new submission with **Review / Accept / Reject** buttons, and can send `/pending` to the bot.
4. **Connect the bot**:
   - Production (HTTPS): set `TELEGRAM_WEBHOOK_SECRET` to a long random string, then run `php artisan telegram:webhook`.
   - Local development: run `php artisan telegram:poll` (long polling; no public URL needed). Keep it running.
5. Optional: copy existing PDFs (up to 20 MB) into the channel with `php artisan files:to-telegram` (`--dry-run` to preview). Local files are kept, because old `/public/storage/pdfs/...` links are indexed by Google Scholar.

Telegram limits: bots can only download files up to **20 MB**. Paper submissions are capped at 20 MB; issue and article PDFs uploaded by editors can be up to 100 MB, and anything over 20 MB stays on the server. See DEPLOY.md for upgrading the live site.

### What the bot does

| Users | Editors (ids in `TELEGRAM_ADMIN_CHAT_IDS`) |
|---|---|
| 📝 Submit a paper step by step (title, authors, organisation, e-mail, abstract, keywords, file) | New submission with the file and buttons |
| 📜 Certificate with QR code sent immediately | 🔍 Review → author is notified |
| 📂 My papers: status, links, certificates | ✅ Accept → choose issue → article is published, author gets link + publication certificate |
| 🔎 Find a published paper → permanent link + certificate | ❌ Reject → optional comment for the author |
| 🌐 Language: Uzbek / English / Russian | `/pending`: list of open submissions |

Authors who submit on the website get a *Connect Telegram* button (`t.me/<bot>?start=p_<code>`). It links their submission to the chat and delivers the certificate there.

## E-mail

Set `MAIL_MAILER=smtp` and the `MAIL_*` values (for example a Gmail app password, or Mailgun). With `MAIL_MAILER=log`, mails are written to `storage/logs/laravel.log`.

## Useful commands

No terminal on the hosting? **Panel → System** runs the database update, connects the Telegram webhook, copies PDFs to Telegram, clears the cache and shows which PDFs are missing. **Panel → Account** changes passwords and manages editors.


| Command | Purpose |
|---|---|
| `php artisan admin:create email [--name=] [--password=]` | Create an admin or reset their password |
| `php artisan telegram:poll` | Run the bot locally |
| `php artisan telegram:webhook [--delete]` | Register or remove the production webhook |
| `php artisan files:to-telegram [--dry-run]` | Copy legacy PDFs (≤ 20 MB) to the storage channel |
| `php artisan files:check [--missing]` | Show where each PDF is found, or which are missing |
| `php artisan test` | Feature tests (in-memory SQLite, Telegram faked) |

## Structure

- `routes/web.php`: public routes registered once per language; admin under `/panel`.
- `app/Services/PaperService.php`: submit / review / accept / reject plus notifications.
- `app/Services/Telegram/BotHandler.php`: bot conversation and editor actions.
- `app/Services/FileVault.php`: Telegram file storage with local fallback.
- `app/Services/CertificateService.php`: certificate PDF (dompdf) and QR code.
- `app/Support/PageContent.php`: editable pages (`about`, `abouteng`, `aboutru` …).
- `lang/uz.json`, `lang/ru.json`: translations (English strings are the keys).
- `resources/views/site`, `resources/views/admin`, `public/css/site.css`, `public/css/admin.css`: UI.
