<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reader numbers shown on each article page: views and PDF downloads on this site (counted by
 * VisitTracker), the same on Zenodo, and citations found by DataCite / OpenAlex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article', function (Blueprint $table) {
            if (! Schema::hasColumn('article', 'views_count')) {
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedInteger('downloads_count')->default(0);
                $table->unsignedInteger('zenodo_views')->default(0);
                $table->unsignedInteger('zenodo_downloads')->default(0);
                $table->unsignedInteger('citations')->default(0);
                $table->timestamp('metrics_checked_at')->nullable()->index();
            }
        });

        // Visits counted before these columns existed.
        if (Schema::hasTable('visit_counts')) {
            foreach (['article' => 'views_count', 'download' => 'downloads_count'] as $kind => $column) {
                $totals = DB::table('visit_counts')->where('kind', $kind)->groupBy('item')->selectRaw('item, SUM(hits) AS total')->pluck('total', 'item');
                foreach ($totals as $id => $total) {
                    DB::table('article')->where('id', (int) $id)->update([$column => (int) $total]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('article', function (Blueprint $table) {
            $table->dropColumn(['views_count', 'downloads_count', 'zenodo_views', 'zenodo_downloads', 'citations', 'metrics_checked_at']);
        });
    }
};
