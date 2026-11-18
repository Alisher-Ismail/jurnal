<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Every text the plagiarism check compares against: published articles, issues and submissions.
        if (! Schema::hasTable('plagiarism_documents')) {
            Schema::create('plagiarism_documents', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20);
                $table->unsignedBigInteger('ref_id');
                $table->string('title')->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->unsignedInteger('words')->default(0);
                $table->unsignedInteger('fingerprints')->default(0);
                $table->string('error')->nullable();
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();
                $table->unique(['type', 'ref_id']);
            });
        }

        // Winnowed 5-word fingerprints (see App\Services\Originality\Fingerprinter).
        if (! Schema::hasTable('plagiarism_fingerprints')) {
            Schema::create('plagiarism_fingerprints', function (Blueprint $table) {
                $table->unsignedBigInteger('document_id');
                $table->bigInteger('hash');
                $table->unsignedInteger('position');
                $table->index('hash');
                $table->index('document_id');
            });
        }

        // Latest originality report of each submission.
        if (! Schema::hasTable('paper_checks')) {
            Schema::create('paper_checks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('paper_id')->unique();
                $table->string('status', 20)->default('pending');
                $table->unsignedInteger('words')->default(0);
                $table->decimal('similarity', 5, 1)->nullable();
                $table->longText('sources')->nullable();
                $table->longText('passages')->nullable();
                $table->unsignedSmallInteger('web_checked')->default(0);
                $table->unsignedSmallInteger('web_found')->default(0);
                $table->longText('web_matches')->nullable();
                $table->string('web_note')->nullable();
                $table->unsignedTinyInteger('ai_score')->nullable();
                $table->longText('ai_signals')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('paper_checks');
        Schema::dropIfExists('plagiarism_fingerprints');
        Schema::dropIfExists('plagiarism_documents');
    }
};
