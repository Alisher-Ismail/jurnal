<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        foreach (['article', 'maqola'] as $name) {
            if (! Schema::hasColumn($name, 'pdf_file_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('pdf_file_id')->nullable()->after('pdf');
                });
            }
        }

        // Issue PDFs may now live only in Telegram, so the local path becomes optional.
        // Keep the column's existing type/length so long file names are never truncated.
        if (DB::getDriverName() === 'mysql') {
            $column = DB::selectOne(
                'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['maqola', 'pdf']
            );
            if ($column && $column->IS_NULLABLE === 'NO') {
                DB::statement('ALTER TABLE maqola MODIFY pdf '.$column->COLUMN_TYPE.' NULL');
            }
        }
    }

    public function down()
    {
        Schema::table('article', function (Blueprint $table) {
            $table->dropColumn('pdf_file_id');
        });
        Schema::table('maqola', function (Blueprint $table) {
            $table->dropColumn('pdf_file_id');
        });
    }
};
