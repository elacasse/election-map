<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('election_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('electoral_district_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('election_party_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // numeroCandidat
            $table->unsignedInteger('source_candidate_number');

            $table->string('last_name');
            $table->string('first_name');

            $table->timestamps();

            $table->unique([
                'election_id',
                'source_candidate_number',
            ]);

            $table->index('electoral_district_id');
            $table->index('election_party_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
