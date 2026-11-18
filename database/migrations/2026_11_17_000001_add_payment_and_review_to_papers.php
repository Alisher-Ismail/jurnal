<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Payment: the administrator confirms the author's payment; the (single) certificate follows it.
 * - Review (taqriz): an editor writes the review and a recommendation on the paper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            if (! Schema::hasColumn('papers', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
                $table->string('paid_by', 100)->nullable();
            }
            if (! Schema::hasColumn('papers', 'review_text')) {
                $table->text('review_text')->nullable();
                $table->string('review_decision', 30)->nullable();
                $table->string('review_by', 100)->nullable();
                $table->timestamp('review_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'paid_by', 'review_text', 'review_decision', 'review_by', 'review_at']);
        });
    }
};
