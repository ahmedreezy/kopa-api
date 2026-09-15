<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->table('tenants', function (Blueprint $table) {
            $table->string('regulatory_class')->default('money_lender')->after('plan');
            $table->string('umra_license_number')->nullable()->after('regulatory_class');
            $table->date('umra_license_expires_on')->nullable()->after('umra_license_number');
            $table->string('pdpo_registration_number')->nullable()->after('umra_license_expires_on');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['regulatory_class', 'umra_license_number', 'umra_license_expires_on', 'pdpo_registration_number']);
        });
    }
};
