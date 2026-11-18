<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Free issues: papers published in them get the certificate without a payment. */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('maqola', 'is_free')) {
            Schema::table('maqola', function (Blueprint $table) {
                $table->boolean('is_free')->default(false);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('maqola', 'is_free')) {
            Schema::table('maqola', function (Blueprint $table) {
                $table->dropColumn('is_free');
            });
        }
    }
};
