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
        Schema::create('electoral_districts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('election_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // numeroCirconscription
            $table->unsignedInteger('source_district_number');

            $table->string('name');

            $table->timestamps();

            $table->unique([
                'election_id',
                'source_district_number',
            ]);

            $table->index('election_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electoral_districts');
    }
};
