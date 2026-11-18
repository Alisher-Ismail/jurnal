@extends('layouts.site')

@php
    $journal = config('journal.name');
    $fee = \App\Support\JournalProfile::get()['payment_fee'] ?? '';
    $maxMb = (int) floor(config('journal.max_upload_kb') / 1024);
    $bot = config('services.telegram.username');
    $botUrl = $bot ? 'https://t.me/'.ltrim($bot, '@') : null;
    $locale = app()->getLocale();

    // Written for each language (the phrases people search for differ), not translated word for word.
    $all = [
        'uz' => [
            'title' => 'Ilmiy maqola chop etish: talablar, muddat va narx',
            'doc' => 'Ilmiy maqola chop etish — 1 kunda, DOI bilan, onlayn | '.$journal,
            'desc' => 'Ilmiy maqola va tezislarni onlayn chop etish: talablar, bosqichlar, narx'.($fee ? ' ('.$fee.')' : '').'. Qabul qilingan maqola 1 kunda chop etiladi, Zenodo DOI, Google Scholar, sertifikat. Oʻzbek, rus va ingliz tillarida.',
            'lead' => $journal.' — ochiq kirishli ilmiy jurnal. Maqolangizni sayt yoki Telegram bot orqali yuboring: u tekshiriladi, qabul qilingach 1 kunda chop etiladi, doimiy DOI oladi va Google Scholar’da koʻrinadi.',
            'steps_h' => 'Maqola qanday chop etiladi',
            'steps' => [
                ['Maqolani tayyorlang', 'Word (.docx) yoki PDF, '.$maxMb.' MB gacha. Sarlavha, mualliflar va tashkilot, annotatsiya, kalit soʻzlar, asosiy matn va adabiyotlar roʻyxati boʻlsin.'],
                ['Onlayn yuboring', 'Saytdagi forma yoki Telegram bot orqali. Darhol kuzatuv kodi beriladi — har bir bosqichni onlayn kuzatasiz.'],
                ['Tekshiruv va taqriz', 'Maqola plagiatga (chop etilgan ishlar bazasi va internet boʻyicha) tekshiriladi va tahririyat tomonidan koʻrib chiqiladi.'],
                ['Qabul va toʻlov', $fee ? 'Maqola qabul qilingandan keyingina nashr toʻlovi ('.$fee.') toʻlanadi.' : 'Nashr toʻlovi faqat maqola qabul qilingandan keyin toʻlanadi.'],
                ['1 kunda chop etiladi', 'Maqola jurnal sahifasida, jurnal ma’lumotlari bilan bezatilgan PDF holida chiqadi, DOI va QR-kodli sertifikat beriladi.'],
            ],
            'req_h' => 'Maqolaga talablar',
            'req' => [
                'Til: oʻzbek (lotin yoki kirill), rus yoki ingliz.',
                'Fayl: .docx, .doc yoki .pdf, '.$maxMb.' MB gacha.',
                'Tuzilishi: sarlavha, mualliflar (F.I.Sh., ish joyi), annotatsiya, kalit soʻzlar, kirish, asosiy qism, xulosa, adabiyotlar.',
                'Bezash: shrift 12, qator oraligʻi 1,25, har tomondan 2 sm hoshiya — Word fayl jurnal shabloniga avtomatik keltiriladi.',
                'Mustaqil yozilgan, avval chop etilmagan ish.',
            ],
            'get_h' => 'Chop etilgandan keyin nima olasiz',
            'get' => [
                'Doimiy DOI (Zenodo, CERN) — maqolangizga iqtibos keltirish uchun.',
                'Google Scholar, OpenAIRE va DataCite’da indekslanish.',
                'Ochiq kirish: maqolani har kim bepul oʻqiy va yuklab oladi.',
                'Jurnal ma’lumotlari, DOI va iqtibos namunasi bilan bezatilgan PDF.',
                'Har kim tekshira oladigan QR-kodli nashr sertifikati.',
            ],
            'topics_h' => 'Qaysi yoʻnalishlarda maqola qabul qilinadi',
            'topics_p' => 'Fizika-matematika, kimyo, biologiya, texnika, iqtisodiyot, filologiya, geografiya, huquq va pedagogika. Eng koʻp chop etilgan mavzular:',
            'faq' => [
                ['Maqolani qanday onlayn yuborsam boʻladi?', 'Saytdagi “Maqola yuborish” sahifasidagi formani toʻldirib, Word yoki PDF faylni biriktiring yoki faylni Telegram botga yuboring. Kuzatuv kodi darhol beriladi.'],
                ['Maqola necha kunda chop etiladi?', 'Tekshiruv va taqrizdan oʻtib qabul qilingan maqola 1 kun ichida chop etiladi.'],
                ['Ilmiy maqola chop etish narxi qancha?', $fee ? 'Nashr toʻlovi '.$fee.'. U faqat maqola qabul qilingandan keyin toʻlanadi. Ayrim sonlar bepul chiqariladi.' : 'Nashr toʻlovi faqat maqola qabul qilingandan keyin toʻlanadi. Ayrim sonlar bepul chiqariladi.'],
                ['Maqolaga DOI beriladimi?', 'Ha. Har bir chop etilgan maqola Zenodo (CERN) orqali doimiy DOI oladi.'],
                ['Tezis chop etish mumkinmi?', 'Ha, konferensiya materiallari (tezislar) alohida konferensiya toʻplamlarida chop etiladi. Yuborish tartibi maqolaniki bilan bir xil.'],
                ['Sertifikat beriladimi?', 'Ha. Chop etilgandan va toʻlov tasdiqlangandan soʻng QR-kodli nashr sertifikati beriladi.'],
            ],
            'cta' => 'Maqola yuborish',
            'cta_bot' => 'Telegram bot orqali yuborish',
        ],
        'ru' => [
            'title' => 'Публикация научной статьи: требования, сроки и стоимость',
            'doc' => 'Опубликовать научную статью — за 1 день, с DOI, онлайн | '.$journal,
            'desc' => 'Публикация научных статей и тезисов онлайн: требования, этапы, стоимость'.($fee ? ' ('.$fee.')' : '').'. Принятая статья публикуется за 1 день, DOI Zenodo, Google Scholar, сертификат. Статьи на узбекском, русском и английском.',
            'lead' => $journal.' — научный журнал открытого доступа. Отправьте статью на сайте или через Telegram-бот: её проверят, после принятия опубликуют за 1 день, присвоят постоянный DOI, и она появится в Google Scholar.',
            'steps_h' => 'Как опубликовать статью',
            'steps' => [
                ['Подготовьте рукопись', 'Word (.docx) или PDF, до '.$maxMb.' МБ. Название, авторы и организация, аннотация, ключевые слова, основной текст и список литературы.'],
                ['Отправьте онлайн', 'Через форму на сайте или Telegram-бот. Вы сразу получите код отслеживания и сможете следить за каждым этапом.'],
                ['Проверка и рецензирование', 'Статья проверяется на плагиат (по базе опубликованных работ и интернету) и рассматривается редакцией.'],
                ['Принятие и оплата', $fee ? 'Публикационный взнос ('.$fee.') оплачивается только после принятия статьи.' : 'Публикационный взнос оплачивается только после принятия статьи.'],
                ['Публикация за 1 день', 'Статья выходит на странице журнала в PDF с данными журнала, с DOI и сертификатом с QR-кодом.'],
            ],
            'req_h' => 'Требования к статье',
            'req' => [
                'Язык: узбекский (латиница или кириллица), русский или английский.',
                'Файл: .docx, .doc или .pdf, до '.$maxMb.' МБ.',
                'Структура: название, авторы (ФИО, место работы), аннотация, ключевые слова, введение, основная часть, заключение, литература.',
                'Оформление: шрифт 12, межстрочный интервал 1,25, поля 2 см — файл Word автоматически приводится к шаблону журнала.',
                'Самостоятельная, ранее не опубликованная работа.',
            ],
            'get_h' => 'Что вы получите после публикации',
            'get' => [
                'Постоянный DOI (Zenodo, CERN) для цитирования.',
                'Индексация в Google Scholar, OpenAIRE и DataCite.',
                'Открытый доступ: статью любой может бесплатно читать и скачивать.',
                'PDF с данными журнала, DOI и образцом цитирования.',
                'Сертификат о публикации с QR-кодом, который может проверить любой.',
            ],
            'topics_h' => 'По каким направлениям принимаются статьи',
            'topics_p' => 'Физика и математика, химия, биология, техника, экономика, филология, география, право и педагогика. Самые популярные темы:',
            'faq' => [
                ['Как отправить статью онлайн?', 'Заполните форму на странице «Отправить статью» и прикрепите файл Word или PDF либо отправьте файл Telegram-боту. Код отслеживания выдаётся сразу.'],
                ['За сколько дней публикуется статья?', 'Статья, прошедшая проверку и рецензирование, публикуется в течение 1 дня после принятия.'],
                ['Сколько стоит публикация научной статьи?', $fee ? 'Публикационный взнос — '.$fee.'. Он оплачивается только после принятия статьи. Некоторые выпуски бесплатные.' : 'Публикационный взнос оплачивается только после принятия статьи. Некоторые выпуски бесплатные.'],
                ['Присваивается ли статье DOI?', 'Да. Каждая опубликованная статья получает постоянный DOI через Zenodo (CERN).'],
                ['Можно ли опубликовать тезисы?', 'Да, материалы конференций (тезисы) публикуются в отдельных сборниках конференций. Порядок отправки тот же, что и для статей.'],
                ['Выдаётся ли сертификат?', 'Да. После публикации и подтверждения оплаты выдаётся сертификат о публикации с QR-кодом.'],
            ],
            'cta' => 'Отправить статью',
            'cta_bot' => 'Отправить через Telegram-бот',
        ],
        'en' => [
            'title' => 'Publish a research paper: requirements, timeline and fee',
            'doc' => 'Publish a research paper online — in 1 day, with a DOI | '.$journal,
            'desc' => 'Publish your research paper or conference abstract online: requirements, steps and fee'.($fee ? ' ('.$fee.')' : '').'. Accepted papers are published within 1 day with a Zenodo DOI, Google Scholar indexing and a certificate. Papers in English, Russian and Uzbek.',
            'lead' => $journal.' is an open-access scientific journal. Submit your paper on the website or through the Telegram bot: it is checked, published within 1 day of acceptance, receives a permanent DOI and appears in Google Scholar.',
            'steps_h' => 'How publication works',
            'steps' => [
                ['Prepare your manuscript', 'Word (.docx) or PDF, up to '.$maxMb.' MB, with title, authors and affiliation, abstract, keywords, main text and references.'],
                ['Submit online', 'Use the form on the website or the Telegram bot. You get a tracking code immediately and can follow every step.'],
                ['Check and review', 'The paper is checked for plagiarism (against published works and the web) and reviewed by the editors.'],
                ['Acceptance and fee', $fee ? 'The publication fee ('.$fee.') is paid only after the paper is accepted.' : 'The publication fee is paid only after the paper is accepted.'],
                ['Published within 1 day', 'The paper appears on the journal website as a PDF with the journal details, a DOI and a certificate with a QR code.'],
            ],
            'req_h' => 'Manuscript requirements',
            'req' => [
                'Language: English, Russian or Uzbek (Latin or Cyrillic).',
                'File: .docx, .doc or .pdf, up to '.$maxMb.' MB.',
                'Structure: title, authors (full name, affiliation), abstract, keywords, introduction, main part, conclusion, references.',
                'Formatting: 12 pt font, 1.25 line spacing, 2 cm margins — Word files are converted to the journal template automatically.',
                'Original work that has not been published before.',
            ],
            'get_h' => 'What you get after publication',
            'get' => [
                'A permanent DOI (Zenodo, CERN) for citations.',
                'Indexing in Google Scholar, OpenAIRE and DataCite.',
                'Open access: anyone can read and download the paper for free.',
                'A PDF with the journal details, DOI and citation.',
                'A publication certificate with a QR code anyone can verify.',
            ],
            'topics_h' => 'Subjects we publish',
            'topics_p' => 'Physics and mathematics, chemistry, biology, engineering, economics, philology, geography, law and education. The most published topics:',
            'faq' => [
                ['How do I submit a paper online?', 'Fill in the form on the “Submit a paper” page and attach a Word or PDF file, or send the file to the Telegram bot. You receive a tracking code immediately.'],
                ['How long does publication take?', 'A paper that passes the check and review is published within 1 day of acceptance.'],
                ['How much does it cost to publish a paper?', $fee ? 'The publication fee is '.$fee.', paid only after acceptance. Some issues are free.' : 'The publication fee is paid only after acceptance. Some issues are free.'],
                ['Will my paper get a DOI?', 'Yes. Every published paper receives a permanent DOI through Zenodo (CERN).'],
                ['Can I publish a conference abstract?', 'Yes, conference abstracts are published in separate conference proceedings. They are submitted the same way as papers.'],
                ['Do I receive a certificate?', 'Yes. A publication certificate with a QR code is issued after publication and payment confirmation.'],
            ],
            'cta' => 'Submit a paper',
            'cta_bot' => 'Submit via the Telegram bot',
        ],
    ];
    $c = $all[$locale] ?? $all['uz'];
    $topTopics = \Illuminate\Support\Facades\Schema::hasTable('topics')
        ? \App\Models\Topic::where('articles_count', '>=', 10)->orderByDesc('articles_count')->limit(24)->get(['slug', 'name', 'articles_count'])
        : collect();
