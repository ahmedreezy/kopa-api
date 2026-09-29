<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->unsignedInteger('duration')->default(1)->after('interest_method');
            $table->unsignedBigInteger('processing_fee')->nullable()->after('repayment_frequencies');
            $table->decimal('minimum_collateral_value_percent', 7, 2)->nullable()->after('collateral_required');
        });

        DB::connection('tenant')->table('loan_products')->update([
            'duration' => DB::raw('minimum_duration'),
            'processing_fee' => DB::raw("CASE WHEN fee_type = 'fixed' THEN fee_value ELSE NULL END"),
            'minimum_collateral_value_percent' => DB::raw('collateral_coverage_percent'),
        ]);

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['minimum_duration', 'maximum_duration', 'fee_type', 'fee_value', 'collateral_coverage_percent']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->unsignedInteger('minimum_duration')->default(1)->after('interest_method');
            $table->unsignedInteger('maximum_duration')->default(12)->after('minimum_duration');
            $table->string('fee_type')->default('fixed')->after('repayment_frequencies');
            $table->decimal('fee_value', 12, 2)->default(0)->after('fee_type');
            $table->decimal('collateral_coverage_percent', 7, 2)->nullable()->after('collateral_required');
        });

        DB::connection('tenant')->table('loan_products')->update([
            'minimum_duration' => DB::raw('duration'),
            'maximum_duration' => DB::raw('duration'),
            'fee_value' => DB::raw('COALESCE(processing_fee, 0)'),
            'collateral_coverage_percent' => DB::raw('minimum_collateral_value_percent'),
        ]);

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['duration', 'processing_fee', 'minimum_collateral_value_percent']);
        });
    }
};
