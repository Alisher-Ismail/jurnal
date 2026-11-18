<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Telegram is configured in Panel → System instead of the hosting's .env file. */
class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAFakeTokenForTestsOnly0123456789ab';

    private function admin(): User
    {
        return User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
    }

    private function fakeTelegram(bool $acceptToken = true): void
    {
        Http::fake(function (Request $request) use ($acceptToken) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($method === 'getMe' && ! $acceptToken) {
                return Http::response(['ok' => false, 'description' => 'Unauthorized'], 401);
            }

            return Http::response(['ok' => true, 'result' => match ($method) {
                'getMe' => ['username' => 'emroscience_bot'],
                'getWebhookInfo' => ['url' => ''],
                'getChat' => ['id' => -1009876543210, 'type' => 'channel'],
                'getChatMember' => ['status' => 'administrator', 'can_post_messages' => true],
                'getUpdates' => [
                    ['update_id' => 1, 'message' => ['chat' => ['id' => 555111, 'type' => 'private', 'first_name' => 'Alisher']]],
                    ['update_id' => 2, 'channel_post' => ['chat' => ['id' => -1001234567, 'type' => 'channel', 'title' => 'Emro files']]],
                ],
                default => true,
            }]);
        });
    }

    public function test_bot_token_is_verified_saved_encrypted_and_applied(): void
    {
        config(['services.telegram.token' => null]);
        $this->fakeTelegram();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()->assertSee('Bot token (from @BotFather)');

        $this->actingAs($admin)->put('/panel/system/telegram', ['token' => self::TOKEN])->assertSessionHas('success');

        $saved = RuntimeSettings::telegram();
        $this->assertSame(self::TOKEN, $saved['token']);
        $this->assertSame('emroscience_bot', $saved['username']);
        $this->assertNotEmpty($saved['webhook_secret']);
        $this->assertStringNotContainsString(self::TOKEN, (string) DB::table('app_settings')->where('key', 'telegram')->value('value'), 'Token is stored encrypted');
        $this->assertSame(self::TOKEN, config('services.telegram.token'));

        // The page now lists the chats with one-click buttons.
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertSee('@emroscience_bot')->assertSee('Emro files')->assertSee('Add as editor');
        $this->actingAs($admin)->post('/panel/system/telegram/chat', ['chat_id' => '-1001234567', 'role' => 'storage'])->assertSessionHas('success');
        $this->actingAs($admin)->post('/panel/system/telegram/chat', ['chat_id' => '555111', 'role' => 'editor'])->assertSessionHas('success');
        $this->assertSame('-1001234567', config('services.telegram.storage_chat_id'));
        $this->assertSame(['555111'], config('services.telegram.admin_chat_ids'));

        // Saving again without a token keeps the stored one.
        $this->actingAs($admin)->put('/panel/system/telegram', ['token' => '', 'storage_chat_id' => '-1001234567', 'admin_chat_ids' => '555111, 777888'])->assertSessionHas('success');
        $this->assertSame(self::TOKEN, RuntimeSettings::telegram()['token']);
        $this->assertSame(['555111', '777888'], config('services.telegram.admin_chat_ids'));
    }

    public function test_invite_link_does_not_lose_the_token_and_public_channels_are_resolved(): void
    {
        config(['services.telegram.token' => null]);
        $this->fakeTelegram();
        $admin = $this->admin();

        // The live case: token + a private invite link in the channel field.
        $this->actingAs($admin)->put('/panel/system/telegram?lang=en', ['token' => self::TOKEN, 'storage_chat_id' => 'https://t.me/+jOFjRQBhl9MwYWNi'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'invite link') && str_contains($m, 'Other settings were saved'));
        $this->assertSame(self::TOKEN, RuntimeSettings::telegram()['token'], 'The token is saved anyway');
        $this->assertNull(RuntimeSettings::telegram()['storage_chat_id']);

        // A public channel address is turned into its numeric id.
        $this->actingAs($admin)->put('/panel/system/telegram', ['storage_chat_id' => 'https://t.me/emro_files'])->assertSessionHas('success');
        $this->assertSame('-1009876543210', RuntimeSettings::telegram()['storage_chat_id']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'getChat') && $r['chat_id'] === '@emro_files');
    }

    public function test_chats_seen_through_a_connected_webhook_are_offered(): void
    {
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.webhook_secret' => 'hook-secret']);
        Http::fake(function (Request $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['ok' => true, 'result' => match ($method) {
                'getMe' => ['username' => 'emroscience_bot'],
                'getWebhookInfo' => ['url' => 'https://journal.emro.uz/public/telegram/webhook/hook-secret'],
                default => ['message_id' => 1],
            }]);
        });

        // The bot is made admin of a channel and someone posts there, while the webhook is connected.
        $hook = fn (array $update) => $this->postJson('/telegram/webhook/hook-secret', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
        $hook(['update_id' => 1, 'my_chat_member' => ['chat' => ['id' => -1001112223334, 'type' => 'channel', 'title' => 'Emro files']]]);
        $hook(['update_id' => 2, 'message' => ['message_id' => 5, 'chat' => ['id' => 555111, 'type' => 'private'], 'from' => ['id' => 555111, 'first_name' => 'Alisher'], 'text' => '/start']]);

        $this->actingAs($this->admin())->get('/panel/system?lang=en')->assertOk()
            ->assertSee('Emro files')->assertSee('-1001112223334')->assertSee('Storage channel')
            ->assertSee('Alisher')->assertSee('Add as editor');
    }

    public function test_test_message_explains_failures_and_follows_upgraded_groups(): void
    {
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.webhook_secret' => 'hook-secret', 'services.telegram.storage_chat_id' => null]);
        RuntimeSettings::saveTelegram(['admin_chat_ids' => ['7000000001', '-1589934591', '-111222333']]);
        Http::fake(function (Request $request) {
            return match ((string) $request['chat_id']) {
                '-1589934591' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
                '-111222333' => Http::response(['ok' => false, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -1001112223334]], 400),
                default => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            };
        });

        $this->actingAs($this->admin())->post('/panel/system/test-telegram?lang=en')
            ->assertSessionHas('error', 'Sent to 2 of 3 chats. See the details below.')
            ->assertSessionHas('output', fn ($out) => str_contains($out, '✓ 7000000001')
                && str_contains($out, '✗ -1589934591') && str_contains($out, 'The bot is not in this chat')
                && str_contains($out, '✓ -1001112223334'));
        $this->assertSame(['7000000001', '-1589934591', '-1001112223334'], RuntimeSettings::telegram()['admin_chat_ids']);

        // The upgrade notice Telegram sends to the old group is followed too.
        RuntimeSettings::saveTelegram(['admin_chat_ids' => ['7000000001', '-1589934591']]);
        $this->postJson('/telegram/webhook/hook-secret', ['update_id' => 9, 'message' => ['message_id' => 1, 'chat' => ['id' => -1589934591, 'type' => 'group', 'title' => 'Editors'], 'migrate_to_chat_id' => -1009998887776]],
            ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
        $this->assertSame(['7000000001', '-1009998887776'], config('services.telegram.admin_chat_ids'));
    }

    public function test_storage_chat_the_bot_cannot_reach_is_refused_and_flagged(): void
    {
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.storage_chat_id' => '-1589934591']);
        Http::fake(function (Request $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($method === 'getChat' && (string) $request['chat_id'] === '-1589934591') {
                return Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400);
            }

            return Http::response(['ok' => true, 'result' => match ($method) {
                'getMe' => ['username' => 'emroscience_bot'],
                'getWebhookInfo' => ['url' => 'https://journal.emro.uz/public/telegram/webhook/x'],
                'getChat' => ['id' => -1001234567, 'type' => 'channel', 'title' => 'emroscience'],
                'getChatMember' => ['status' => 'administrator', 'can_post_messages' => true],
                default => true,
            }]);
        });
        $admin = $this->admin();

        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()
            ->assertSee('The bot cannot use the storage channel')->assertSee('The bot is not in this chat');

        $this->actingAs($admin)->post('/panel/system/telegram/chat?lang=en', ['chat_id' => '-1589934591', 'role' => 'editor'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'The bot is not in this chat'));
        $this->assertSame([], RuntimeSettings::telegram()['admin_chat_ids']);

        $this->actingAs($admin)->post('/panel/system/telegram/chat', ['chat_id' => '-1001234567', 'role' => 'storage'])->assertSessionHas('success');
        $this->assertSame('-1001234567', config('services.telegram.storage_chat_id'));
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertDontSee('The bot cannot use the storage channel');
    }

    public function test_editor_forwards_a_channel_post_to_register_the_channel(): void
    {
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.webhook_secret' => 'hook-secret', 'services.telegram.admin_chat_ids' => ['7000000001']]);
        Http::fake(fn (Request $request) => Http::response(['ok' => true, 'result' => match (basename(parse_url($request->url(), PHP_URL_PATH))) {
            'getMe' => ['username' => 'emroscience_bot'],
            'getWebhookInfo' => ['url' => 'https://journal.emro.uz/public/telegram/webhook/hook-secret'],
            default => ['message_id' => 1],
        }]));

        $forward = fn (int $from) => $this->postJson('/telegram/webhook/hook-secret', ['update_id' => $from, 'message' => [
            'message_id' => 7, 'chat' => ['id' => $from, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => 'X'], 'text' => 'test',
            'forward_origin' => ['type' => 'channel', 'chat' => ['id' => -1002233445566, 'type' => 'channel', 'title' => 'emroscience'], 'message_id' => 3],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

        $forward(123456); // not an editor: ignored
        $this->assertDatabaseMissing('telegram_chats', ['chat_id' => -1002233445566]);

        $forward(7000000001);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains((string) $r['text'], '-1002233445566'));
        $this->actingAs($this->admin())->get('/panel/system?lang=en')->assertSee('emroscience')->assertSee('-1002233445566')->assertSee('Storage channel');
    }

    public function test_public_channels_and_channels_without_bot_rights_are_refused_as_storage(): void
    {
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.storage_chat_id' => '7000000001']);
        Http::fake(function (Request $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $public = in_array((string) $request['chat_id'], ['@emroscience', '-1002151644805'], true);
            if ($method === 'getChatMember' && $public) {
                return Http::response(['ok' => false, 'description' => 'Bad Request: member list is inaccessible'], 400);
            }

            return Http::response(['ok' => true, 'result' => match ($method) {
                'getChat' => $public ? ['id' => -1002151644805, 'type' => 'channel', 'title' => 'EmroScience', 'username' => 'emroscience']
                    : ['id' => -1003000000001, 'type' => 'channel', 'title' => 'emroscience'],
                'getChatMember' => ['status' => 'administrator', 'can_post_messages' => true],
                default => true,
            }]);
        });
        $admin = $this->admin();

        $this->actingAs($admin)->put('/panel/system/telegram?lang=en', ['storage_chat_id' => '@emroscience'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Make the bot an admin of this channel'));
        $this->assertNull(RuntimeSettings::telegram()['storage_chat_id']);

        // A private channel's post link carries its id.
        $this->actingAs($admin)->put('/panel/system/telegram', ['storage_chat_id' => 'https://t.me/c/3000000001/12'])->assertSessionHas('success');
        $this->assertSame('-1003000000001', config('services.telegram.storage_chat_id'));
    }

    public function test_forward_from_a_person_is_explained(): void
    {
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.webhook_secret' => 'hook-secret', 'services.telegram.admin_chat_ids' => ['7000000001']]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 1]]));

        $this->postJson('/telegram/webhook/hook-secret', ['update_id' => 1, 'message' => [
            'message_id' => 7, 'chat' => ['id' => 7000000001, 'type' => 'private'], 'from' => ['id' => 7000000001, 'first_name' => 'Alisher'],
            'document' => ['file_id' => 'F1', 'file_name' => 'a.pdf', 'file_size' => 1000],
            'forward_origin' => ['type' => 'user', 'sender_user' => ['id' => 648837670, 'first_name' => 'emroscience'], 'date' => 1],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains((string) $r['text'], 'shaxsdan (emroscience)'));
    }

    public function test_private_channel_becomes_storage_by_itself(): void
    {
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.webhook_secret' => 'hook-secret', 'services.telegram.admin_chat_ids' => ['7000000001']]);
        RuntimeSettings::saveTelegram(['storage_chat_id' => '7000000001']); // a person's chat used as a stopgap
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
        $hook = fn (array $update) => $this->postJson('/telegram/webhook/hook-secret', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
        $admin = fn ($id, $title, $extra = []) => ['update_id' => 1, 'my_chat_member' => [
            'chat' => ['id' => $id, 'type' => 'channel', 'title' => $title] + $extra,
            'new_chat_member' => ['status' => 'administrator', 'can_post_messages' => true],
        ]];

        // A public channel is never used for manuscripts.
        $hook($admin(-1002151644805, 'EmroScience', ['username' => 'emroscience']));
        $this->assertSame('7000000001', config('services.telegram.storage_chat_id'));

        // The private channel is adopted and the editors are told.
        $hook($admin(-1003300000001, 'emroscience files'));
        $this->assertSame('-1003300000001', RuntimeSettings::telegram()['storage_chat_id']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === '7000000001' && str_contains((string) $r['text'], '-1003300000001'));

        // Once a channel is set, another one does not replace it silently.
        $hook(['update_id' => 2, 'channel_post' => ['message_id' => 1, 'chat' => ['id' => -1003300000002, 'type' => 'channel', 'title' => 'Other']]]);
        $this->assertSame('-1003300000001', RuntimeSettings::telegram()['storage_chat_id']);
    }

    public function test_bad_tokens_are_refused(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/panel/system/telegram', ['token' => 'not-a-token'])->assertSessionHasErrors('token');

        $this->fakeTelegram(false);
        $this->actingAs($admin)->put('/panel/system/telegram', ['token' => self::TOKEN])->assertSessionHas('error');
        $this->assertNull(RuntimeSettings::telegram()['token']);
    }

    public function test_webhook_works_with_settings_saved_in_the_panel(): void
    {
        config(['services.telegram.token' => null, 'services.telegram.webhook_secret' => null]);
        $this->fakeTelegram();
        $this->actingAs($this->admin())->put('/panel/system/telegram', ['token' => self::TOKEN])->assertSessionHas('success');
        $secret = RuntimeSettings::telegram()['webhook_secret'];

        $this->postJson('/telegram/webhook/'.$secret, ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertOk();
        $this->postJson('/telegram/webhook/wrong', ['update_id' => 1])->assertForbidden();
    }
}
