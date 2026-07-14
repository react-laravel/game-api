<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monopoly_rooms', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('created_by')->index();
            $table->string('name', 40);
            $table->string('status', 20)->default('waiting')->index();
            $table->unsignedTinyInteger('max_players')->default(8);
            $table->unsignedInteger('current_turn_order')->default(0);
            $table->unsignedInteger('round')->default(1);
            $table->json('config')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monopoly_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('monopoly_rooms')->cascadeOnDelete();
            $table->bigInteger('user_id')->nullable()->index();
            $table->string('name', 40);
            $table->string('type', 12)->default('human');
            $table->unsignedTinyInteger('turn_order');
            $table->integer('cash')->default(8000000);
            $table->unsignedTinyInteger('position')->default(0);
            $table->boolean('is_host')->default(false);
            $table->boolean('is_bankrupt')->default(false);
            $table->boolean('is_in_jail')->default(false);
            $table->unsignedTinyInteger('jail_turns')->default(0);
            $table->unsignedTinyInteger('jail_cards')->default(0);
            $table->unsignedTinyInteger('last_roll')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'turn_order']);
            $table->unique(['room_id', 'user_id']);
            $table->index(['room_id', 'is_bankrupt', 'turn_order']);
        });

        Schema::create('monopoly_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('monopoly_rooms')->cascadeOnDelete();
            $table->unsignedTinyInteger('tile_index');
            $table->string('type', 16);
            $table->string('name', 40);
            $table->integer('price')->default(0);
            $table->integer('base_rent')->default(0);
            $table->integer('house_price')->default(0);
            $table->foreignId('owner_player_id')->nullable()->constrained('monopoly_players')->nullOnDelete();
            $table->unsignedTinyInteger('houses')->default(0);
            $table->timestamps();

            $table->unique(['room_id', 'tile_index']);
            $table->index(['room_id', 'owner_player_id']);
        });

        Schema::create('monopoly_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('monopoly_rooms')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('monopoly_players')->nullOnDelete();
            $table->string('type', 32);
            $table->string('message', 255);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monopoly_events');
        Schema::dropIfExists('monopoly_properties');
        Schema::dropIfExists('monopoly_players');
        Schema::dropIfExists('monopoly_rooms');
    }
};
