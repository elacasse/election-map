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
        Schema::create('party_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('snapshot_id')
                  ->constrained('election_snapshots')
                  ->cascadeOnDelete();

            $table->foreignId('election_party_id')
                  ->constrained('election_parties')
                  ->cascadeOnDelete();

            $table->unsignedInteger('vote_count');

            $table->decimal('vote_rate', 7, 4);

            $table->unsignedSmallInteger(
                'leading_district_count'
            );

            $table->decimal(
                'leading_district_rate',
                7,
                4
            );

            $table->unsignedSmallInteger('candidate_count');

            $table->unique([
                'snapshot_id',
                'election_party_id',
            ]);

            $table->index('election_party_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('party_results');
    }
};
