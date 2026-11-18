<?php

namespace App\Http\Controllers;

use App\Services\Telegram\BotHandler;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BotHandler $bot)
    {
        $expected = (string) config('services.telegram.webhook_secret');
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', $secret);

        abort_unless($expected !== '' && hash_equals($expected, $secret) && hash_equals($expected, $header), 403);

        // Telegram ids can exceed 32-bit integers (e.g. user 7000000001): decode them as strings,
        // otherwise a 32-bit PHP turns them into floats that MySQL binds as wrapped integers.
        $update = json_decode((string) $request->getContent(), true, 512, JSON_BIGINT_AS_STRING);
        $bot->handle(is_array($update) ? $update : $request->all());

        return response()->json(['ok' => true]);
    }
}
