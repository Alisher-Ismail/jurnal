<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abstracts read from the PDFs: "excerpt" holds the opening of papers that have no abstract,
 * "text_checked_at" marks articles whose PDF was already read (so each is read only once).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('article', function (Blueprint $table) {
            if (! Schema::hasColumn('article', 'excerpt')) {
                $table->text('excerpt')->nullable();
            }
            if (! Schema::hasColumn('article', 'text_checked_at')) {
                $table->timestamp('text_checked_at')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('article', function (Blueprint $table) {
            foreach (['excerpt', 'text_checked_at'] as $column) {
                if (Schema::hasColumn('article', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
