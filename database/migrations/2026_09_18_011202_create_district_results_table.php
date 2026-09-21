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
        Schema::create('district_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('snapshot_id')
                  ->constrained('election_snapshots')
                  ->cascadeOnDelete();

            $table->foreignId('electoral_district_id')
                  ->constrained('electoral_districts')
                  ->cascadeOnDelete();

            $table->unsignedSmallInteger(
                'polling_station_completed_count'
            );

            $table->unsignedSmallInteger(
                'polling_station_count'
            );

            $table->unsignedInteger('valid_vote_count');
            $table->unsignedInteger('rejected_vote_count');
            $table->unsignedInteger('cast_vote_count');
            $table->unsignedInteger('registered_voter_count');

            $table->decimal('valid_vote_rate', 7, 4);
            $table->decimal('rejected_vote_rate', 7, 4);
            $table->decimal('participation_rate', 7, 4)->nullable();

            $table->boolean('results_final')->default(false);

            /*
             * iso8601DateMAJ spécifique à la circonscription.
             */
            $table->dateTime('source_updated_at', 3)->nullable();

            $table->unique([
                'snapshot_id',
                'electoral_district_id',
            ]);

            /*
             * Très utile pour retrouver l'historique d'une
             * circonscription particulière.
             */
            $table->index([
                'electoral_district_id',
                'snapshot_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('district_results');
    }
};
