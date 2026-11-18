{{--
    A quiet invitation for readers: submit a paper / join the Telegram channel.
    Article pages: always, a few seconds after the page opens; "×" closes it on that page only.
    Other pages: only after real reading (40 s or 60% scrolled), at most once a week; "×" hides
    it for 30 days, following either link for 90 days. Never on the submission pages or for
    logged-in editors. Nothing blocks the page; Esc closes it.
--}}
@php
    $channel = config('journal.telegram_channel');
    // ?nudge=1 previews it at once (for editors checking how it looks), without remembering anything.
    $preview = request()->boolean('nudge');
    $always = request()->routeIs('maqola', 'maqolaeng', 'maqolaru');
    $skip = ! $preview && (auth()->check() || request()->routeIs('submit*', 'guide*', 'papers.*', 'certificate.*', 'login'));
@endphp
@unless ($skip)
<aside class="nudge" id="nudge" role="complementary" aria-label="{{ __('Publish with us') }}" @if ($preview) data-preview @endif @if ($always) data-always @endif hidden>
    <button class="nudge-x" type="button" data-nudge-close aria-label="{{ __('Close') }}">×</button>
    <p class="nudge-title">{{ __('Writing a paper?') }}</p>
    <p class="nudge-text">{{ __('Accepted papers are published within 1 day, with a DOI and a certificate.') }}</p>
    <div class="nudge-actions">
        <a class="btn btn-primary btn-sm" href="{{ lroute('submit') }}?from=nudge" data-nudge-go>{{ __('Submit a paper') }}</a>
        @if ($channel)
            <a class="btn btn-ghost btn-sm" href="{{ $channel }}" target="_blank" rel="noopener" data-nudge-go><x-icon name="telegram" style="width:15px;height:15px"/> {{ __('Telegram channel') }}</a>
        @endif
    </div>
</aside>
<style>
    .nudge { position: fixed; right: 20px; bottom: 20px; z-index: 40; width: 320px; max-width: calc(100vw - 32px);
        background: var(--surface); color: var(--text); border: 1px solid var(--line); border-radius: var(--radius);
        box-shadow: var(--shadow-lg); padding: 16px 18px 16px; opacity: 0; transform: translateY(12px);
        transition: opacity .35s ease, transform .35s ease; }
    .nudge.show { opacity: 1; transform: none; }
    .nudge-x { position: absolute; top: 6px; right: 8px; width: 30px; height: 30px; border: 0; background: none;
        color: var(--faint); font-size: 22px; line-height: 1; cursor: pointer; border-radius: 8px; }
    .nudge-x:hover, .nudge-x:focus-visible { color: var(--ink); background: var(--surface-2, var(--bg)); }
    .nudge-title { margin: 0 28px 4px 0; font: 600 15.5px/1.35 var(--sans); color: var(--ink); }
    .nudge-text { margin: 0 0 12px; font-size: 13.5px; line-height: 1.45; color: var(--muted); }
    .nudge-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    @media (max-width: 640px) {
        /* Phones: a slim bar at the bottom instead of a card. */
        .nudge { left: 10px; right: 10px; bottom: 10px; width: auto; max-width: none; padding: 12px 14px; }
        .nudge-text { display: none; }
        .nudge-title { font-size: 14.5px; margin-bottom: 8px; }
    }
    @media (prefers-reduced-motion: reduce) { .nudge { transition: none; } }
    @media print { .nudge { display: none !important; } }
</style>
<script>
(function () {
    var box = document.getElementById('nudge');
    if (!box) return;
    var KEY = 'emro-nudge-until', DAY = 864e5;
    function get() { try { return +localStorage.getItem(KEY) || 0; } catch (e) { return 0; } }
    var preview = box.hasAttribute('data-preview'), always = box.hasAttribute('data-always');
    function set(days) { if (preview || always) return; try { localStorage.setItem(KEY, String(Date.now() + days * DAY)); } catch (e) {} }
    if (!preview && !always && Date.now() < get()) return;

    var shown = false, timer;
    function show() {
        if (shown) return;
        shown = true;
        clearTimeout(timer);
        window.removeEventListener('scroll', onScroll);
        set(7); // even if ignored, not again this week
        box.hidden = false;
        setTimeout(function () { box.classList.add('show'); }, 30); // after un-hiding, so the fade runs
    }
    function hide(days) {
        set(days);
        box.classList.remove('show');
        setTimeout(function () { box.hidden = true; }, 350);
    }
    function onScroll() {
        var h = document.documentElement;
        if ((h.scrollTop + window.innerHeight) / h.scrollHeight >= 0.6) show();
    }
    timer = setTimeout(show, preview ? 300 : (always ? 4000 : 40000));
    window.addEventListener('scroll', onScroll, {passive: true});
    box.querySelector('[data-nudge-close]').addEventListener('click', function () { hide(30); });
    box.querySelectorAll('[data-nudge-go]').forEach(function (a) { a.addEventListener('click', function () { set(90); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && shown && !box.hidden) hide(30); });
})();
</script>
@endunless
