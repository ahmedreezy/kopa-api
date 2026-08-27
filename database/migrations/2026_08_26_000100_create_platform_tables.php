<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('sequence')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('database_name')->unique();
            $table->string('owner_email');
            $table->string('status')->default('provisioning')->index();
            $table->string('plan')->default('trial');
            $table->json('settings')->nullable();
            $table->text('provisioning_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('platform')->create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection('platform')->create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plan')->default('trial');
            $table->string('status')->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('platform')->create('provisioning_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('step');
            $table->string('status');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('provisioning_logs');
        Schema::connection('platform')->dropIfExists('subscriptions');
        Schema::connection('platform')->dropIfExists('platform_users');
        Schema::connection('platform')->dropIfExists('tenants');
    }
};
