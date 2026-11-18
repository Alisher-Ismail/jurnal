<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topic pages: every article keyword becomes a topic ("raqamli iqtisodiyot", "экспорт" = "eksport"),
 * with its own page listing the articles about it — the phrases people search for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('topics')) {
            Schema::create('topics', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 120)->unique();
                $table->string('name', 120);
                $table->unsignedInteger('articles_count')->default(0);
                $table->timestamps();
                $table->index('articles_count');
            });
        }
        if (! Schema::hasTable('article_topic')) {
            Schema::create('article_topic', function (Blueprint $table) {
                $table->unsignedBigInteger('article_id');
                $table->unsignedBigInteger('topic_id');
                $table->primary(['article_id', 'topic_id']);
                $table->index('topic_id');
            });
        }

        try {
            app(\App\Services\TopicIndex::class)->rebuild();
        } catch (\Throwable $e) {
            // Built again whenever an article is saved, or from Panel → System.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_topic');
        Schema::dropIfExists('topics');
    }
};
