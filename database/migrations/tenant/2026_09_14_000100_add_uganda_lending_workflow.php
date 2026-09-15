<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('borrowers', function (Blueprint $table) {
            $table->string('borrower_type')->default('individual')->after('branch_id');
            $table->date('date_of_birth')->nullable()->after('full_name');
            $table->string('email')->nullable()->after('alternative_phone');
            $table->string('id_type')->default('national_id')->after('email');
            $table->string('sub_county')->nullable()->after('district');
            $table->string('parish')->nullable()->after('sub_county');
            $table->string('village')->nullable()->after('parish');
            $table->string('lc1_reference')->nullable()->after('village');
            $table->string('employer_name')->nullable()->after('occupation');
            $table->string('business_name')->nullable()->after('employer_name');
            $table->string('business_sector')->nullable()->after('business_name');
            $table->string('tin')->nullable()->after('business_sector');
            $table->unsignedSmallInteger('years_operating')->nullable()->after('tin');
            $table->unsignedBigInteger('monthly_income')->nullable()->after('years_operating');
            $table->unsignedBigInteger('monthly_expenses')->nullable()->after('monthly_income');
            $table->unsignedBigInteger('disposable_income')->nullable()->after('monthly_expenses');
            $table->text('repayment_source')->nullable()->after('disposable_income');
            $table->string('next_of_kin_relationship')->nullable()->after('next_of_kin');
            $table->string('next_of_kin_alternative_phone')->nullable()->after('next_of_kin_phone');
            $table->string('next_of_kin_address')->nullable()->after('next_of_kin_alternative_phone');
            $table->timestamp('consent_given_at')->nullable()->after('notes');
            $table->string('consent_notice_version')->nullable()->after('consent_given_at');
            $table->unique('nin');
        });

        Schema::connection('tenant')->create('loan_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('minimum_principal');
            $table->unsignedBigInteger('maximum_principal');
            $table->decimal('interest_rate', 9, 4);
            $table->string('interest_period')->default('monthly');
            $table->string('interest_method')->default('simple');
            $table->unsignedInteger('minimum_duration')->default(1);
            $table->unsignedInteger('maximum_duration')->default(12);
            $table->string('duration_unit')->default('months');
            $table->json('repayment_frequencies');
            $table->string('fee_type')->default('fixed');
            $table->decimal('fee_value', 12, 2)->default(0);
            $table->unsignedSmallInteger('minimum_guarantors')->default(0);
            $table->boolean('collateral_required')->default(false);
            $table->decimal('collateral_coverage_percent', 7, 2)->nullable();
            $table->json('required_documents')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->foreignUuid('loan_product_id')->nullable()->after('branch_id')->constrained('loan_products')->nullOnDelete();
            $table->string('purpose')->nullable()->after('created_by');
            $table->text('source_of_repayment')->nullable()->after('purpose');
            $table->unsignedBigInteger('declared_disposable_income')->nullable()->after('source_of_repayment');
            $table->decimal('effective_monthly_rate', 9, 4)->nullable()->after('interest_rate');
            $table->decimal('effective_annual_rate', 9, 4)->nullable()->after('effective_monthly_rate');
            $table->json('product_snapshot')->nullable()->after('installment_amount');
        });

        Schema::connection('tenant')->table('collateral', function (Blueprint $table) {
            $table->unsignedBigInteger('forced_sale_value')->nullable()->after('estimated_value');
            $table->date('valuation_date')->nullable()->after('forced_sale_value');
            $table->string('valuer')->nullable()->after('valuation_date');
            $table->string('location')->nullable()->after('owner');
            $table->string('custody_status')->default('with_borrower')->after('condition');
            $table->string('simpo_registration_number')->nullable()->after('custody_status');
        });

        Schema::connection('tenant')->table('guarantors', function (Blueprint $table) {
            $table->string('alternative_phone')->nullable()->after('phone');
            $table->string('occupation')->nullable()->after('address');
            $table->string('employer_name')->nullable()->after('occupation');
            $table->unsignedBigInteger('monthly_income')->nullable()->after('employer_name');
            $table->timestamp('consent_given_at')->nullable()->after('notes');
        });

        Schema::connection('tenant')->create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('documentable_type');
            $table->uuid('documentable_id');
            $table->string('category');
            $table->string('disk')->default('local');
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['documentable_type', 'documentable_id']);
        });

        Schema::connection('tenant')->create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('borrower_id')->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->string('notice_version');
            $table->timestamp('granted_at');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->table('receipts', function (Blueprint $table) {
            $table->string('status')->default('issued')->after('remaining_balance');
            $table->timestamp('reversed_at')->nullable()->after('status');
            $table->json('snapshot')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipts', fn (Blueprint $table) => $table->dropColumn(['status', 'reversed_at', 'snapshot']));
        Schema::connection('tenant')->dropIfExists('consent_records');
        Schema::connection('tenant')->dropIfExists('documents');
        Schema::connection('tenant')->table('guarantors', fn (Blueprint $table) => $table->dropColumn(['alternative_phone', 'occupation', 'employer_name', 'monthly_income', 'consent_given_at']));
        Schema::connection('tenant')->table('collateral', fn (Blueprint $table) => $table->dropColumn(['forced_sale_value', 'valuation_date', 'valuer', 'location', 'custody_status', 'simpo_registration_number']));
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_product_id');
            $table->dropColumn(['purpose', 'source_of_repayment', 'declared_disposable_income', 'effective_monthly_rate', 'effective_annual_rate', 'product_snapshot']);
        });
        Schema::connection('tenant')->dropIfExists('loan_products');
        Schema::connection('tenant')->table('borrowers', function (Blueprint $table) {
            $table->dropUnique(['nin']);
            $table->dropColumn([
                'borrower_type', 'date_of_birth', 'email', 'id_type', 'sub_county', 'parish', 'village', 'lc1_reference',
                'employer_name', 'business_name', 'business_sector', 'tin', 'years_operating', 'monthly_income',
                'monthly_expenses', 'disposable_income', 'repayment_source', 'next_of_kin_relationship',
                'next_of_kin_alternative_phone', 'next_of_kin_address', 'consent_given_at', 'consent_notice_version',
            ]);
        });
    }
};
