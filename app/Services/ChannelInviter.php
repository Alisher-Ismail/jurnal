<?php

namespace App\Services;

use App\Mail\ChannelInviteMail;
use App\Models\Paper;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-time invitation to the journal's Telegram channel for authors of published papers.
 * Authors linked to the bot get a Telegram message with a button; the others an e-mail.
 * Older articles (before online submission) have no contact details and cannot be reached.
 */
class ChannelInviter
{
    public function __construct(private TelegramApi $telegram, private PaperService $papers)
    {
    }

    public function ready(): bool
    {
        return (bool) config('journal.telegram_channel') && Schema::hasTable('channel_invites');
    }

    /** Recipients not invited yet: [['channel' => 'telegram'|'email', 'address' => ..., 'paper' => Paper], ...] */
    public function pending(): array
    {
        $invited = DB::table('channel_invites')->get(['channel', 'address'])
            ->map(fn ($r) => $r->channel.':'.$r->address)->flip();

        $recipients = [];
        foreach (Paper::whereNotNull('article_id')->latest('id')->get() as $paper) {
            // Telegram reaches the author directly; e-mail only when there is no linked chat.
            if ($paper->telegram_chat_id) {
                $key = 'telegram:'.$paper->telegram_chat_id;
                $recipients[$key] ??= ['channel' => 'telegram', 'address' => (string) $paper->telegram_chat_id, 'paper' => $paper];
            } elseif ($paper->email && filter_var(trim($paper->email), FILTER_VALIDATE_EMAIL)) {
                $key = 'email:'.Str::lower(trim($paper->email));
                $recipients[$key] ??= ['channel' => 'email', 'address' => Str::lower(trim($paper->email)), 'paper' => $paper];
            }
        }

        return array_values(array_diff_key($recipients, $invited->all()));
    }

    public function stats(): array
    {
        $pending = collect($this->pending());

        return [
            'published' => Paper::whereNotNull('article_id')->count(),
            'telegram' => $pending->where('channel', 'telegram')->count(),
            'email' => $pending->where('channel', 'email')->count(),
            'invited' => DB::table('channel_invites')->count(),
        ];
    }

    /** Sends the pending invitations (at most $limit). Returns ['sent' => n, 'failed' => n]. */
    public function send(int $limit = 100): array
    {
        $sent = $failed = 0;

        foreach (array_slice($this->pending(), 0, $limit) as $r) {
            $paper = $r['paper'];
            $locale = $paper->locale ?: 'uz';

            try {
                if ($r['channel'] === 'telegram') {
                    $this->telegram->sendMessage($r['address'], $this->telegramText($paper, $locale), $this->papers->channelMarkup($locale));
                    usleep(300000); // well under Telegram's per-bot limits
                } else {
                    Mail::to($r['address'])->locale($locale)->send(new ChannelInviteMail($paper));
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Channel invitation failed', ['to' => $r['channel'], 'paper' => $paper->code, 'error' => $e->getMessage()]);

                continue;
            }

            DB::table('channel_invites')->insert([
                'channel' => $r['channel'], 'address' => $r['address'], 'paper_id' => $paper->id,
                'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $sent++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function telegramText(Paper $paper, string $locale): string
    {
        $t = fn (string $key, array $r = []) => e(__($key, $r, $locale));

        return '📢 <b>'.e(config('journal.name'))."</b>\n\n"
            .$t('Dear :name, thank you for publishing your paper in our journal!', ['name' => Str::limit($paper->authors, 80)])."\n\n"
            .$t('Join our Telegram channel to be the first to know about new issues, submission deadlines and announcements.')."\n\n"
            .PaperService::channelHandle();
    }
}
