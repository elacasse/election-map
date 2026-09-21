<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('election_statistics', function (Blueprint $table) {

            $table->foreignId('snapshot_id')
                  ->constrained('election_snapshots')
                  ->cascadeOnDelete();

            $table->primary('snapshot_id');

            $table->unsignedInteger('polling_station_count');
            $table->unsignedInteger('polling_station_completed_count');

            $table->decimal(
                'polling_station_completed_rate',
                7,
                4
            );

            $table->unsignedInteger('valid_vote_count');
            $table->unsignedInteger('rejected_vote_count');
            $table->unsignedInteger('cast_vote_count');
            $table->unsignedInteger('registered_voter_count');

            $table->decimal('participation_rate', 7, 4)->nullable();

            $table->unsignedSmallInteger('electoral_district_count');

            $table->unsignedSmallInteger(
                'electoral_district_with_result_count'
            );

            $table->unsignedSmallInteger(
                'electoral_district_without_result_count'
            );

            $table->decimal(
                'electoral_district_without_result_rate',
                7,
                4
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_statistics');
    }
};
