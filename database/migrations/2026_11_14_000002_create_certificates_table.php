<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Production tables may already exist (created from an SQL dump); never fail on them.
        if (Schema::hasTable('certificates')) {
            return;
        }

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 20);
            $table->unsignedBigInteger('paper_id')->nullable()->index();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->string('recipient');
            $table->string('title');
            $table->string('locale', 5)->default('uz');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('certificates');
    }
};
