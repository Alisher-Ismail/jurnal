<?php

namespace App\Services;

use App\Models\Article;
use App\Support\AppSetting;
use App\Support\JournalProfile;
use App\Support\Locale;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Deposits published articles in Zenodo (zenodo.org), which gives each one a permanent DOI.
 * Uses a personal access token entered by an editor in Panel → System (scopes: deposit:write, deposit:actions).
 * Only articles whose authors agreed when submitting are deposited automatically.
 */
class ZenodoService
{
    public const SETTING = 'zenodo';

    private const API = 'https://zenodo.org/api';

    /** @return array{token: ?string, auto: bool, owner: ?string} */
    public static function settings(): array
    {
        try {
            $saved = AppSetting::get(self::SETTING, []) ?: [];
        } catch (Throwable $e) {
            $saved = [];
        }
        $token = null;
        if (! empty($saved['token'])) {
            try {
                $token = Crypt::decryptString($saved['token']);
            } catch (Throwable $e) {
                $token = null; // APP_KEY changed: the token must be entered again
            }
        }

        return ['token' => $token, 'auto' => (bool) ($saved['auto'] ?? true), 'owner' => $saved['owner'] ?? null];
    }

    public static function save(?string $token, bool $auto, ?string $owner): void
    {
        $saved = AppSetting::get(self::SETTING, []) ?: [];
        if ($token !== null) {
            $saved['token'] = $token === '' ? null : Crypt::encryptString($token);
            $saved['owner'] = $token === '' ? null : $owner;
        }
        $saved['auto'] = $auto;
        AppSetting::put(self::SETTING, $saved);
    }

    public function enabled(): bool
    {
        return filled(self::settings()['token']);
    }

    /** Checks a token: returns the number of the account's deposits (throws when Zenodo refuses it). */
    public function check(string $token): int
    {
        $response = Http::withToken($token)->acceptJson()->timeout(30)->get(self::API.'/deposit/depositions', ['size' => 1]);
        if (! $response->successful()) {
            throw new RuntimeException('Zenodo: '.$response->status().' '.($response->json('message') ?? ''));
        }

        return count($response->json() ?? []);
    }

    /**
     * Creates, fills and publishes the Zenodo record for an article and saves its DOI.
     *
     * @return string the DOI
     */
    public function deposit(Article $article): string
    {
        if ($article->doi) {
            return $article->doi;
        }
        $token = self::settings()['token'];
        if (! $token) {
            throw new RuntimeException(__('Zenodo is not connected (Panel → System → Zenodo).'));
        }
        $pdf = $this->pdfContents($article);

        $http = fn () => Http::withToken($token)->acceptJson()->timeout(120);

        // Reuse an unfinished deposition from an earlier attempt instead of creating a duplicate.
        $id = $article->zenodo_id;
        if (! $id) {
            $created = $http()->post(self::API.'/deposit/depositions', ['metadata' => $this->metadata($article)]);
            $this->ensure($created, 'create');
            $id = (string) $created->json('id');
            $article->forceFill(['zenodo_id' => $id])->save();
            $bucket = $created->json('links.bucket');
        } else {
            $existing = $http()->get(self::API.'/deposit/depositions/'.$id);
            $this->ensure($existing, 'read');
            if ($existing->json('submitted') && ($doi = $existing->json('doi'))) {
                $article->forceFill(['doi' => $doi])->save();

                return $doi;
            }
            $bucket = $existing->json('links.bucket');
            $this->ensure($http()->put(self::API.'/deposit/depositions/'.$id, ['metadata' => $this->metadata($article)]), 'update');
        }

        $filename = 'emro-'.$article->id.'.pdf';
        $upload = Http::withToken($token)->timeout(300)->withBody($pdf, 'application/octet-stream')->put($bucket.'/'.$filename);
        $this->ensure($upload, 'upload');

        $published = $http()->post(self::API.'/deposit/depositions/'.$id.'/actions/publish');
        if (! $published->successful()) {
            // Zenodo sometimes answers 500 although the record was published: look before giving up.
            sleep(3);
            $check = $http()->get(self::API.'/deposit/depositions/'.$id);
            if ($check->successful() && $check->json('submitted') && $check->json('doi')) {
                $published = $check;
            }
        }
        $this->ensure($published, 'publish');
        $doi = (string) ($published->json('doi') ?: $published->json('metadata.doi'));
        if ($doi === '') {
            throw new RuntimeException('Zenodo published the record but returned no DOI.');
        }
        $article->forceFill(['doi' => $doi, 'zenodo_id' => $id])->save();

        return $doi;
    }

