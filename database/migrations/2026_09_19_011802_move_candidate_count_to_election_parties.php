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
        Schema::table('election_parties', function (Blueprint $table) {
            $table->unsignedSmallInteger('candidate_count')
                  ->default(0);
        });

        Schema::table('party_results', function (Blueprint $table) {
            $table->dropColumn('candidate_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('party_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('candidate_count')
                  ->default(0);
        });

        Schema::table('election_parties', function (Blueprint $table) {
            $table->dropColumn('candidate_count');
        });
    }
};
