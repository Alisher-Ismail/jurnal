<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Production tables may already exist (created from an SQL dump); never fail on them.
        if (Schema::hasTable('article')) {
            return;
        }

        Schema::create('article', function (Blueprint $table) {
            $table->id();
            $table->string('volume')->nullable();
            $table->string('title')->nullable();
            $table->string('keywords')->nullable();
            $table->string('author')->nullable();
            $table->text('abstract')->nullable();
            $table->string('pdf')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article');
    }
};