    private function metadata(Article $article): array
    {
        $issue = $article->issue;
        preg_match('/Vol\.?\s*(\d+)(?:\s*No\.?\s*(\d+))?/i', (string) $issue?->volume, $vol);
        $abstract = trim(preg_replace('/\s+/u', ' ', preg_replace('/^\s*(Annotatsiya|Anotatsiya|Аннотация|Abstract)\s*[:.]\s*/iu', '', (string) $article->abstract)));

        $meta = [
            'upload_type' => 'publication',
            'publication_type' => 'article',
            'title' => $article->title,
            'publication_date' => optional($article->onlineAt())->format('Y-m-d') ?? now()->format('Y-m-d'),
            'creators' => array_map(fn ($name) => ['name' => $name], $article->authorList() ?: ['Unknown']),
            // Papers without an abstract: the opening of their text (read from the PDF), else the title.
            'description' => e($abstract !== '' ? $abstract : (trim((string) $article->excerpt) ?: $article->title)),
            'access_right' => 'open',
            'license' => 'cc-by-4.0',
            'language' => ['uz' => 'uzb', 'ru' => 'rus', 'en' => 'eng'][$article->languageCode()],
            'journal_title' => config('journal.name'),
            'related_identifiers' => [
                ['identifier' => Locale::route('maqola', $article->id, 'uz'), 'relation' => 'isIdenticalTo', 'resource_type' => 'publication-article'],
            ],
        ];
        if ($keywords = $article->keywordList()) {
            $meta['keywords'] = $keywords;
        }
        if (! empty($vol[1])) {
            $meta['journal_volume'] = $vol[1];
        }
        if (! empty($vol[2])) {
            $meta['journal_issue'] = $vol[2];
        }
        if ($issue) {
            $meta['related_identifiers'][] = ['identifier' => Locale::route('maqolavolume', $issue->id, 'uz'), 'relation' => 'isPartOf'];
            // Only proceedings issues are conferences; regular issues are plain journal articles.
            if ($issue->isProceedings()) {
                $meta['conference_title'] = $issue->title;
            }
        }
        if ($issn = JournalProfile::issnNumber()) {
            $meta['related_identifiers'][] = ['identifier' => $issn, 'relation' => 'isPartOf', 'scheme' => 'issn'];
        }

        return $meta;
    }

    /** The PDF exactly as readers download it (with the journal header), else the original file. */
    private function pdfContents(Article $article): string
    {
        $url = $article->pdfUrl();
        if ($url) {
            try {
                $response = Http::timeout(120)->get($url);
                if ($response->successful() && str_starts_with($response->body(), '%PDF')) {
                    return $response->body();
                }
            } catch (Throwable $e) {
                // fall back to the stored file below
            }
        }
        $vault = app(FileVault::class);
        $path = $article->pdf_file_id ? $vault->telegramCopy($article->pdf_file_id) : $vault->localPath($article->pdf, 'public');
        if (! $path || ! is_file($path)) {
            throw new RuntimeException(__('The PDF of this article was not found.'));
        }

        return (string) file_get_contents($path);
    }

    private function ensure($response, string $step): void
    {
        if (! $response->successful()) {
            $errors = collect($response->json('errors') ?? [])->map(fn ($e) => ($e['field'] ?? '').' '.($e['message'] ?? ''))->implode('; ');
            throw new RuntimeException("Zenodo ($step): ".$response->status().' '.($response->json('message') ?? '').($errors ? ' — '.$errors : ''));
        }
    }
}
