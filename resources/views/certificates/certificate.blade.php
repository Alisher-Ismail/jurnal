@php
    // Rendered by dompdf (CSS 2.1): absolute positioning, tables, and DejaVu fonts (Uzbek and Cyrillic).
    $isPublication = $certificate->type === 'publication';
    $profile = \App\Support\JournalProfile::get();
    $navy = '#14223f';
    $gold = '#b08d3c';
    $paleGold = '#e3cf98';
    $blue = '#1f45c4';      // royal blue accent
    $skyTint = '#eaf0fc';   // light blue panels

    $logoFile = resource_path('pdf/logo-mark.png'); // transparent version of the journal logo
    $logo = is_file($logoFile) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoFile)) : null;

    // Frame: navy outer line, double gold inner line and corner ornaments, drawn once as an SVG layer.
    $corner = fn ($x, $y, $sx, $sy) => '<g transform="translate('.$x.' '.$y.') scale('.$sx.' '.$sy.')">'
        .'<path d="M0 34 L0 0 L34 0" fill="none" stroke="'.$gold.'" stroke-width="1.6"/>'
        .'<path d="M6 26 L6 6 L26 6" fill="none" stroke="'.$gold.'" stroke-width="0.7"/>'
        .'<rect x="9" y="9" width="7" height="7" transform="rotate(45 12.5 12.5)" fill="'.$gold.'"/>'
        .'<circle cx="40" cy="0" r="1.6" fill="'.$gold.'"/><circle cx="0" cy="40" r="1.6" fill="'.$gold.'"/></g>';
    $waves = '';
    foreach ([[300, '#dfe6f7'], [330, '#e8d9b0'], [360, '#dfe6f7'], [390, '#e8d9b0'], [420, '#dfe6f7'], [450, '#e8d9b0'], [480, '#dfe6f7']] as $k => [$y, $color]) {
        $d = 'M44 '.$y;
        for ($x = 44; $x < 1078; $x += 40) {
            $d .= ' Q'.($x + 10).' '.($y - 14 + ($k % 2) * 6).' '.($x + 20).' '.$y.' T'.($x + 40).' '.$y;
        }
        $waves .= '<path d="'.$d.'" fill="none" stroke="'.$color.'" stroke-width="1.1"/>';
    }
    $accent = fn ($flip) => '<g'.($flip ? ' transform="rotate(180 561 397)"' : '').'>'
        .'<polygon points="22,22 300,22 22,210" fill="'.$navy.'"/>'
        .'<polygon points="22,22 222,22 22,158" fill="'.$blue.'"/>'
        .'<polygon points="22,22 140,22 22,102" fill="'.$navy.'"/>'
        .'<line x1="314" y1="22" x2="22" y2="220" stroke="'.$gold.'" stroke-width="3"/>'
        .'<line x1="330" y1="22" x2="22" y2="231" stroke="'.$gold.'" stroke-width="0.9"/></g>';
    $frame = '<svg xmlns="http://www.w3.org/2000/svg" width="1122" height="794" viewBox="0 0 1122 794">'
        .$waves
        .$accent(false).$accent(true)
        .'<rect x="22" y="22" width="1078" height="750" fill="none" stroke="'.$navy.'" stroke-width="7"/>'
        .'<rect x="36" y="36" width="1050" height="722" fill="none" stroke="'.$gold.'" stroke-width="1.8"/>'
        .'<rect x="42" y="42" width="1038" height="710" fill="none" stroke="'.$gold.'" stroke-width="0.6"/>'
        .$corner(1072, 50, -1, 1).$corner(50, 744, 1, -1)
        .'</svg>';

    // Gold seal with a serrated edge and ribbon tails; the journal logo sits on top of it.
    $points = [];
    for ($i = 0; $i < 72; $i++) {
        $r = $i % 2 ? 46 : 50;
        $a = M_PI * 2 * $i / 72;
        $points[] = round(60 + $r * cos($a), 2).','.round(60 + $r * sin($a), 2);
    }
    $seal = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="150" viewBox="0 0 120 150">'
        .'<polygon points="34,92 22,146 40,134 52,148 58,100" fill="'.$blue.'"/>'
        .'<polygon points="86,92 98,146 80,134 68,148 62,100" fill="'.$navy.'"/>'
        .'<polygon points="'.implode(' ', $points).'" fill="'.$gold.'"/>'
        .'<circle cx="60" cy="60" r="41" fill="#fbf8f1" stroke="'.$gold.'" stroke-width="2.4"/>'
        .'<circle cx="60" cy="60" r="36" fill="none" stroke="'.$gold.'" stroke-width="0.8"/>'
        .'</svg>';

    // Shield with a check mark for the electronic signature box.
    $esignIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="34" viewBox="0 0 30 34">'
        .'<path d="M15 1 L28 6 L28 16 C28 24 22 30 15 33 C8 30 2 24 2 16 L2 6 Z" fill="'.$navy.'"/>'
        .'<path d="M15 4.5 L25 8.4 L25 16 C25 22.3 20.6 27 15 29.6 C9.4 27 5 22.3 5 16 L5 8.4 Z" fill="none" stroke="'.$gold.'" stroke-width="1"/>'
        .'<path d="M9.5 16.5 L13.5 20.5 L21 12.5" fill="none" stroke="'.$paleGold.'" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>'
        .'</svg>';

    $title = $certificate->title;
    $titleSize = mb_strlen($title) > 160 ? 11.5 : (mb_strlen($title) > 110 ? 13 : (mb_strlen($title) > 70 ? 14.5 : 17));
    // Both long: shrink further so the text never reaches the signature row.
    if (mb_strlen($title) + mb_strlen($certificate->recipient) > 230) {
        $titleSize = min($titleSize, 11);
    }
    $recipientSize = mb_strlen($certificate->recipient) > 75 ? 14.5 : (mb_strlen($certificate->recipient) > 50 ? 17.5 : (mb_strlen($certificate->recipient) > 32 ? 21 : 26));
    // Issue titles often end with the journal name, which the sentence already contains.
    $issueTitle = $issue ? trim(preg_replace('/[,\s]*'.preg_quote(config('journal.name'), '/').'\s*$/iu', '', (string) $issue->title), ' ,') : null;
    $meta = array_filter([
        $t('Scientific journal'),
        \App\Support\JournalProfile::issnLabel(),
        $profile['website'],
        config('journal.telegram_channel') ? preg_replace('#^https?://#', '', config('journal.telegram_channel')) : null,
    ]);

    // Publication details row: issue, date, DOI and license.
    $article = $isPublication ? $certificate->article : null;
    $details = [];
    if ($article) {
        // The issue's "Vol. 2 No. 3 (2026)" field; older issues only had it inside the title.
        $issueShort = trim((string) $issue?->volume) ?: ($issueTitle && preg_match('/^.*?\(\d{4}\)/u', $issueTitle, $m) ? $m[0] : $issueTitle);
        if ($issueShort) {
            $details[] = [$t('Volume').' / '.$t('Issue'), \Illuminate\Support\Str::limit($issueShort, 40)];
        }
        if ($date = $article->onlineAt()) {
            $details[] = [$t('Published'), $date->format('d.m.Y')];
        }
        if ($article->doi) {
            $details[] = ['DOI', $article->doi];
        }
        $details[] = [$t('License'), config('journal.license', 'CC BY 4.0').' · '.$t('Open access')];
    }
    // Long titles leave less room once the details row is printed.
    if ($details) {
        $titleSize = min($titleSize, mb_strlen($title) > 160 ? 10.5 : (mb_strlen($title) > 110 ? 11.5 : 15.5));
        $recipientSize = min($recipientSize, 23);
    }

    // Databases: the journal's indexing list from Panel → System, plus Zenodo for articles deposited there
    // (Zenodo DOIs are registered with DataCite, and OpenAIRE harvests every Zenodo record).
    $databases = $profile['indexing'];
    if ($article?->doi && str_starts_with(strtolower($article->doi), '10.5281/zenodo')) {
        foreach (['Zenodo', 'OpenAIRE', 'DataCite'] as $db) {
            if (! collect($databases)->contains(fn ($d) => stripos($d, $db) !== false)) {
                $databases[] = $db;
            }
        }
    }
    $archived = collect($databases)->contains(fn ($d) => stripos($d, 'zenodo') !== false);
    // Official logos (resources/pdf/indexing/<name>.png), fitted into a tile; other databases get a name badge.
    $tileW = count($databases) > 6 ? 30 : 38;
    $logoFor = function (string $name) use ($tileW) {
        $file = resource_path('pdf/indexing/'.strtolower(preg_replace('/[^a-z]/i', '', $name)).'.png');
        if (! is_file($file) || ! ($size = @getimagesize($file))) {
            return null;
        }
        [$w, $h] = [$size[0], $size[1]];
        $maxW = $tileW - 6;
        $height = min(7.2, $maxW * $h / $w);

        return ['src' => 'data:image/png;base64,'.base64_encode(file_get_contents($file)), 'w' => round($height * $w / $h, 2), 'h' => round($height, 2)];
    };
    $monogram = function (string $name) {
        $words = preg_split('/[\s\-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $letters = count($words) > 1 ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1) : mb_substr($name, 0, 1);

        return mb_strtoupper($letters);
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #2a3346; }
    .page { position: relative; width: 297mm; height: 210mm; background: #fbf8f1; overflow: hidden; }
    .layer { position: absolute; top: 0; left: 0; width: 297mm; height: 210mm; }
    .watermark { position: absolute; top: 47mm; left: 101mm; width: 95mm; height: 95mm; opacity: 0.045; }
    .center { position: absolute; left: 30mm; right: 30mm; text-align: center; }
    .serif { font-family: "DejaVu Serif", serif; }

    .logo { top: 11mm; }
    .logo img { width: 14mm; height: 14mm; }
    .journal { top: 26.5mm; font-size: 14pt; letter-spacing: 3.2pt; color: {{ $navy }}; font-weight: bold; text-transform: uppercase; }
    .meta { top: 34mm; font-size: 8pt; letter-spacing: 1.6pt; color: #6b7385; text-transform: uppercase; }
    .meta span { color: {{ $gold }}; padding: 0 2mm; }

    .heading { top: 38.5mm; font-size: 35pt; letter-spacing: 9pt; color: {{ $navy }}; font-weight: bold; }
    .rule { top: 54.5mm; font-size: 9pt; color: {{ $gold }}; letter-spacing: 1pt; }
    .rule b { display: inline-block; width: 30mm; border-top: 0.35mm solid {{ $gold }}; vertical-align: middle; margin: 0 3mm; }
    .kind { top: 59mm; font-size: 10.5pt; letter-spacing: 4.5pt; font-weight: bold; text-transform: uppercase; }
    .kind span { background: {{ $navy }}; color: {{ $paleGold }}; padding: 1.3mm 7mm 1.1mm 8mm; border-radius: 1.2mm; border-bottom: 0.6mm solid {{ $gold }}; }

    .lead { font-size: 10.5pt; color: #6b7385; font-style: italic; }
    .recipient { color: {{ $blue }}; font-weight: bold; line-height: 1.15; }
    .recipient-line { margin: 2mm auto 0; width: 120mm; border-top: 0.5mm solid {{ $gold }}; }
    .paper { color: #232c3f; font-style: italic; line-height: 1.3; }
    .where { font-size: 10pt; color: #3d4659; line-height: 1.45; }
    .where strong { color: {{ $navy }}; }

    .details { margin: 3.2mm auto 0; border-collapse: separate; border-spacing: 2mm 0; }
    .details td { padding: 1.1mm 4mm 1.2mm; text-align: center; background: {{ $skyTint }}; border-radius: 1.6mm; border-top: 0.6mm solid {{ $blue }}; }
    .details .k { font-size: 6.3pt; text-transform: uppercase; letter-spacing: 1.2pt; color: {{ $blue }}; font-weight: bold; }
    .details .v { font-size: 9pt; color: {{ $navy }}; font-weight: bold; margin-top: 0.6mm; }

    .foot { position: absolute; left: 30mm; right: 30mm; top: 141mm; }
    .foot table { width: 100%; border-collapse: collapse; }
    .foot td { vertical-align: bottom; font-size: 8.5pt; color: #6b7385; }
    .label { font-size: 7pt; text-transform: uppercase; letter-spacing: 1.3pt; color: #8d94a3; }
    .value { font-size: 10.5pt; color: {{ $navy }}; font-weight: bold; margin-top: 0.8mm; }
    .sign-line { border-top: 0.3mm solid {{ $navy }}; padding-top: 1.6mm; width: 62mm; margin-left: auto; text-align: center; }
    .esign-box { width: 60mm; margin: 0 0 1.6mm 9mm; border: 0.35mm solid {{ $navy }}; border-radius: 1.6mm; background: #fff; }
    .esign { width: 100%; border-collapse: collapse; }
    .esign td { vertical-align: middle; padding: 1mm 1.4mm; }
    .esign td.esign-icon { width: 6.5mm; padding-right: 0; }
    .esign-icon img { width: 6.2mm; height: 7mm; }
    .esign-title { font-size: 7.5pt; font-weight: bold; color: {{ $navy }}; letter-spacing: 0.5pt; white-space: nowrap; }
    .esign-meta { font-size: 6.5pt; color: #6b7385; margin-top: 0.4mm; }
    .qr img { width: 20mm; height: 20mm; background: #fff; border: 0.3mm solid {{ $paleGold }}; padding: 0.8mm; }

    .seal { position: absolute; top: 139.5mm; left: 167mm; width: 24mm; height: 30mm; }
    .seal-logo { position: absolute; top: 146.47mm; left: 173.97mm; width: 10.05mm; height: 10.05mm; }

    /* Databases: a full-width band of logo tiles, the second focus of the certificate after the paper. */
    .databases { position: absolute; left: 20mm; right: 20mm; top: 172mm; text-align: center; background: {{ $skyTint }}; border-top: 0.6mm solid {{ $gold }}; border-bottom: 0.6mm solid {{ $gold }}; padding: 1.8mm 0 2.4mm; }
    .databases .title { font-size: 8pt; letter-spacing: 2.6pt; color: {{ $navy }}; font-weight: bold; text-transform: uppercase; }
    .databases .title b { display: inline-block; width: 34mm; border-top: 0.35mm solid {{ $gold }}; vertical-align: middle; margin: 0 3mm; }
    .databases .title i { font-style: normal; color: {{ $gold }}; }
    .databases table { margin: 2.2mm auto 0; border-collapse: separate; border-spacing: 2.6mm 0; }
    .databases td.db { width: {{ $tileW }}mm; height: 12.5mm; background: #fff; border: 0.35mm solid {{ $paleGold }}; border-radius: 2.2mm; text-align: center; vertical-align: middle; padding: 0; }
    .databases td.db img { vertical-align: middle; }
    .databases .mono { display: inline-block; width: 6mm; height: 6mm; line-height: 6mm; border-radius: 3mm; background: {{ $navy }}; color: {{ $paleGold }}; font-size: 7pt; font-weight: bold; text-align: center; vertical-align: middle; }
    .databases .name { display: inline-block; font-size: 9pt; color: {{ $navy }}; font-weight: bold; vertical-align: middle; padding-left: 1.6mm; }
</style>
</head>
<body>
<div class="page">
    <img class="layer" src="data:image/svg+xml;base64,{{ base64_encode($frame) }}" alt="">
    @if ($logo)
        <img class="watermark" src="{{ $logo }}" alt="">
    @endif

    <div class="center logo">@if ($logo)<img src="{{ $logo }}" alt="">@endif</div>
    <div class="center journal serif">{{ config('journal.name') }}</div>
    <div class="center meta">{!! collect($meta)->map(fn ($m) => e($m))->implode('<span>◆</span>') !!}</div>

    <div class="center heading serif">{{ mb_strtoupper($t('Certificate')) }}</div>
    <div class="center rule"><b></b>◆<b></b></div>
    <div class="center kind"><span>{{ $isPublication ? $t('of publication') : $t('of submission') }}</span></div>

    <div class="center" style="top: {{ $details ? 69.5 : 73 }}mm">
        <div class="lead serif">{{ $t('This certifies that') }}</div>
        <div class="recipient serif" style="font-size: {{ $recipientSize }}pt; margin-top: 2mm">{{ $certificate->recipient }}</div>
        <div class="recipient-line"></div>
        <div class="lead serif" style="margin-top: {{ $details ? 2.5 : 3.5 }}mm">{{ $isPublication ? $t('published the scientific paper') : $t('submitted the scientific paper') }}</div>
        <div class="paper serif" style="font-size: {{ $titleSize }}pt; margin: 2mm 12mm 0">«{{ $title }}»</div>
        <div class="where" style="margin-top: {{ $details ? 2.5 : 3 }}mm">
            {{-- The journal name is set in bold inside the translated sentence. --}}
            @php($journal = '<strong>'.e(config('journal.name')).'</strong>')
            @if ($isPublication)
                {!! str_replace('%J%', $journal, e($t('in :journal', ['journal' => '%J%']))) !!}@if ($issueTitle && (! $details || mb_strlen($issueTitle) <= 80)), {{ $issueTitle }}@endif.
            @else
                {!! str_replace('%J%', $journal, e($t('to :journal. The paper is registered and has been passed to the editorial board for review.', ['journal' => '%J%']))) !!}
            @endif
        </div>
        @if ($details)
            <table class="details">
                <tr>
                    @foreach ($details as $i => [$k, $v])
                        <td class="{{ $i === 0 ? 'first' : '' }}"><div class="k">{{ $k }}</div><div class="v">{{ $v }}</div></td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>

    <div class="foot">
        <table>
            <tr>
                <td class="qr" style="width: 27mm">
                    <img src="{{ $qr }}" alt="QR">
                </td>
                <td style="width: 70mm; padding-left: 3mm">
                    <div class="label">{{ $t('Date of issue') }}</div>
                    <div class="value">{{ optional($certificate->issued_at)->format('d.m.Y') }}</div>
                    <div class="label" style="margin-top: 1.8mm">{{ $t('Certificate number') }}</div>
                    <div class="value">{{ $certificate->code }}</div>
                    <div style="font-size: 6.5pt; margin-top: 1mm; color: #8d94a3">{{ $t('Scan the QR code to verify this certificate') }}</div>
                </td>
                <td></td>
                <td style="width: 70mm">
                    {{-- Electronic certificate: an e-signature mark instead of a handwritten signature. --}}
                    <div class="esign-box"><table class="esign">
                        <tr>
                            <td class="esign-icon"><img src="data:image/svg+xml;base64,{{ base64_encode($esignIcon) }}" alt=""></td>
                            <td>
                                <div class="esign-title">{{ mb_strtoupper($t('Electronically signed')) }}</div>
                                <div class="esign-meta">{{ $profile['editor_name'] }} · {{ optional($certificate->issued_at)->format('d.m.Y') }}</div>
                            </td>
                        </tr>
                    </table></div>
                    <div class="sign-line">
                        <div class="value" style="margin: 0">{{ $profile['editor_name'] }}</div>
                        <div style="margin-top: 0.6mm">{{ $profile['editor_title'] ?: $t('Editor-in-chief') }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if ($databases)
        <div class="databases">
            <div class="title"><b></b><i>◆</i> {{ mb_strtoupper($archived ? $t('Indexed and archived in') : $t('Indexed in')) }} <i>◆</i><b></b></div>
            <table>
                <tr>
                    @foreach ($databases as $db)
                        @php($dbLogo = $logoFor($db))
                        <td class="db">
                            @if ($dbLogo)
                                <img src="{{ $dbLogo['src'] }}" alt="{{ $db }}" style="width: {{ $dbLogo['w'] }}mm; height: {{ $dbLogo['h'] }}mm">
                            @else
                                <span class="mono">{{ $monogram($db) }}</span><span class="name">{{ $db }}</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </table>
        </div>
    @endif

    <img class="seal" src="data:image/svg+xml;base64,{{ base64_encode($seal) }}" alt="">
    @if ($logo)
        <img class="seal-logo" src="{{ $logo }}" alt="">
    @endif

</div>
</body>
</html>
