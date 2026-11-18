<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The production table got "type" from an SQL dump; fresh installs never had it.
    public function up()
    {
        if (! Schema::hasColumn('users', 'type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('type', 100)->default('admin');
            });
        }
    }

    public function down()
    {
        //
    }
};
