<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monopoly_players', function (Blueprint $table) {
            $table->unsignedTinyInteger('houses_built_this_turn')->default(0)->after('last_roll');
        });
    }

    public function down(): void
    {
        Schema::table('monopoly_players', function (Blueprint $table) {
            $table->dropColumn('houses_built_this_turn');
        });
    }
};
