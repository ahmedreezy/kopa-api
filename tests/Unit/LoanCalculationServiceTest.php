<?php

namespace Tests\Unit;

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
        $this->assertSame(550000, array_sum(array_column($result['schedule'], 'amount_due')));
        $this->assertCount(4, $result['schedule']);
    }
}
