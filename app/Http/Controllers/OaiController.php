<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Maqola;
use App\Support\JournalProfile;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * OAI-PMH 2.0 data provider (Dublin Core), so BASE, CORE, OpenAIRE and other open-access
 * aggregators can harvest the journal's articles. One set per issue.
 *
 * Base URL: https://journal.emro.uz/public/oai
 */
class OaiController extends Controller
{
    private const PAGE = 100;

    private const ARGS = [
        'Identify' => ['required' => [], 'optional' => []],
        'ListMetadataFormats' => ['required' => [], 'optional' => ['identifier']],
        'ListSets' => ['required' => [], 'optional' => ['resumptionToken']],
        'GetRecord' => ['required' => ['identifier', 'metadataPrefix'], 'optional' => []],
        'ListIdentifiers' => ['required' => ['metadataPrefix'], 'optional' => ['from', 'until', 'set', 'resumptionToken']],
        'ListRecords' => ['required' => ['metadataPrefix'], 'optional' => ['from', 'until', 'set', 'resumptionToken']],
    ];

    public function __invoke(Request $request)
    {
        $args = $request->isMethod('post') ? $request->post() : $request->query();
        $verb = (string) ($args['verb'] ?? '');
        unset($args['verb']);

        if (! isset(self::ARGS[$verb])) {
            return $this->respond($verb, [], $this->error('badVerb', 'Illegal or missing verb.'), false);
        }

        // Arguments: no unknowns, no repeats; resumptionToken is exclusive.
        $allowed = array_merge(self::ARGS[$verb]['required'], self::ARGS[$verb]['optional']);
        foreach ($args as $key => $value) {
            if (! in_array($key, $allowed, true) || is_array($value)) {
                return $this->respond($verb, [], $this->error('badArgument', "Illegal argument: $key"), false);
            }
        }
        if (isset($args['resumptionToken'])) {
            if (count($args) > 1) {
                return $this->respond($verb, [], $this->error('badArgument', 'resumptionToken is an exclusive argument.'), false);
            }
        } else {
            foreach (self::ARGS[$verb]['required'] as $key) {
                if (! isset($args[$key])) {
                    return $this->respond($verb, [], $this->error('badArgument', "Missing argument: $key"), false);
                }
            }
        }

        $body = match ($verb) {
            'Identify' => $this->identify(),
            'ListMetadataFormats' => $this->listMetadataFormats($args),
            'ListSets' => $this->listSets($args),
            'GetRecord' => $this->getRecord($args),
            'ListIdentifiers' => $this->listRecords($args, false),
            'ListRecords' => $this->listRecords($args, true),
        };

        return $this->respond($verb, $args, $body);
    }

    private function identify(): string
    {
        $earliest = Article::min('updated_at') ?? Article::min('created_at') ?? now();
        $email = config('journal.email') ?: 'editor@journal.emro.uz';

        return '<Identify>'
            .'<repositoryName>'.e(config('journal.name')).'</repositoryName>'
            .'<baseURL>'.e(route('oai')).'</baseURL>'
            .'<protocolVersion>2.0</protocolVersion>'
            .'<adminEmail>'.e($email).'</adminEmail>'
            .'<earliestDatestamp>'.Carbon::parse($earliest)->utc()->format('Y-m-d').'</earliestDatestamp>'
            .'<deletedRecord>no</deletedRecord>'
            .'<granularity>YYYY-MM-DD</granularity>'
            .'<description><oai-identifier xmlns="http://www.openarchives.org/OAI/2.0/oai-identifier" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            .'xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd">'
            .'<scheme>oai</scheme><repositoryIdentifier>'.e($this->repositoryId()).'</repositoryIdentifier>'
            .'<delimiter>:</delimiter><sampleIdentifier>'.e($this->identifier(1)).'</sampleIdentifier>'
            .'</oai-identifier></description>'
            .'</Identify>';
    }

