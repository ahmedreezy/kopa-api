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
            $table->unsignedBigInteger('principal_amount')->default(1000)->after('is_active');
            $table->string('processing_fee_type')->default('fixed')->after('repayment_frequencies');
            $table->decimal('processing_fee_value', 12, 2)->nullable()->after('processing_fee_type');
        });

        DB::connection('tenant')->table('loan_products')->orderBy('id')->each(function (object $product): void {
            DB::connection('tenant')->table('loan_products')->where('id', $product->id)->update([
                'principal_amount' => $product->minimum_principal,
                'processing_fee_type' => 'fixed',
                'processing_fee_value' => $product->processing_fee,
            ]);
        });

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['minimum_principal', 'maximum_principal', 'processing_fee']);
        });

        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->unsignedBigInteger('net_disbursement_amount')->nullable()->after('fees_amount');
        });

        DB::connection('tenant')->table('loans')->orderBy('id')->each(function (object $loan): void {
            DB::connection('tenant')->table('loans')->where('id', $loan->id)->update([
                'net_disbursement_amount' => max(0, (int) $loan->principal_amount - (int) $loan->fees_amount),
            ]);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->unsignedBigInteger('minimum_principal')->default(1000)->after('is_active');
            $table->unsignedBigInteger('maximum_principal')->default(1000)->after('minimum_principal');
            $table->unsignedBigInteger('processing_fee')->nullable()->after('repayment_frequencies');
        });

        DB::connection('tenant')->table('loan_products')->orderBy('id')->each(function (object $product): void {
            $fixedFee = $product->processing_fee_type === 'fixed' ? $product->processing_fee_value : null;
            DB::connection('tenant')->table('loan_products')->where('id', $product->id)->update([
                'minimum_principal' => $product->principal_amount,
                'maximum_principal' => $product->principal_amount,
                'processing_fee' => $fixedFee,
            ]);
        });

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'processing_fee_type', 'processing_fee_value']);
        });

        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->dropColumn('net_disbursement_amount');
        });
    }
};
