<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('borrowers', function (Blueprint $table) {
            $table->string('organization_name')->nullable()->after('occupation');
            $table->unsignedBigInteger('average_monthly_income')->nullable()->after('organization_name');
            $table->dropColumn([
                'alternative_phone', 'employer_name', 'business_name', 'business_sector', 'tin',
                'years_operating', 'monthly_income', 'monthly_expenses', 'disposable_income',
                'next_of_kin_alternative_phone',
            ]);
        });

        DB::connection('tenant')->table('loan_products')->orderBy('id')->each(function ($product) {
            $requirements = json_decode($product->required_documents ?? '[]', true) ?: [];
            $hadLegacyIdentityRequirement = in_array('national_id_front', $requirements, true)
                || in_array('national_id_back', $requirements, true);
            $requirements = array_values(array_filter(
                $requirements,
                fn (string $category) => ! in_array($category, ['national_id_front', 'national_id_back'], true)
            ));
            if ($hadLegacyIdentityRequirement) {
                array_unshift($requirements, 'identity_document');
            }

            DB::connection('tenant')->table('loan_products')->where('id', $product->id)->update([
                'required_documents' => json_encode(array_values(array_unique($requirements))),
            ]);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('borrowers', function (Blueprint $table) {
            $table->string('alternative_phone')->nullable();
            $table->string('employer_name')->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_sector')->nullable();
            $table->string('tin')->nullable();
            $table->unsignedSmallInteger('years_operating')->nullable();
            $table->unsignedBigInteger('monthly_income')->nullable();
            $table->unsignedBigInteger('monthly_expenses')->nullable();
            $table->unsignedBigInteger('disposable_income')->nullable();
            $table->string('next_of_kin_alternative_phone')->nullable();
            $table->dropColumn(['organization_name', 'average_monthly_income']);
        });

        DB::connection('tenant')->table('loan_products')->orderBy('id')->each(function ($product) {
            $requirements = json_decode($product->required_documents ?? '[]', true) ?: [];
            if (! in_array('identity_document', $requirements, true)) {
                return;
            }
            $requirements = array_values(array_filter($requirements, fn (string $category) => $category !== 'identity_document'));
            array_unshift($requirements, 'national_id_front', 'national_id_back');
            DB::connection('tenant')->table('loan_products')->where('id', $product->id)->update([
                'required_documents' => json_encode(array_values(array_unique($requirements))),
            ]);
        });
    }
};
