<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Production tables may already exist (created from an SQL dump); never fail on them.
        if (Schema::hasTable('telegram_chats')) {
            return;
        }

        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('state', 40)->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('telegram_chats');
    }
};
