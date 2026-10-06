<?php

namespace Database\Seeders;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\ConsentRecord;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Loans\LoanService;
use App\Services\Repayments\RepaymentService;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CollectionsDemoSeeder extends Seeder
{
    private const TENANT_SLUG = 'kiboga-capital';

    private const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The collections demo may only be seeded in local or testing environments.');
        }

        [$tenant, $owner] = $this->tenantAndOwner();
        app(TenantContext::class)->initialize($tenant);

        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
        $owner->forceFill([
            'branch_id' => $branch->id,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ])->save();

        $demoStaff = [
            'manager' => ['name' => 'Joan Namusoke', 'email' => 'manager@kiboga.ug', 'phone' => '0772 700 201'],
            'loan_officer' => ['name' => 'Brian Ssenyonga', 'email' => 'officer@kiboga.ug', 'phone' => '0772 700 203'],
            'collector' => ['name' => 'Mercy Achieng', 'email' => 'collector@kiboga.ug', 'phone' => '0772 700 202'],
            'accountant' => ['name' => 'Patience Atuhairwe', 'email' => 'accountant@kiboga.ug', 'phone' => '0772 700 204'],
        ];
        $users = [];
        foreach ($demoStaff as $role => $details) {
            $users[$role] = User::query()->updateOrCreate(
                ['email' => $details['email']],
                $details + [
                    'branch_id' => $branch->id,
                    'role' => $role,
                    'password' => Hash::make(self::PASSWORD),
                    'is_active' => true,
                ],
            );
        }
        $collector = $users['collector'];

        $product = LoanProduct::query()->updateOrCreate(
            ['code' => 'DEMO-WEEKLY-500'],
            [
                'name' => 'Weekly Working Capital 500K',
                'description' => 'Four-week working-capital loan used by the collections demo.',
                'is_active' => true,
                'principal_amount' => 500_000,
                'interest_rate' => 10,
                'interest_period' => 'loan_term',
                'interest_method' => 'simple',
                'duration' => 4,
                'duration_unit' => 'weeks',
                'repayment_frequencies' => ['weekly'],
                'processing_fee_type' => 'fixed',
                'processing_fee_value' => 10_000,
                'required_documents' => [],
            ],
        );

        $scenarios = [
            [
                'key' => 'due-today',
                'name' => 'Grace Namukasa',
                'phone' => '0700 200 101',
                'nin' => 'CM00000101D0MO',
                'district' => 'Kampala',
                'occupation' => 'Market vendor',
                'organization' => 'Owino Fresh Foods',
                'income' => 1_400_000,
                'first_due' => today(),
                'payment' => null,
            ],
            [
                'key' => 'overdue-partial',
                'name' => 'Moses Okello',
                'phone' => '0700 200 102',
                'nin' => 'CM00000102D0MO',
                'district' => 'Wakiso',
                'occupation' => 'Carpenter',
                'organization' => 'Okello Furniture Works',
                'income' => 1_850_000,
                'first_due' => today()->subDays(14),
                'payment' => 'partial',
            ],
            [
                'key' => 'overdue-advanced',
                'name' => 'Denis Kato',
                'phone' => '0700 200 103',
                'nin' => 'CM00000103D0MO',
                'district' => 'Mukono',
                'occupation' => 'Motorcycle mechanic',
                'organization' => 'Kato Cycle Garage',
                'income' => 1_600_000,
                'first_due' => today()->subDays(14),
                'payment' => 'first_installment',
            ],
            [
                'key' => 'upcoming',
                'name' => 'Amina Nansubuga',
                'phone' => '0700 200 104',
                'nin' => 'CM00000104D0MO',
                'district' => 'Kampala',
                'occupation' => 'Tailor',
                'organization' => 'Amina Garments',
                'income' => 1_250_000,
                'first_due' => today()->addDays(3),
                'payment' => null,
            ],
            [
                'key' => 'completed',
                'name' => 'Rita Atim',
                'phone' => '0700 200 105',
                'nin' => 'CM00000105D0MO',
                'district' => 'Kampala',
                'occupation' => 'Restaurant owner',
                'organization' => 'Atim Kitchen',
                'income' => 2_300_000,
                'first_due' => today()->subDays(28),
                'payment' => 'complete',
            ],
        ];

        foreach ($scenarios as $scenario) {
            $borrower = $this->borrower($scenario, $branch, $owner);
            $loan = Loan::query()->where('borrower_id', $borrower->id)
                ->where('loan_product_id', $product->id)
                ->first();

            if (! $loan) {
                $loan = $this->loan($scenario, $borrower, $branch, $product, $owner);
            }

            $this->seedPayment($scenario, $loan, $collector);
        }

        $this->command?->info('Collections demo ready: kiboga-capital / owner, manager, officer, collector or accountant @kiboga.ug / password');
    }

    private function tenantAndOwner(): array
    {
        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->first();
        if (! $tenant) {
            $result = app(TenantProvisioningService::class)->provision(
                ['name' => 'Kiboga Capital', 'phone' => '+256 772 400 118'],
                [
                    'name' => 'Ahmed Kato',
                    'email' => 'owner@kiboga.ug',
                    'phone' => '0772 400 118',
                    'password' => self::PASSWORD,
                ],
            );

            return [$result['tenant'], $result['user']];
        }

        app(TenantContext::class)->initialize($tenant);
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        $owner = User::query()->where('email', 'owner@kiboga.ug')->firstOrFail();

        return [$tenant, $owner];
    }

    private function borrower(array $scenario, Branch $branch, User $owner): Borrower
    {
        $borrower = Borrower::query()->updateOrCreate(
            ['phone_number' => $scenario['phone']],
            [
                'branch_id' => $branch->id,
                'borrower_type' => 'sole_trader',
                'full_name' => $scenario['name'],
                'date_of_birth' => '1990-06-15',
                'email' => null,
                'id_type' => 'national_id',
                'nin' => $scenario['nin'],
                'address' => $scenario['district'].' Central',
                'district' => $scenario['district'],
                'sub_county' => 'Central Division',
                'parish' => 'Central Parish',
                'village' => 'Market Village',
                'lc1_reference' => null,
                'occupation' => $scenario['occupation'],
                'organization_name' => $scenario['organization'],
                'average_monthly_income' => $scenario['income'],
                'repayment_source' => 'Daily business sales',
                'next_of_kin' => 'Demo Next of Kin',
                'next_of_kin_relationship' => 'Sibling',
                'next_of_kin_phone' => '0700 299 999',
                'next_of_kin_address' => $scenario['district'],
                'notes' => 'Demo borrower for collections testing.',
                'consent_given_at' => now(),
                'consent_notice_version' => '2026-09',
            ],
        );

        ConsentRecord::query()->firstOrCreate(
            ['borrower_id' => $borrower->id, 'purpose' => 'credit_application_and_servicing'],
            ['notice_version' => '2026-09', 'granted_at' => now(), 'recorded_by' => $owner->id],
        );

        foreach (['national_id_front', 'national_id_back'] as $category) {
            $this->document(Borrower::class, $borrower->id, $category, $owner, $scenario['key'].'-'.$category.'.png');
        }

        return $borrower;
    }

    private function loan(array $scenario, Borrower $borrower, Branch $branch, LoanProduct $product, User $owner): Loan
    {
        $evidence = $this->document(
            'temporary',
            $owner->id,
            'security_evidence',
            $owner,
            $scenario['key'].'-collateral.png',
        );
        $firstDue = Carbon::parse($scenario['first_due']);

        return app(LoanService::class)->create([
            'borrower_id' => $borrower->id,
            'branch_id' => $branch->id,
            'principal_amount' => $product->principal_amount,
            'duration' => $product->duration,
            'duration_unit' => $product->duration_unit,
            'repayment_frequency' => 'weekly',
            'disbursement_date' => $firstDue->copy()->subWeek()->toDateString(),
            'first_repayment_date' => $firstDue->toDateString(),
            'purpose' => 'Purchase stock and supplies for '.$scenario['organization'].'.',
            'source_of_repayment' => 'Daily business sales',
            'guarantors' => [],
            'collateral' => [[
                'security_type' => 'inventory',
                'description' => null,
                'estimated_value' => 750_000,
                'owner' => $borrower->full_name,
                'condition' => 'Good',
                'location' => $borrower->address,
                'custody_status' => 'with_borrower',
                'notes' => 'Demo collateral evidence.',
                'document_ids' => [$evidence->id],
            ]],
        ], $product, $owner->id);
    }

    private function seedPayment(array $scenario, Loan $loan, User $collector): void
    {
        if (! $scenario['payment'] || $loan->repayments()->where('entry_type', 'payment')->exists()) {
            return;
        }

        $data = [
            'payment_method' => 'cash',
            'paid_at' => now()->subDay(),
            'notes' => 'Seeded collections demo payment.',
        ];

        if ($scenario['payment'] === 'first_installment') {
            $data['schedule_id'] = $loan->schedules()->firstOrFail()->id;
        } else {
            $data['amount'] = $scenario['payment'] === 'complete' ? $loan->outstanding_amount : 40_000;
        }

        app(RepaymentService::class)->post($loan, $data, $collector->id);
    }

    private function document(string $type, string $id, string $category, User $uploader, string $name): Document
    {
        $existing = Document::query()
            ->where('documentable_type', $type)
            ->where('documentable_id', $id)
            ->where('category', $category)
            ->first();
        if ($existing) {
            return $existing;
        }

        $path = 'demo/collections/'.$id.'/'.$name;
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        Storage::disk('local')->put($path, $contents);

        return Document::query()->create([
            'documentable_type' => $type,
            'documentable_id' => $id,
            'category' => $category,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size_bytes' => strlen($contents),
            'uploaded_by' => $uploader->id,
        ]);
    }
}
