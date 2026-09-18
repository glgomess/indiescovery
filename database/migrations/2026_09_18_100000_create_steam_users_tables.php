<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Replaces Laravel's default users table with Steam users and adds their owned games snapshot. */
return new class extends Migration
{
    /** Creates users keyed by steam id and owned_games; owned_games.app_id has no FK so unknown games still fit. */
    public function up(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->string('steam_id')->primary();
            $table->string('persona_name');
            $table->string('avatar_url')->nullable();
            $table->string('profile_url')->nullable();
            $table->timestamp('steam_created_at')->nullable();
            $table->timestamp('last_login_at');
            $table->timestamp('library_synced_at')->nullable();
            $table->boolean('library_private')->default(false);
            $table->timestamps();
        });

        Schema::create('owned_games', function (Blueprint $table) {
            $table->string('steam_id');
            $table->unsignedInteger('app_id');
            $table->string('name');
            $table->unsignedInteger('playtime_minutes');
            $table->unsignedInteger('playtime_2w_minutes')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->primary(['steam_id', 'app_id']);
            $table->index('app_id');
            $table->foreign('steam_id')->references('steam_id')->on('users')->cascadeOnDelete();
        });
    }

    /** Drops the Steam tables and restores Laravel's default users table. */
    public function down(): void
    {
        Schema::dropIfExists('owned_games');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
