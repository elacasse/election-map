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
        Schema::create('election_parties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('election_id')
                ->constrained()
                ->cascadeOnDelete();

            // numeroPartiPolitique provenant d'Élections Québec
            $table->unsignedInteger('source_party_number');

            $table->string('name');
            $table->string('abbreviation', 64);

            // Sans le # : 000000 à FFFFFF
            $table->char('color', 6)->nullable();

            $table->timestamps();

            $table->unique([
                'election_id',
                'source_party_number',
            ]);

            $table->index('election_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_parties');
    }
};
