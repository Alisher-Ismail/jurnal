<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // When the author agreed to CC BY 4.0 publication and deposit in Zenodo (null = not asked / not agreed).
        if (Schema::hasTable('papers') && ! Schema::hasColumn('papers', 'license_agreed_at')) {
            Schema::table('papers', function (Blueprint $table) {
                $table->timestamp('license_agreed_at')->nullable();
            });
        }

        // Zenodo record id of the article's deposit (its DOI is kept in article.doi).
        if (Schema::hasTable('article') && ! Schema::hasColumn('article', 'zenodo_id')) {
            Schema::table('article', function (Blueprint $table) {
                $table->string('zenodo_id', 32)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('papers', 'license_agreed_at')) {
            Schema::table('papers', fn (Blueprint $table) => $table->dropColumn('license_agreed_at'));
        }
        if (Schema::hasColumn('article', 'zenodo_id')) {
            Schema::table('article', fn (Blueprint $table) => $table->dropColumn('zenodo_id'));
        }
    }
};
