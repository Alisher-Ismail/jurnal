<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // English and Russian team pages used to read the Uzbek "team" table.
    public function up()
    {
        foreach (['teameng', 'teamru'] as $name) {
            if (Schema::hasTable($name)) {
                continue;
            }
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->text('team');
                $table->timestamps();
            });

            $current = DB::table('team')->orderByDesc('id')->first();
            if ($current) {
                DB::table($name)->insert([
                    'team' => $current->team,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('teameng');
        Schema::dropIfExists('teamru');
    }
};
