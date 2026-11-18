<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CRC32 fingerprints go up to 4,294,967,295. On some MySQL servers the column ended up as a signed
    // INT (max 2,147,483,647), so half of all inserts failed with "Out of range value for column 'hash'".
    public function up()
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $column = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['plagiarism_fingerprints', 'hash']
        );
        if ($column && strtolower($column->DATA_TYPE) !== 'bigint') {
            DB::statement('ALTER TABLE plagiarism_fingerprints MODIFY `hash` BIGINT NOT NULL');
        }
    }

    public function down()
    {
        //
    }
};
