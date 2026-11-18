<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Indexed text (compressed) and author names, so a match can be checked for authorship.
        Schema::table('plagiarism_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('plagiarism_documents', 'authors')) {
                $table->string('authors', 500)->nullable();
            }
            if (! Schema::hasColumn('plagiarism_documents', 'text_gz')) {
                $table->longText('text_gz')->nullable();
            }
        });

        Schema::table('paper_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('paper_checks', 'conflict')) {
                $table->longText('conflict')->nullable();
                $table->boolean('certificate_held')->default(false);
                // Result of a manual check on an external service (e.g. antiplag.uz), entered by an editor.
                $table->string('external_service', 60)->nullable();
                $table->decimal('external_similarity', 5, 1)->nullable();
                $table->decimal('external_ai', 5, 1)->nullable();
                $table->string('external_report')->nullable();
                $table->string('external_note', 1000)->nullable();
                $table->string('external_by')->nullable();
                $table->timestamp('external_at')->nullable();
            }
        });

        Schema::table('papers', function (Blueprint $table) {
            if (! Schema::hasColumn('papers', 'submitter_ip')) {
                $table->string('submitter_ip', 45)->nullable();
            }
        });
    }

    public function down()
    {
        //
    }
};
