<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // DOI of the article (e.g. its Zenodo deposit, 10.5281/zenodo.123), empty until one is assigned.
        if (Schema::hasTable('article') && ! Schema::hasColumn('article', 'doi')) {
            Schema::table('article', function (Blueprint $table) {
                $table->string('doi', 191)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('article', 'doi')) {
            Schema::table('article', function (Blueprint $table) {
                $table->dropColumn('doi');
            });
        }
    }
};
