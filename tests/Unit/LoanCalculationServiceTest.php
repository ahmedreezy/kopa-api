<?php

namespace Tests\Unit;

use App\Models\LoanProduct;
use App\Services\Loans\LoanCalculationService;
use PHPUnit\Framework\TestCase;

class LoanCalculationServiceTest extends TestCase
{
    public function test_it_uses_integer_money_and_balances_the_schedule_exactly(): void
    {
        $result = (new LoanCalculationService)->calculate([
            'principal_amount' => 500000, 'interest_rate' => '10.0000',
            'interest_period' => 'loan_term', 'duration' => 4, 'duration_unit' => 'weeks',
            'repayment_frequency' => 'weekly', 'first_repayment_date' => '2026-09-02', 'fees_amount' => 0,
        ]);

        $this->assertSame(50000, $result['total_interest']);
        $this->assertSame(550000, $result['total_payable']);
        $this->assertSame(500000, $result['net_disbursement_amount']);
        $this->assertSame(550000, array_sum(array_column($result['schedule'], 'amount_due')));
        $this->assertCount(4, $result['schedule']);
    }

    public function test_fixed_processing_fee_is_deducted_from_disbursement_only(): void
    {
        $product = (new LoanProduct)->forceFill([
            'interest_rate' => 10, 'interest_period' => 'loan_term',
            'processing_fee_type' => 'fixed', 'processing_fee_value' => 10000,
        ]);
        $result = (new LoanCalculationService)->calculateForProduct($product, [
            'principal_amount' => 500000, 'duration' => 4, 'duration_unit' => 'weeks',
            'repayment_frequency' => 'weekly', 'first_repayment_date' => '2026-09-02',
        ]);

        $this->assertSame(10000, $result['fees_amount']);
        $this->assertSame(490000, $result['net_disbursement_amount']);
        $this->assertSame(550000, $result['total_payable']);
        $this->assertSame(550000, array_sum(array_column($result['schedule'], 'amount_due')));
    }
}
