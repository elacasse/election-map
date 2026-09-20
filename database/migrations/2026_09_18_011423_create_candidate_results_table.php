<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('snapshot_id')
                  ->constrained('election_snapshots')
                  ->cascadeOnDelete();

            $table->foreignId('candidate_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->unsignedInteger('vote_count');

            $table->decimal('vote_rate', 7, 4);

            $table->unsignedInteger('advance_vote_count');

            $table->unique([
                'snapshot_id',
                'candidate_id',
            ]);

            $table->index([
                'candidate_id',
                'snapshot_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_results');
    }
};
