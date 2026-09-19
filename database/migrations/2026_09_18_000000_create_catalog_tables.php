<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Creates the Steam game catalog and the discovery crawl cursor. */
return new class extends Migration
{
    /** Creates games (details and stats tracked as two independent fetch queues) and crawl_state. */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->unsignedInteger('app_id')->primary();
            $table->text('name'); // Steam allows names over 255 chars; varchar(255) crashed the real crawl.

            // Filled by catalog:fetch-details from Steam appdetails.
            $table->text('short_description')->nullable();
            $table->date('release_date')->nullable();
            $table->text('developer')->nullable();
            $table->text('publisher')->nullable();
            $table->integer('price_cents')->nullable();
            $table->json('raw_details')->nullable();
            $table->timestamp('details_fetched_at')->nullable();
            $table->unsignedSmallInteger('details_attempts')->default(0);
            $table->text('details_error')->nullable();

            // Filled by catalog:fetch-stats from SteamSpy, or Steam as the fallback (stats_source says which).
            // ponytail: json tags, no GIN index; move to TEXT[] + GIN when recommendation queries need it.
            $table->json('tags')->nullable();
            $table->unsignedInteger('review_count')->nullable();
            $table->decimal('positive_ratio', 4, 3)->nullable();
            $table->string('stats_source')->nullable();
            $table->timestamp('stats_fetched_at')->nullable();
            $table->unsignedSmallInteger('stats_attempts')->default(0);
            $table->text('stats_error')->nullable();

            $table->timestamps();
            $table->index(['details_fetched_at', 'details_attempts']);
            $table->index(['stats_fetched_at', 'stats_attempts']);
        });

        Schema::create('crawl_state', function (Blueprint $table) {
            $table->string('crawl_type')->primary();
            $table->unsignedInteger('last_app_id')->nullable();
            $table->timestamp('last_modified_since')->nullable();
            $table->timestamp('last_completed_at')->nullable();
        });
    }

    /** Drops both catalog tables. */
    public function down(): void
    {
        Schema::dropIfExists('crawl_state');
        Schema::dropIfExists('games');
    }
};
