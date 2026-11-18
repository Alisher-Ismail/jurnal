<?php

return [
    'name' => env('JOURNAL_NAME', 'Emro Science and Education'),

    // Run the database update and clear caches automatically on the first request after an upload.
    'auto_update' => env('JOURNAL_AUTO_UPDATE', true),
    'short_name' => env('JOURNAL_SHORT_NAME', 'Emro Science'),
    'issn' => env('JOURNAL_ISSN'),
    'publisher' => env('JOURNAL_PUBLISHER', 'Urobot.uz'),
    // License of published articles (shown on the site, in the OAI feed and on Zenodo).
    'license' => env('JOURNAL_LICENSE', 'CC BY 4.0'),
    'license_url' => env('JOURNAL_LICENSE_URL', 'https://creativecommons.org/licenses/by/4.0/'),
    'publisher_url' => env('JOURNAL_PUBLISHER_URL', 'https://urobot.uz'),
    'email' => env('JOURNAL_EMAIL'),
    'address' => env('JOURNAL_ADDRESS', 'Andijon, Oltinkoʻl, Oʻzbekiston'),
    'telegram_channel' => env('JOURNAL_TELEGRAM_CHANNEL', 'https://t.me/emrojournal'),
    // IndexNow key (Bing, Yandex…); the same value is in public/<key>.txt.
    'indexnow_key' => env('INDEXNOW_KEY', '56909d658a3fd2e224a2b1a38d9251f7'),
    'telegram_admin' => env('JOURNAL_TELEGRAM_ADMIN', 'https://t.me/urobotuz'),
    'editor_name' => env('JOURNAL_EDITOR', 'A. Ismailov'),
    'admin_locale' => env('JOURNAL_ADMIN_LOCALE', 'uz'),

    // Telegram bots can only download files up to 20 MB, so uploads are capped there.
    'max_upload_kb' => (int) env('JOURNAL_MAX_UPLOAD_KB', 20480),
    // Issue/article PDFs uploaded by editors (conference proceedings can be large; >20 MB stay on the server).
    'max_admin_upload_kb' => (int) env('JOURNAL_MAX_ADMIN_UPLOAD_KB', 102400),
    'paper_extensions' => ['pdf', 'doc', 'docx'],

    // Originality check: sentences searched on the web per paper, and a monthly cap on searches.
    'web_check_sentences' => (int) env('JOURNAL_WEB_CHECK_SENTENCES', 8),
    'web_check_monthly_limit' => (int) env('JOURNAL_WEB_CHECK_MONTHLY_LIMIT', 900),
    'word_converter' => env('JOURNAL_WORD_CONVERTER', 'auto'),

    // Authorship protection: the submission certificate is sent only after the originality check
    // finds no conflict (text matching other authors' work, or mostly found on the web).
    'check_on_submit' => (bool) env('JOURNAL_CHECK_ON_SUBMIT', true),
    'certificate_after_check' => (bool) env('JOURNAL_CERTIFICATE_AFTER_CHECK', true),
    // One certificate per paper: the publication certificate, sent once the administrator confirms
    // the author's payment. No submission certificate is issued while this is on.
    'certificate_after_payment' => (bool) env('JOURNAL_CERTIFICATE_AFTER_PAYMENT', true),
    'authorship_match_percent' => (float) env('JOURNAL_AUTHORSHIP_MATCH_PERCENT', 25),
    'web_conflict_share' => (float) env('JOURNAL_WEB_CONFLICT_SHARE', 0.5),

    // Free corpus of published Uzbek research harvested from OpenAlex (titles, authors, abstracts).
    'corpus' => [
        'enabled' => (bool) env('JOURNAL_CORPUS_ENABLED', true),
        'from_year' => (int) env('JOURNAL_CORPUS_FROM_YEAR', 2015),
        // Keep 1 in N abstract fingerprints (smaller database, still reliable for a copied abstract).
        'sampling' => (int) env('JOURNAL_CORPUS_SAMPLING', 4),
        // Share of a harvested abstract a submission must contain to count as "the same paper".
        'match_coverage' => (float) env('JOURNAL_CORPUS_MATCH_COVERAGE', 0.5),
        // Optional free key (https://openalex.org/settings/api); works without one.
        'openalex_key' => env('OPENALEX_API_KEY'),
    ],
    'cron_secret' => env('CRON_SECRET'),
];
