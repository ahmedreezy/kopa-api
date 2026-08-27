<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('tenant')->create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('role')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('borrowers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone_number')->index();
            $table->string('alternative_phone')->nullable();
            $table->string('nin')->nullable()->index();
            $table->string('photo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('district')->nullable();
            $table->string('occupation')->nullable();
            $table->string('next_of_kin')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'created_at']);
        });

        Schema::connection('tenant')->create('loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('loan_number')->unique();
            $table->foreignUuid('borrower_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('principal_amount');
            $table->decimal('interest_rate', 9, 4);
            $table->string('interest_method')->default('simple');
            $table->string('interest_period')->default('loan_term');
            $table->unsignedInteger('duration');
            $table->string('duration_unit')->default('months');
            $table->string('repayment_frequency');
            $table->date('disbursement_date');
            $table->date('first_repayment_date');
            $table->date('maturity_date');
            $table->unsignedBigInteger('fees_amount')->default(0);
            $table->unsignedBigInteger('total_interest');
            $table->unsignedBigInteger('total_payable');
            $table->unsignedBigInteger('installment_amount');
            $table->string('status')->default('draft');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['borrower_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'maturity_date']);
        });

        Schema::connection('tenant')->create('repayment_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->unsignedBigInteger('amount_due');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('status')->default('upcoming');
            $table->timestamps();
            $table->unique(['loan_id', 'installment_number']);
            $table->index(['status', 'due_date']);
        });

        Schema::connection('tenant')->create('repayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reverses_repayment_id')->nullable()->constrained('repayments')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('entry_type')->default('payment');
            $table->string('payment_method');
            $table->string('transaction_reference')->nullable();
            $table->text('notes')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('paid_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['loan_id', 'created_at']);
            $table->unique(['payment_method', 'transaction_reference']);
        });

        Schema::connection('tenant')->create('repayment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('repayment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('repayment_schedule_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('receipt_number')->unique();
            $table->foreignUuid('repayment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('loan_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_paid');
            $table->unsignedBigInteger('remaining_balance');
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('collateral', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained()->cascadeOnDelete();
            $table->string('security_type');
            $table->text('description');
            $table->unsignedBigInteger('estimated_value')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('owner')->nullable();
            $table->string('condition')->nullable();
            $table->text('notes')->nullable();
            $table->json('documents')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('guarantors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('nin')->nullable();
            $table->string('relationship')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->json('documents')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel')->default('sms');
            $table->string('recipient');
            $table->string('template');
            $table->text('message');
            $table->string('provider')->nullable();
            $table->string('status')->default('queued');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'notification_logs', 'guarantors', 'collateral', 'receipts', 'repayment_allocations', 'repayments', 'repayment_schedules', 'loans', 'borrowers', 'users', 'branches'] as $table) {
            Schema::connection('tenant')->dropIfExists($table);
        }
    }
};
