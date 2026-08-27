<?php

namespace Database\Seeders;

use App\Models\Borrower;
use App\Services\Loans\LoanService;
use App\Services\Repayments\RepaymentService;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $result = app(TenantProvisioningService::class)->provision(
            ['name' => 'Kiboga Capital', 'phone' => '+256 772 400 118'],
            ['name' => 'Ahmed Kato', 'email' => 'owner@kiboga.ug', 'phone' => '0772 400 118', 'password' => 'password'],
        );
        $user = $result['user'];
        $names = [
            ['John Kato', '0772418650', 'Kampala', 'Retail shop'],
            ['Sarah Namusoke', '0751293884', 'Wakiso', 'Produce trader'],
            ['Peter Ssenyonga', '0704661209', 'Mukono', 'Boda boda rider'],
            ['Musa Mugisha', '0788120443', 'Kampala', 'Carpenter'],
            ['Joan Nakato', '0762908112', 'Entebbe', 'Salon owner'],
        ];
        $principals = [300000, 500000, 1000000, 500000, 2500000];

        foreach ($names as $index => [$name, $phone, $district, $occupation]) {
            $borrower = Borrower::query()->create([
                'branch_id' => $user->branch_id, 'full_name' => $name, 'phone_number' => $phone,
                'district' => $district, 'occupation' => $occupation,
            ]);
            $loan = app(LoanService::class)->create([
                'borrower_id' => $borrower->id, 'branch_id' => $user->branch_id,
                'principal_amount' => $principals[$index], 'interest_rate' => '10.0000',
                'interest_method' => 'simple', 'interest_period' => 'loan_term',
                'duration' => 4, 'duration_unit' => 'weeks', 'repayment_frequency' => 'weekly',
                'disbursement_date' => today()->subWeeks(2 + $index)->toDateString(),
                'first_repayment_date' => today()->subWeeks(1 + $index)->toDateString(), 'fees_amount' => 0,
            ], $user->id);
            if ($index > 1) {
                app(RepaymentService::class)->post($loan, [
                    'amount' => min(100000, $loan->total_payable),
                    'payment_method' => $index % 2 ? 'cash' : 'mtn_momo',
                    'transaction_reference' => 'DEMO-'.($index + 1), 'paid_at' => now()->subDays($index),
                ], $user->id);
            }
        }
    }
}
