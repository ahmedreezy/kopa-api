# Kopa API architecture

Kopa uses Laravel 12 as a modular monolith with a database-per-tenant boundary.

## Local setup

```sh
composer install
php artisan migrate:fresh --seed
php artisan serve
```

The local environment uses SQLite so development works without external services. `.env.example` configures PostgreSQL for `platform_db` and dynamically provisioned `tenant_000001` databases.

Demo login: company `kiboga-capital`, email `owner@kiboga.ug`, password `password`.

## Data boundaries

- Platform: tenants, subscriptions, provisioning state, platform users, and Sanctum tokens.
- Tenant: branches, staff, borrowers, loans, schedules, repayments, receipts, collateral, guarantors, notifications, and audit logs.
- Authenticated API calls require `Authorization: Bearer <token>` and `X-Tenant: <slug>`.

## Business services

- `TenantProvisioningService` creates, migrates, seeds, verifies, and activates tenant databases.
- `LoanCalculationService` owns integer-money calculations and balanced schedules.
- `RepaymentService` owns transaction-locked posting, allocation, receipts, reversals, and audits.
- `SmsSender` provides a queue-compatible notification provider boundary.

## Verification

```sh
vendor/bin/pint --test
php artisan test
```
