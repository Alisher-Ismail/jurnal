<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // English/Russian page tables existed only in the production SQL dump; create them on fresh installs.
    private const TABLES = [
        'abouteng' => 'about', 'aboutru' => 'about',
        'aimeng' => 'aim', 'aimru' => 'aim',
        'instructioneng' => 'instruction', 'instructionru' => 'instruction',
        'plagarismeng' => 'plagarism', 'plagarismru' => 'plagarism',
        'submissioneng' => 'submission', 'submissionru' => 'submission',
    ];

    public function up()
    {
        foreach (self::TABLES as $name => $column) {
            if (Schema::hasTable($name)) {
                continue;
            }
            Schema::create($name, function (Blueprint $table) use ($column) {
                $table->id();
                $table->text($column);
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        //
    }
};
