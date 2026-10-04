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
        Schema::table('district_results', function (Blueprint $table) {
            $table->string('status', 16)
                ->nullable()
                ->after('results_final');
        });

        Schema::table('party_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('projected_district_count')
                ->default(0)
                ->after('won_district_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('district_results', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('party_results', function (Blueprint $table) {
            $table->dropColumn('projected_district_count');
        });
    }
};
