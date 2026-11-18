<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Published works of Uzbek researchers harvested from OpenAlex (title, authors, abstract, link).
        if (! Schema::hasTable('external_works')) {
            Schema::create('external_works', function (Blueprint $table) {
                $table->id();
                $table->string('openalex_id', 40)->unique();
                $table->string('doi')->nullable();
                $table->string('title', 600)->nullable();
                $table->string('authors', 1000)->nullable();
                $table->unsignedSmallInteger('year')->nullable();
                $table->string('journal')->nullable();
                $table->string('url', 600)->nullable();
                $table->string('pdf_url', 600)->nullable();
                $table->timestamps();
            });
        }

        // Small key/value store (harvest cursor etc.). Not the cache: "Clear cache" must not reset it.
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('external_works');
        Schema::dropIfExists('app_settings');
    }
};
