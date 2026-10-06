<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->dropColumn('declared_disposable_income');
        });

        Schema::connection('tenant')->table('guarantors', function (Blueprint $table) {
            $table->string('id_type')->default('national_id')->after('phone');
        });

        Schema::connection('tenant')->table('collateral', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->unsignedBigInteger('declared_disposable_income')->nullable()->after('source_of_repayment');
        });

        Schema::connection('tenant')->table('guarantors', function (Blueprint $table) {
            $table->dropColumn('id_type');
        });

        Schema::connection('tenant')->table('collateral', function (Blueprint $table) {
            $table->text('description')->nullable(false)->change();
        });
    }
};