@endphp

@section('title', $c['title'])
@section('document_title', $c['doc'])
@section('description', $c['desc'])

@push('meta')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'HowTo',
        'name' => $c['steps_h'],
        'step' => array_map(fn ($s, $i) => ['@type' => 'HowToStep', 'position' => $i + 1, 'name' => $s[0], 'text' => $s[1]], $c['steps'], array_keys($c['steps'])),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $c['faq']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<header class="page-head">
    <div class="wrap">
        <nav class="crumbs"><a href="{{ lroute('maqolabosh') }}">{{ __('Home') }}</a><span>{{ $c['title'] }}</span></nav>
        <span class="eyebrow">{{ __('For authors') }}</span>
        <h1>{{ $c['title'] }}</h1>
        <p style="max-width:760px">{{ $c['lead'] }}</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:22px">
            <a class="btn btn-primary" href="{{ lroute('submit') }}"><x-icon name="upload"/> {{ $c['cta'] }}</a>
            @if ($botUrl)
                <a class="btn btn-ghost" href="{{ $botUrl }}" target="_blank" rel="noopener"><x-icon name="telegram"/> {{ $c['cta_bot'] }}</a>
            @endif
        </div>
    </div>
</header>

<section class="section-tight" style="padding-top:36px">
    <div class="wrap" style="display:grid;gap:22px;max-width:980px">
        <div class="card card-pad">
            <h2 style="margin-top:0">{{ $c['steps_h'] }}</h2>
            <ol style="display:grid;gap:12px;padding-left:22px;margin:0">
                @foreach ($c['steps'] as [$step, $text])
                    <li><strong>{{ $step }}.</strong> {{ $text }}</li>
                @endforeach
            </ol>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:22px">
            <div class="card card-pad">
                <h2 style="margin-top:0">{{ $c['req_h'] }}</h2>
                <ul style="display:grid;gap:8px;padding-left:20px;margin:0">
                    @foreach ($c['req'] as $item)<li>{{ $item }}</li>@endforeach
                </ul>
            </div>
            <div class="card card-pad">
                <h2 style="margin-top:0">{{ $c['get_h'] }}</h2>
                <ul style="display:grid;gap:8px;padding-left:20px;margin:0">
                    @foreach ($c['get'] as $item)<li>{{ $item }}</li>@endforeach
                </ul>
            </div>
        </div>

        <div class="card card-pad">
            <h2 style="margin-top:0">{{ $c['topics_h'] }}</h2>
            <p class="muted">{{ $c['topics_p'] }}</p>
            @if ($topTopics->isNotEmpty())
                <div class="tags">
                    @foreach ($topTopics as $t)
                        <a class="tag" href="{{ lroute('mavzu', $t->slug) }}">{{ $t->name }} <span style="opacity:.6;margin-left:5px">{{ $t->articles_count }}</span></a>
                    @endforeach
                </div>
                <p class="small" style="margin:14px 0 0"><a href="{{ lroute('mavzular') }}">{{ __('All topics') }} →</a></p>
            @endif
        </div>

        <div class="card card-pad">
            <h2 style="margin-top:0">FAQ</h2>
            <div style="display:grid;gap:10px">
                @foreach ($c['faq'] as [$q, $a])
                    <details @if ($loop->first) open @endif style="border:1px solid var(--line);border-radius:12px;padding:12px 16px">
                        <summary style="cursor:pointer;font-weight:600">{{ $q }}</summary>
                        <p style="margin:10px 0 0">{{ $a }}</p>
                    </details>
                @endforeach
            </div>
        </div>

        <div style="text-align:center;padding:8px 0 20px">
            <a class="btn btn-primary btn-lg" href="{{ lroute('submit') }}"><x-icon name="upload"/> {{ $c['cta'] }}</a>
        </div>
    </div>
</section>
@endsection
