<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Production tables may already exist (created from an SQL dump); never fail on them.
        if (Schema::hasTable('papers')) {
            return;
        }

        Schema::create('papers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('title');
            $table->string('authors');
            $table->string('affiliation')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('abstract')->nullable();
            $table->string('keywords')->nullable();
            $table->string('locale', 5)->default('uz');
            $table->string('source', 20)->default('web');
            $table->bigInteger('telegram_chat_id')->nullable()->index();
            $table->string('telegram_username')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_id')->nullable();
            $table->string('file_path')->nullable();
            $table->bigInteger('storage_message_id')->nullable();
            $table->string('status', 20)->default('submitted')->index();
            $table->unsignedBigInteger('issue_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('papers');
    }
};
