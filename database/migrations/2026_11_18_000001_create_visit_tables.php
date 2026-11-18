<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor statistics, stored as daily totals (small: the database counts towards the hosting quota).
 * No IP addresses are kept: a visitor is a one-day hash, deleted after two days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visit_days')) {
            Schema::create('visit_days', function (Blueprint $table) {
                $table->date('day')->primary();
                $table->unsignedInteger('visitors')->default(0);
                $table->unsignedInteger('views')->default(0);
                $table->unsignedInteger('downloads')->default(0);
                $table->unsignedInteger('bots')->default(0);
            });
        }
        if (! Schema::hasTable('visit_counts')) {
            Schema::create('visit_counts', function (Blueprint $table) {
                $table->date('day');
                $table->string('kind', 16);   // article, download, source, page, lang, device, bot
                $table->string('item', 120);
                $table->unsignedInteger('hits')->default(0);
                $table->primary(['day', 'kind', 'item']);
                $table->index(['kind', 'day']);
            });
        }
        if (! Schema::hasTable('visit_uniques')) {
            Schema::create('visit_uniques', function (Blueprint $table) {
                $table->date('day');
                $table->char('hash', 16);
                $table->primary(['day', 'hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_uniques');
        Schema::dropIfExists('visit_counts');
        Schema::dropIfExists('visit_days');
    }
};
