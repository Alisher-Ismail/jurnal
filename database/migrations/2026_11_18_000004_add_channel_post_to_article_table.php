<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When (and as which message) an article was announced in the journal's Telegram channel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article', function (Blueprint $table) {
            if (! Schema::hasColumn('article', 'channel_posted_at')) {
                $table->unsignedBigInteger('channel_message_id')->nullable();
                $table->timestamp('channel_posted_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('article', function (Blueprint $table) {
            $table->dropColumn(['channel_message_id', 'channel_posted_at']);
        });
    }
};
