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
        Schema::table('party_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('won_district_count')
                ->default(0)
                ->after('leading_district_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('party_results', function (Blueprint $table) {
            $table->dropColumn('leading_district_count');
        });
    }
};
