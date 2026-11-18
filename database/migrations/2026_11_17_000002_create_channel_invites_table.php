<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who was already invited to the Telegram channel, so nobody gets the invitation twice. */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('channel_invites')) {
            return;
        }

        Schema::create('channel_invites', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);   // telegram | email
            $table->string('address', 190);  // chat id or e-mail address
            $table->unsignedBigInteger('paper_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'address']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('channel_invites');
    }
};