    private function listMetadataFormats(array $args): string
    {
        if (isset($args['identifier']) && ! $this->findArticle($args['identifier'])) {
            return $this->error('idDoesNotExist', 'No such record.');
        }

        return '<ListMetadataFormats><metadataFormat>'
            .'<metadataPrefix>oai_dc</metadataPrefix>'
            .'<schema>http://www.openarchives.org/OAI/2.0/oai_dc.xsd</schema>'
            .'<metadataNamespace>http://www.openarchives.org/OAI/2.0/oai_dc/</metadataNamespace>'
            .'</metadataFormat></ListMetadataFormats>';
    }

    private function listSets(array $args): string
    {
        if (isset($args['resumptionToken'])) {
            return $this->error('badResumptionToken', 'Sets are not paged.');
        }
        $out = '<ListSets>';
        foreach (Maqola::latest('created_at')->get() as $issue) {
            $out .= '<set><setSpec>issue-'.$issue->id.'</setSpec><setName>'.e($issue->volume.': '.$issue->title).'</setName></set>';
        }

        return $out.'</ListSets>';
    }

    private function getRecord(array $args): string
    {
        if ($args['metadataPrefix'] !== 'oai_dc') {
            return $this->error('cannotDisseminateFormat', 'Only oai_dc is supported.');
        }
        $article = $this->findArticle($args['identifier']);
        if (! $article) {
            return $this->error('idDoesNotExist', 'No such record.');
        }

        return '<GetRecord>'.$this->record($article, true).'</GetRecord>';
    }

    private function listRecords(array $args, bool $full): string
    {
        $tag = $full ? 'ListRecords' : 'ListIdentifiers';

        if (isset($args['resumptionToken'])) {
            $state = $this->decodeToken($args['resumptionToken']);
            if (! $state) {
                return $this->error('badResumptionToken', 'Invalid or expired resumptionToken.');
            }
        } else {
            if ($args['metadataPrefix'] !== 'oai_dc') {
                return $this->error('cannotDisseminateFormat', 'Only oai_dc is supported.');
            }
            foreach (['from', 'until'] as $key) {
                if (isset($args[$key]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $args[$key])) {
                    return $this->error('badArgument', "Bad date in $key (use YYYY-MM-DD).");
                }
            }
            if (isset($args['set']) && ! preg_match('/^issue-\d+$/', $args['set'])) {
                return $this->error('noRecordsMatch', 'No such set.');
            }
            $state = ['from' => $args['from'] ?? null, 'until' => $args['until'] ?? null, 'set' => $args['set'] ?? null, 'offset' => 0];
        }

        $query = Article::with('issue')->orderBy('id');
        if ($state['from']) {
            $query->where('updated_at', '>=', $state['from'].' 00:00:00');
        }
        if ($state['until']) {
            $query->where('updated_at', '<=', $state['until'].' 23:59:59');
        }
        if ($state['set']) {
            $query->where('volume', substr($state['set'], 6));
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            return $this->error('noRecordsMatch', 'No records match.');
        }

        $out = "<$tag>";
        foreach ($query->skip($state['offset'])->take(self::PAGE)->get() as $article) {
            $out .= $full ? $this->record($article, true) : $this->header($article);
        }

        $next = $state['offset'] + self::PAGE;
        if ($next < $total) {
            $out .= '<resumptionToken completeListSize="'.$total.'" cursor="'.$state['offset'].'">'
                .e($this->encodeToken(['offset' => $next] + $state)).'</resumptionToken>';
        } elseif ($state['offset'] > 0) {
            $out .= '<resumptionToken completeListSize="'.$total.'" cursor="'.$state['offset'].'"/>';
        }

        return $out."</$tag>";
    }

    private function header(Article $article): string
    {
        $stamp = Carbon::parse($article->updated_at ?? $article->created_at)->utc()->format('Y-m-d');

        return '<header><identifier>'.e($this->identifier($article->id)).'</identifier>'
            .'<datestamp>'.$stamp.'</datestamp>'
            .($article->volume ? '<setSpec>issue-'.e($article->volume).'</setSpec>' : '')
            .'</header>';
    }

