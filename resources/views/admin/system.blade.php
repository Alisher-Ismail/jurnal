@extends('layouts.admin')

@section('title', __('System'))

@section('content')
<div class="panel-grid">
    <div>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 20px 6px;flex-wrap:wrap">
                <h2 style="font:600 16px var(--sans);margin:0">{{ __('PDF files') }}</h2>
                @if ($missing)
                    <span class="badge badge-rejected">{{ __(':count missing', ['count' => $missing]) }}</span>
                @else
                    <span class="badge badge-accepted">{{ __('All files found') }}</span>
                @endif
            </div>
            <p class="muted small" style="padding:0 20px;margin:6px 0 0">{{ __('Missing files are not on this server. Open the record and upload the PDF again.') }}</p>
            @if (empty($files))
                <div class="empty"><x-icon name="file"/><p>—</p></div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Record') }}</th><th>{{ __('Title') }}</th><th>{{ __('Stored path') }}</th><th>{{ __('Found at') }}</th></tr></thead>
                        <tbody>
                        @foreach ($files as $row)
                            <tr>
                                <td class="num" style="white-space:nowrap"><a href="{{ $row['url'] }}">{{ $row['record'] }}</a></td>
                                <td class="small">{{ \Illuminate\Support\Str::limit($row['title'], 60) }}</td>
                                <td class="small" style="word-break:break-all"><code>{{ $row['path'] ?: '—' }}</code></td>
                                <td class="small" style="word-break:break-all">
                                    @if ($row['missing'])
                                        <span class="badge badge-rejected">{{ str_starts_with($row['where'], 'NOT A PDF') ? __('Not a PDF') : __('Missing') }}</span>
                                    @elseif ($row['where'] === 'Telegram')
                                        <span class="tag tag-brand">Telegram</span>
                                    @else
                                        <code>{{ $row['where'] }}</code>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div>
        <div class="card card-pad panel">
            <h2>{{ __('Database') }}</h2>
            @if ($pending)
                <p class="small" style="color:var(--warn)">{{ __(':count update(s) waiting:', ['count' => count($pending)]) }}</p>
                <ul class="small muted" style="margin:0 0 12px;padding-left:18px;word-break:break-all">
                    @foreach ($pending as $name)<li>{{ $name }}</li>@endforeach
                </ul>
                <form method="post" action="{{ route('admin.system.migrate') }}" data-confirm="{{ __('Back up the database first. Run the update now?') }}">
                    @csrf
                    <button class="btn btn-primary" style="width:100%">{{ __('Run database update') }}</button>
                </form>
            @else
                <p class="small" style="margin:0"><span class="status-dot on">{{ __('Up to date') }}</span></p>
            @endif
            <p class="muted small" style="margin:12px 0 0">PHP {{ $php }}</p>
        </div>

        <div class="card card-pad panel" id="telegram">
            <h2>Telegram</h2>
            @php($ts = $telegramSettings)
            @if (config('services.telegram.token'))
                @if ($telegramError)
                    <div class="alert alert-bad"><x-icon name="alert"/><span>{{ $telegramError }}</span></div>
                @else
                    <div style="display:grid;gap:8px;margin-bottom:14px" class="small">
                        <span class="status-dot on">{{ '@'.($bot['username'] ?? '?') }}</span>
                        <span @class(['status-dot', 'on' => $storageReady, 'off' => ! $storageReady])>{{ __('File storage channel') }}{{ config('services.telegram.storage_chat_id') ? ': '.config('services.telegram.storage_chat_id') : '' }}</span>
                        @if ($storageProblem)
                            <span style="color:var(--bad)">{{ __('The bot cannot use the storage channel') }}: {{ $storageProblem }}</span>
                        @endif
                        <span @class(['status-dot', 'on' => count(config('services.telegram.admin_chat_ids', [])) > 0, 'off' => ! config('services.telegram.admin_chat_ids')])>{{ __('Editor chats') }}: {{ implode(', ', config('services.telegram.admin_chat_ids', [])) ?: '—' }}</span>
                        <span @class(['status-dot', 'on' => ! empty($webhook['url']), 'off' => empty($webhook['url'])])>{{ ! empty($webhook['url']) ? __('Webhook connected') : __('Webhook not connected') }}</span>
                        @if (! empty($webhook['last_error_message']))
                            <span style="color:var(--bad)">{{ __('Last error') }}: {{ $webhook['last_error_message'] }}</span>
                        @endif
                    </div>
                @endif

                @if ($chats)
                    <div style="margin-bottom:14px">
                        <div class="small" style="font-weight:600;margin-bottom:6px">{{ __('Chats the bot has seen') }}:</div>
                        <div style="display:grid;gap:6px">
                            @foreach ($chats as $c)
                                <div class="cite" style="font-size:13px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                                    <span><strong>{{ $c['name'] ?: '—' }}</strong> <span class="muted">· {{ $c['type'] }} · {{ $c['id'] }}</span></span>
                                    <span style="display:flex;gap:6px">
                                        @if (str_starts_with($c['id'], '-'))
                                            <form method="post" action="{{ route('admin.system.telegram.chat') }}" class="inline">@csrf
                                                <input type="hidden" name="chat_id" value="{{ $c['id'] }}"><input type="hidden" name="role" value="storage">
                                                <button class="btn btn-ghost btn-sm" @disabled((string) config('services.telegram.storage_chat_id') === (string) $c['id'])>{{ __('Storage channel') }}</button>
                                            </form>
                                        @endif
                                        @if ($c['type'] !== 'channel')
                                            <form method="post" action="{{ route('admin.system.telegram.chat') }}" class="inline">@csrf
                                                <input type="hidden" name="chat_id" value="{{ $c['id'] }}"><input type="hidden" name="role" value="editor">
                                                <button class="btn btn-ghost btn-sm" @disabled(in_array((string) $c['id'], config('services.telegram.admin_chat_ids', []), true))>{{ __('Add as editor') }}</button>
                                            </form>
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @elseif (empty($webhook['url']))
                    <p class="muted small">{{ __('To see chat ids here: send /start to the bot from your Telegram account, and post any message in the storage channel (with the bot added as admin). Then reload this page.') }}</p>
                @endif

                <div style="display:grid;gap:8px;margin-bottom:14px">
                    <form method="post" action="{{ route('admin.system.webhook') }}">
                        @csrf
                        <button class="btn btn-primary" style="width:100%"><x-icon name="telegram"/> {{ ! empty($webhook['url']) ? __('Reconnect webhook') : __('Connect webhook') }}</button>
                    </form>
                    <form method="post" action="{{ route('admin.system.telegram') }}">
                        @csrf
                        <button class="btn btn-ghost" style="width:100%"><x-icon name="send"/> {{ __('Send test message') }}</button>
                    </form>
                    @if ($storageReady)
                        <form method="post" action="{{ route('admin.system.files') }}" data-confirm="{{ __('Move files up to 20 MB to the Telegram channel and delete the server copies after checking them? Old links keep working.') }}">
                            @csrf
                            <button class="btn btn-ghost" style="width:100%" data-busy="{{ __('Moving… up to a minute') }}"><x-icon name="upload"/> {{ __('Move files to Telegram (free server space)') }}</button>
                        </form>
                    @endif
                    @if ($disk)
                        @php($mb = fn ($b) => number_format($b / 1048576, 1).' MB')
                        <details class="small">
                            <summary style="cursor:pointer;font-weight:600">{{ __('Server space') }}: {{ $mb(array_sum($disk['folders'])) }}</summary>
                            <div style="display:grid;gap:4px;margin-top:8px">
                                <span>{{ __('Journal files that can move to Telegram') }}: <strong>{{ $mb($disk['movable']) }}</strong></span>
                                <span>{{ __('Files over 20 MB (must stay on the server)') }}: {{ $mb($disk['big']) }}</span>
                                <span>{{ __('Cached copies (rebuilt when needed, deleted after :days days)', ['days' => \App\Services\SpaceSaver::CACHE_DAYS]) }}: {{ $mb($disk['cache']) }}</span>
                                <span class="muted" style="margin-top:6px">{{ __('Largest folders') }}:</span>
                                @foreach ($disk['folders'] as $folder => $bytes)
                                    <span class="muted" style="display:flex;justify-content:space-between;gap:8px"><code>{{ $folder }}</code><span>{{ $mb($bytes) }}</span></span>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    @if (! empty($webhook['url']))
                        <form method="post" action="{{ route('admin.system.webhook.delete') }}" data-confirm="{{ __('Disconnect the bot?') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-ghost btn-sm" style="width:100%">{{ __('Disconnect webhook') }}</button>
                        </form>
                    @endif
                </div>
            @endif

            <details @if (! config('services.telegram.token')) open @endif>
                <summary class="small" style="cursor:pointer;font-weight:600">{{ __('Bot settings') }}</summary>
                <form method="post" action="{{ route('admin.system.telegram.save') }}" style="display:grid;gap:10px;margin-top:10px">
                    @csrf @method('PUT')
                    <div class="field">
                        <label>{{ __('Bot token (from @BotFather)') }}</label>
                        <input class="input" type="password" name="token" autocomplete="off"
                               placeholder="{{ config('services.telegram.token') ? '••••••••'.substr((string) config('services.telegram.token'), -4).' — '.__('leave empty to keep') : '1234567890:AA…' }}">
                    </div>
                    <div class="field">
                        <label>{{ __('Storage channel id') }} <span class="opt">{{ __('or use the button above') }}</span></label>
                        <input class="input" name="storage_chat_id" value="{{ old('storage_chat_id', $ts['storage_chat_id'] ?: config('services.telegram.storage_chat_id')) }}" placeholder="-100…  {{ __('or') }}  https://t.me/c/…">
                    </div>
                    <div class="field">
                        <label>{{ __('Editor chat ids') }} <span class="opt">{{ __('separated by commas') }}</span></label>
                        <input class="input" name="admin_chat_ids" value="{{ old('admin_chat_ids', implode(', ', $ts['admin_chat_ids'] ?: config('services.telegram.admin_chat_ids', []))) }}" placeholder="123456789, 987654321">
                    </div>
                    <button class="btn btn-ghost"><x-icon name="check"/> {{ __('Save') }}</button>
                    <span class="muted small">{{ __('Saved here, encrypted; overrides the TELEGRAM_* values in .env. After changing the token, press Connect webhook again.') }}</span>
                </form>
            </details>
        </div>
        <div class="card card-pad panel">
            <h2>{{ __('Plagiarism index') }}</h2>
            <div class="small" style="display:grid;gap:6px;margin-bottom:12px">
                <span @class(['status-dot', 'on' => $index['documents'] >= $index['total'] && $index['total'] > 0, 'off' => $index['documents'] < $index['total']])>{{ __(':done of :total documents indexed', ['done' => $index['documents'], 'total' => $index['total']]) }}</span>
                <span class="muted">{{ number_format($index['fingerprints'], 0, '.', ' ') }} {{ __('fingerprints') }}@if ($index['failed']) · {{ __(':n unreadable', ['n' => $index['failed']]) }}@endif</span>
                <span @class(['status-dot', 'on' => $web['enabled'], 'off' => ! $web['enabled']])>{{ __('Web search (Brave)') }}: {{ $web['enabled'] ? __(':used of :limit searches this month', ['used' => $web['used'], 'limit' => $web['limit']]) : __('not configured') }}</span>
            </div>
            <form method="post" action="{{ route('admin.system.index') }}">
                @csrf
                <button class="btn btn-ghost" style="width:100%" data-busy="{{ __('Indexing… up to a minute') }}"><x-icon name="layers"/> {{ __('Build index') }}</button>
            </form>
            <p class="muted small" style="margin:10px 0 0">{{ __('Large proceedings take about 15 seconds each. Press again until everything is indexed.') }}</p>
            @if (! empty($index['problems']))
                <div style="margin-top:12px;display:grid;gap:6px">
                    <div class="small" style="font-weight:600;color:var(--bad)">{{ __('Could not read') }}:</div>
                    @foreach ($index['problems'] as $p)
                        <div class="cite" style="font-size:12.5px;word-break:break-word">
                            <strong>{{ $p['type'] }} {{ $p['ref_id'] }}</strong> · {{ \Illuminate\Support\Str::limit($p['title'], 60) }}<br>
                            <span style="color:var(--bad)">{{ $p['error'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
            <details style="margin-top:10px">
                <summary class="small muted" style="cursor:pointer">{{ __('Server check') }}</summary>
                <div class="small" style="display:grid;gap:4px;margin-top:8px">
                    <span @class(['status-dot', 'on' => $server['pdf_parser'], 'off' => ! $server['pdf_parser']])>{{ __('PDF text reader installed') }}</span>
                    <span @class(['status-dot', 'on' => $server['zlib'], 'off' => ! $server['zlib']])>PHP zlib</span>
                    <span @class(['status-dot', 'on' => $server['mbstring'], 'off' => ! $server['mbstring']])>PHP mbstring</span>
                    <span class="status-dot on">PHP fileinfo{{ $server['fileinfo'] ? '' : ' — '.__('not installed; the built-in detector is used') }}</span>
                    <span @class(['status-dot', 'on' => $server['storage_writable'], 'off' => ! $server['storage_writable']])>{{ __('storage/app is writable') }}</span>
                    <span @class(['status-dot', 'on' => $server['memory_raise'], 'off' => ! $server['memory_raise']])>memory_limit {{ $server['memory_limit'] }}{{ $server['memory_raise'] ? '' : ' — '.__('cannot be raised') }}</span>
                    <span class="muted">max_execution_time {{ $server['max_execution_time'] }}</span>
                </div>
            </details>
        </div>

        <div class="card card-pad panel">
            <h2>{{ __('Uzbek publications corpus') }}</h2>
            @if (! ($corpus['ready'] ?? false))
                <p class="muted small" style="margin:0">{{ __('Run the database update first.') }}</p>
            @else
                @php($pct = $corpus['expected'] ? min(100, round($corpus['works'] / $corpus['expected'] * 100)) : 0)
                <div class="small" style="display:grid;gap:6px;margin-bottom:12px">
                    <span @class(['status-dot', 'on' => $corpus['mode'] !== 'full', 'off' => $corpus['mode'] === 'full'])>
                        {{ number_format($corpus['works'], 0, '.', ' ') }}@if ($corpus['expected']) / {{ number_format($corpus['expected'], 0, '.', ' ') }}@endif {{ __('works') }}
                        · {{ $corpus['mode'] === 'full' ? __('first download in progress') : __('up to date, refreshed daily') }}
                    </span>
                    @if ($corpus['mode'] === 'full' && $corpus['expected'])
                        <span style="height:8px;border-radius:99px;background:var(--surface-2);overflow:hidden;display:block"><span style="display:block;height:100%;width:{{ $pct }}%;background:var(--brand)"></span></span>
                    @endif
                    <span class="muted">{{ __('Last run') }}: {{ $corpus['last_run'] ?: '—' }}@if ($corpus['size_mb'] !== null) · {{ __('database') }} {{ $corpus['size_mb'] }} MB @endif</span>
                    @if ($corpus['last_error'])<span style="color:var(--bad)">{{ $corpus['last_error'] }}</span>@endif
                </div>
                <form method="post" action="{{ route('admin.system.harvest') }}">
                    @csrf
                    <button class="btn btn-ghost" style="width:100%" data-busy="{{ __('Downloading… up to a minute') }}"><x-icon name="download"/> {{ __('Download next batch') }}</button>
                </form>
                @php($cronWorks = $cronLastRun && $cronLastRun->gt(now()->subMinutes(20)))
                <div class="small" style="margin-top:10px">
                    <span @class(['status-dot', 'on' => $cronWorks, 'off' => ! $cronWorks])>
                        {{ __('Automatic download') }}:
                        {{ $cronLastRun ? __('last run :time', ['time' => $cronLastRun->diffForHumans()]) : __('not set up yet') }}
                    </span>
                </div>
                <details style="margin-top:10px" @if (! $cronWorks) open @endif>
                    <summary class="small muted" style="cursor:pointer">{{ __('Automatic download (cPanel → Cron Jobs)') }}</summary>
                    <div class="small" style="margin-top:8px;display:grid;gap:6px">
                        @if ($cronUrl)
                            <span>{{ __('Add a cron job "Every 5 minutes" with this command:') }}</span>
                            <code class="cite" id="cron-command" style="font-size:11.5px;word-break:break-all;user-select:all">wget -q -O /dev/null "{{ $cronUrl }}"</code>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('cron-command').innerText).then(() => this.textContent = '✓ {{ __('Copied') }}')">{{ __('Copy command') }}</button>
                        @else
                            <span>{{ __('Add CRON_SECRET (any long random text) to .env, clear the cache, and the cron command appears here.') }}</span>
                        @endif
                    </div>
                </details>
                <p class="muted small" style="margin:10px 0 0">{{ __('Source: OpenAlex (open data). Titles, authors and abstracts of works by Uzbek researchers and in Uzbek journals since :year.', ['year' => config('journal.corpus.from_year')]) }}</p>
            @endif
        </div>

        <div class="card card-pad panel" id="zenodo">
            <h2>Zenodo · DOI</h2>
            <p class="muted small" style="margin-top:0">{{ __('Accepted papers whose authors agreed to CC BY 4.0 are deposited in Zenodo and get a permanent DOI, shown on the article page.') }}</p>
            <p style="margin:0 0 12px">
                @if ($zenodo['token'])
                    <span class="badge badge-accepted">{{ __('Connected') }}</span>
                    @if ($zenodo['owner'])<span class="muted small">{{ __('by :name', ['name' => $zenodo['owner']]) }}</span>@endif
                @else
                    <span class="badge">{{ __('Not connected') }}</span>
                @endif
            </p>
            <form method="post" action="{{ route('admin.system.zenodo') }}" style="display:grid;gap:10px">
                @csrf @method('PUT')
                <div class="field">
                    <label>{{ __('Personal access token') }} <span class="opt">{{ $zenodo['token'] ? __('leave empty to keep the current one') : '' }}</span></label>
                    <input class="input" type="password" name="token" autocomplete="off" placeholder="{{ $zenodo['token'] ? '••••••••••••' : '' }}">
                    <span class="muted small">{!! __('Create it at <a href="https://zenodo.org/account/settings/applications/tokens/new/" target="_blank" rel="noopener">zenodo.org → Applications → Personal access token</a> with the scopes <code>deposit:write</code> and <code>deposit:actions</code>. It is stored encrypted.') !!}</span>
                </div>
                <label class="check small">
                    <input type="hidden" name="auto" value="0">
                    <input type="checkbox" name="auto" value="1" @checked($zenodo['auto'])>
                    <span>{{ __('Deposit automatically when a paper is accepted (only if the author agreed)') }}</span>
                </label>
                <div class="btn-row">
                    <button class="btn btn-primary btn-sm" type="submit"><x-icon name="check"/> {{ __('Save') }}</button>
                    @if ($zenodo['token'])
                        <button class="btn btn-ghost btn-sm" type="submit" name="disconnect" value="1">{{ __('Disconnect') }}</button>
                    @endif
                </div>
            </form>
        </div>

        <div class="card card-pad panel" id="certificate">
            <h2>{{ __('Certificate') }}</h2>
            <div style="display:flex;gap:6px;margin-bottom:12px">
                <a class="btn btn-ghost btn-sm" style="flex:1" target="_blank" href="{{ route('admin.system.certificate.preview', 'publication') }}">{{ __('Preview') }}: {{ __('of publication') }}</a>
                <a class="btn btn-ghost btn-sm" style="flex:1" target="_blank" href="{{ route('admin.system.certificate.preview', 'submission') }}">{{ __('Preview') }}: {{ __('of submission') }}</a>
            </div>
            <form method="post" action="{{ route('admin.system.journal') }}" style="display:grid;gap:10px">
                @csrf @method('PUT')
                <div class="field">
                    <label>{{ __('Indexed in') }} <span class="opt">{{ __('one database per line') }}</span></label>
                    <textarea class="textarea" name="indexing" rows="4" style="min-height:96px" placeholder="Google Scholar">{{ old('indexing', implode("\n", $journalProfile['indexing'])) }}</textarea>
                    <span class="muted small">{{ __('List only databases where the journal is really indexed: certificates are checked by readers and universities.') }}</span>
                </div>
                <div class="field">
                    <label>ISSN <span class="opt">{{ __('optional') }}</span></label>
                    <input class="input" name="issn" value="{{ old('issn', $journalProfile['issn']) }}" placeholder="0000-0000">
                </div>
                <div class="field">
                    <label>{{ __('Website on the certificate') }}</label>
                    <input class="input" name="website" value="{{ old('website', $journalProfile['website']) }}">
                </div>
                <div class="field">
                    <label>{{ __('Signed by') }}</label>
                    <input class="input" name="editor_name" value="{{ old('editor_name', $journalProfile['editor_name']) }}">
                </div>
                <div class="field">
                    <label>{{ __('Position') }} <span class="opt">{{ __('empty = "Editor-in-chief" in the certificate language') }}</span></label>
                    <input class="input" name="editor_title" value="{{ old('editor_title', $journalProfile['editor_title']) }}">
                </div>
                <div class="field">
                    <label>{{ __('Publication fee') }}</label>
                    <input class="input" name="payment_fee" value="{{ old('payment_fee', $journalProfile['payment_fee']) }}">
                </div>
                <div class="field">
                    <label>{{ __('Payment details') }} <span class="opt">{{ __('sent to the author when the paper is accepted') }}</span></label>
                    <textarea class="textarea" name="payment_info" rows="3" style="min-height:80px">{{ old('payment_info', $journalProfile['payment_info']) }}</textarea>
                </div>
                <button class="btn btn-ghost"><x-icon name="check"/> {{ __('Save') }}</button>
            </form>
        </div>

        @if ($zenodoBacklog)
            <div class="card card-pad panel" id="zenodo-backlog">
                <h2>{{ __('DOIs for older articles') }}</h2>
                <p class="muted small">{{ __('Older articles are deposited in Zenodo one by one and receive a permanent DOI. Each Zenodo record links back to the article page. The cron job continues automatically.') }}</p>
                <div class="small" style="display:grid;gap:4px;margin-bottom:12px">
                    <span>{{ __('With DOI') }}: <strong>{{ $zenodoBacklog['with_doi'] }}</strong> / {{ $zenodoBacklog['total'] }}</span>
                    <span>{{ __('Waiting') }}: <strong>{{ $zenodoBacklog['remaining'] }}</strong>@if ($zenodoBacklog['failed']) · {{ __('Failed') }}: <strong>{{ $zenodoBacklog['failed'] }}</strong>@endif</span>
                </div>
                @foreach ($zenodoBacklog['reasons'] ?? [] as $id => $reason)
                    <p class="small muted" style="margin:0 0 6px"><a href="{{ route('admin.articles.edit', $id) }}">#{{ $id }}</a> — {{ \Illuminate\Support\Str::limit($reason, 160) }}</p>
                @endforeach
                <form method="post" action="{{ route('admin.system.zenodo.backlog') }}" style="display:grid;gap:8px">
                    @csrf
                    @if ($zenodoBacklog['remaining'] > 0)
                        <button class="btn btn-primary" style="width:100%"><x-icon name="send"/> {{ __('Deposit the next 10') }}</button>
                    @endif
                    @if ($zenodoBacklog['failed'])
                        <button class="btn btn-ghost btn-sm" name="retry" value="1">{{ __('Retry the failed ones') }}</button>
                    @endif
                </form>
            </div>
        @endif

        <div class="card card-pad panel" id="indexnow">
            <h2>{{ __('Search engines (IndexNow)') }}</h2>
            <p class="muted small">{{ __('New and changed articles are announced to Bing, Yandex, DuckDuckGo, Yahoo and others automatically. Use this button to send every page of the site at once.') }}</p>
            <form method="post" action="{{ route('admin.system.indexnow') }}">
                @csrf
                <button class="btn btn-ghost" style="width:100%"><x-icon name="send"/> {{ __('Send all pages') }}</button>
            </form>
        </div>

        @if ($abstracts)
            <div class="card card-pad panel" id="abstracts">
                <h2>{{ __('Abstracts from PDFs') }}</h2>
                <p class="muted small">{{ __('Google Scholar only includes papers whose page shows the abstract (or the opening of the text). This reads each PDF once: a labelled abstract becomes the article\'s abstract; papers without one get the opening of their text as an excerpt. An abstract is used only when the paper\'s own title stands right above it.') }}</p>
                <div class="small" style="display:grid;gap:4px;margin-bottom:12px">
                    <span>{{ __('Articles') }}: <strong>{{ $abstracts['total'] }}</strong> · {{ __('without an abstract') }}: <strong>{{ $abstracts['missing'] }}</strong></span>
                    <span>{{ __('Excerpts shown instead') }}: <strong>{{ $abstracts['excerpts'] }}</strong></span>
                    <span>{{ __('Not read yet') }}: <strong>{{ $abstracts['remaining'] }}</strong></span>
                </div>
                @if ($abstracts['remaining'] > 0)
                    <form method="post" action="{{ route('admin.system.abstracts') }}">
                        @csrf
                        <button class="btn btn-primary" style="width:100%"><x-icon name="file"/> {{ __('Read the next 25 PDFs') }}</button>
                    </form>
                @else
                    <p class="muted small" style="margin:0">{{ __('All PDFs have been read.') }}</p>
                @endif
            </div>
        @endif

        @php($poster = app(\App\Services\ChannelPoster::class))
        @if ($poster->ready())
            @php($autopost = \App\Services\ChannelPoster::settings())
            @php($botCan = $poster->botStatus())
            <div class="card card-pad panel" id="channel-autopost">
                <h2>{{ __('New papers in the Telegram channel') }}</h2>
                <p class="muted small">{{ __('The bot posts every newly published paper to :channel once it has its DOI (title, authors, DOI, link). At most two per run, older papers are never posted in bulk.', ['channel' => $poster->chat()]) }}</p>
                <p class="small" style="margin:0 0 12px">
                    {{ __('Status') }}: <strong>{{ $autopost['enabled'] ? __('on') : __('off') }}</strong>
                    @if ($autopost['enabled'] && $autopost['since']) · {{ __('papers published since :date', ['date' => \Illuminate\Support\Carbon::parse($autopost['since'])->timezone('Asia/Tashkent')->format('d.m.Y H:i')]) }} @endif
                    <br>{{ __('Bot can post in the channel') }}: <strong>{{ $botCan['ok'] ? __('yes') : __('no') }}</strong>@unless ($botCan['ok']) <span class="muted">({{ $botCan['reason'] }})</span>@endunless
                </p>
                <form method="post" action="{{ route('admin.system.channel.autopost') }}">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $autopost['enabled'] ? 0 : 1 }}">
                    <button class="btn {{ $autopost['enabled'] ? 'btn-ghost' : 'btn-primary' }}" style="width:100%"><x-icon name="telegram"/> {{ $autopost['enabled'] ? __('Turn off') : __('Turn on automatic posts') }}</button>
                </form>
            </div>
        @endif

        @if ($channelInvite)
            <div class="card card-pad panel" id="channel-invite">
                <h2>{{ __('Invite authors to the channel') }}</h2>
                <p class="muted small">{{ __('A one-time invitation to :channel for authors of published papers: through the bot if their Telegram is linked, otherwise by e-mail. Nobody is invited twice.', ['channel' => \App\Services\PaperService::channelHandle()]) }}</p>
                <div class="small" style="display:grid;gap:4px;margin-bottom:12px">
                    <span>{{ __('Published papers') }}: <strong>{{ $channelInvite['published'] }}</strong></span>
                    <span>{{ __('To invite') }}: <strong>{{ $channelInvite['telegram'] }}</strong> Telegram · <strong>{{ $channelInvite['email'] }}</strong> e-mail</span>
                    <span class="muted">{{ __('Already invited') }}: {{ $channelInvite['invited'] }}</span>
                </div>
                @if ($channelInvite['telegram'] + $channelInvite['email'] > 0)
                    <form method="post" action="{{ route('admin.system.channelInvite') }}" data-confirm="{{ __('Send the channel invitation to :count authors?', ['count' => $channelInvite['telegram'] + $channelInvite['email']]) }}">
                        @csrf
                        <button class="btn btn-tg" style="width:100%"><x-icon name="telegram"/> {{ __('Send invitations') }}</button>
                    </form>
                @else
                    <p class="muted small" style="margin:0">{{ __('Everyone with contact details has been invited.') }}</p>
                @endif
            </div>
        @endif

        <form class="card card-pad panel" method="post" action="{{ route('admin.system.mail') }}">
            @csrf
            <h2>E-mail</h2>
            <div class="small" style="display:grid;gap:6px;margin-bottom:12px">
                <span @class(['status-dot', 'on' => $mail['mailer'] === 'smtp', 'off' => $mail['mailer'] !== 'smtp'])>MAIL_MAILER: {{ $mail['mailer'] }}</span>
                @if ($mail['mailer'] === 'smtp')<span class="muted">{{ $mail['host'] }}:{{ $mail['port'] }}</span>@endif
                <span class="muted">{{ __('From') }}: {{ $mail['from'] }}</span>
            </div>
            <div style="display:flex;gap:6px">
                <input class="input" type="email" name="email" required placeholder="you@example.com" value="{{ auth()->user()->email && str_contains(auth()->user()->email, '@') ? auth()->user()->email : '' }}" style="height:38px;padding:0 10px">
                <button class="btn btn-ghost btn-sm" style="height:38px">{{ __('Send test') }}</button>
            </div>
        </form>

        <div class="card card-pad panel">
            <h2>Word → PDF</h2>
            <div style="display:grid;gap:8px" class="small">
                <span @class(['status-dot', 'on' => $wordConverter['libreoffice'], 'off' => ! $wordConverter['libreoffice']])>LibreOffice{{ $wordConverter['libreoffice'] ? '' : ' — '.__('not installed') }}</span>
                <span @class(['status-dot', 'on' => $wordConverter['php'], 'off' => ! $wordConverter['php']])>{{ __('Built-in converter (PHP)') }}</span>
            </div>
            @unless ($wordConverter['libreoffice'] || $wordConverter['php'])
                <p class="small" style="color:var(--bad);margin:10px 0 0">{{ __('Enable the PHP zip and xml extensions in cPanel → Select PHP Version.') }}</p>
            @endunless
        </div>

        <div class="card card-pad panel">
            <h2>{{ __('Cache') }}</h2>
            <p class="muted small">{{ __('Use after uploading new files or changing .env.') }}</p>
            <form method="post" action="{{ route('admin.system.cache') }}">
                @csrf
                <button class="btn btn-ghost" style="width:100%">{{ __('Clear cache') }}</button>
            </form>
            <p class="muted small" style="margin:10px 0 0">{{ __('Also runs by itself every :hours hours (with moving files to Telegram and removing old logs).', ['hours' => \App\Http\Controllers\CronController::CLEANUP_HOURS]) }}
                @if ($lastCleanup = \App\Support\AppSetting::get(\App\Http\Controllers\CronController::LAST_CLEANUP))
                    {{ __('Last time') }}: {{ \Illuminate\Support\Carbon::parse($lastCleanup)->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}
                @endif
            </p>
        </div>
    </div>
</div>
@endsection
