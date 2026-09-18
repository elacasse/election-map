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
        Schema::create('election_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('election_id')
                  ->constrained()
                  ->cascadeOnDelete();

            /*
             * Moment où notre application a obtenu ce nouvel état.
             * Stocké en UTC.
             */
            $table->dateTime('captured_at', 3);

            /*
             * Header HTTP ETag.
             */
            $table->string('source_etag', 255)->nullable();

            /*
             * Header HTTP Last-Modified, parsé et converti en UTC.
             */
            $table->dateTime('source_last_modified_at', 3)->nullable();

            /*
             * iso8601DateMAJ provenant du JSON.
             */
            $table->dateTime('source_updated_at', 3)->nullable();

            /*
             * SHA-256 de la représentation normalisée des résultats.
             */
            $table->char('results_hash', 64);

            $table->boolean('results_final')->default(false);

            $table->timestamps();

            /*
             * Deux états réellement identiques ne doivent pas pouvoir
             * être sauvegardés deux fois pour la même élection.
             */
            $table->unique([
                'election_id',
                'results_hash',
            ]);

            $table->index([
                'election_id',
                'captured_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_snapshots');
    }
};