    private function record(Article $article, bool $withMetadata): string
    {
        $issue = $article->issue;
        $dc = [];
        $dc[] = ['title', $article->title];
        foreach ($article->authorList() as $author) {
            $dc[] = ['creator', $author];
        }
        foreach ($article->keywordList() as $keyword) {
            $dc[] = ['subject', $keyword];
        }
        if (trim((string) $article->abstract) !== '') {
            $dc[] = ['description', trim($article->abstract)];
        }
        $dc[] = ['publisher', config('journal.publisher')];
        $dc[] = ['date', optional($issue?->created_at ?? $article->created_at)->format('Y-m-d')];
        $dc[] = ['type', 'info:eu-repo/semantics/article'];
        $dc[] = ['type', 'info:eu-repo/semantics/publishedVersion'];
        $dc[] = ['type', 'Text'];
        $dc[] = ['format', 'application/pdf'];
        $dc[] = ['identifier', Locale::route('maqola', $article->id, 'uz')];
        if ($doi = $article->doiUrl()) {
            $dc[] = ['identifier', $doi];
        }
        if ($pdf = $article->pdfUrl()) {
            $dc[] = ['identifier', $pdf];
        }
        $source = config('journal.name').($issue ? '; '.$issue->volume : '');
        $dc[] = ['source', $source];
        if ($issn = JournalProfile::issnNumber()) {
            $dc[] = ['source', 'ISSN '.$issn];
        }
        if ($issue) {
            $dc[] = ['relation', Locale::route('maqolavolume', $issue->id, 'uz')];
            $dc[] = ['coverage', $issue->title];
        }
        $dc[] = ['language', ['uz' => 'uzb', 'ru' => 'rus', 'en' => 'eng'][$article->languageCode()]];
        $dc[] = ['rights', 'info:eu-repo/semantics/openAccess'];
        $dc[] = ['rights', config('journal.license_url')];

        $meta = '<oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            .'xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd">';
        foreach ($dc as [$name, $value]) {
            if (filled($value)) {
                $meta .= "<dc:$name>".$this->xml($value)."</dc:$name>";
            }
        }
        $meta .= '</oai_dc:dc>';

        return '<record>'.$this->header($article).($withMetadata ? '<metadata>'.$meta.'</metadata>' : '').'</record>';
    }

    private function findArticle(string $identifier): ?Article
    {
        $prefix = 'oai:'.$this->repositoryId().':article/';
        if (! str_starts_with($identifier, $prefix) || ! ctype_digit(substr($identifier, strlen($prefix)))) {
            return null;
        }

        return Article::with('issue')->find((int) substr($identifier, strlen($prefix)));
    }

    private function identifier(int $id): string
    {
        return 'oai:'.$this->repositoryId().':article/'.$id;
    }

    private function repositoryId(): string
    {
        return 'journal.emro.uz';
    }

    private function encodeToken(array $state): string
    {
        return rtrim(strtr(base64_encode(json_encode($state)), '+/', '-_'), '=');
    }

    private function decodeToken(string $token): ?array
    {
        $state = json_decode((string) base64_decode(strtr($token, '-_', '+/')), true);
        if (! is_array($state) || ! isset($state['offset']) || ! is_int($state['offset']) || $state['offset'] < 0) {
            return null;
        }
        foreach (['from', 'until'] as $key) {
            if (isset($state[$key]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $state[$key])) {
                return null;
            }
        }
        if (isset($state['set']) && ! preg_match('/^issue-\d+$/', (string) $state['set'])) {
            return null;
        }

        return $state + ['from' => null, 'until' => null, 'set' => null];
    }

    private function error(string $code, string $message): string
    {
        return '<error code="'.$code.'">'.e($message).'</error>';
    }

    private function xml(string $value): string
    {
        // Strip characters that are illegal in XML 1.0, then escape.
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function respond(string $verb, array $args, string $body, bool $echoArgs = true)
    {
        $attrs = '';
        if ($echoArgs && ! str_starts_with($body, '<error code="badVerb"') && ! str_starts_with($body, '<error code="badArgument"')) {
            $attrs = ' verb="'.e($verb).'"';
            foreach ($args as $key => $value) {
                $attrs .= ' '.$key.'="'.e($value).'"';
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            .'xsi:schemaLocation="http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd">'
            .'<responseDate>'.now()->utc()->format('Y-m-d\TH:i:s\Z').'</responseDate>'
            .'<request'.$attrs.'>'.e(route('oai')).'</request>'
            .$body
            .'</OAI-PMH>';

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
