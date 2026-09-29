<?php

namespace App\Services\Loans;

use App\Models\LoanProduct;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class LoanCalculationService
{
    public function calculateForProduct(LoanProduct $product, array $terms): array
    {
        $principal = (int) $terms['principal_amount'];
        $duration = (int) $terms['duration'];
        $unit = $terms['duration_unit'];
        $rate = (float) $product->interest_rate;
        $months = $this->months($duration, $unit);
        $years = $this->days($duration, $unit) / 365;
        $rateMultiplier = match ($product->interest_period) {
            'monthly' => $months,
            'annual' => $years,
            default => 1,
        };
        $interest = (int) round($principal * ($rate / 100) * $rateMultiplier);
        $fees = $this->processingFee($product, $principal);

        return $this->schedule($principal, $interest, $duration, $unit, $terms['repayment_frequency'], $terms['first_repayment_date']) + [
            'interest_rate' => $product->interest_rate,
            'interest_period' => $product->interest_period,
            'interest_method' => 'simple',
            'fees_amount' => $fees,
            'net_disbursement_amount' => $principal - $fees,
            'effective_monthly_rate' => round(($interest / max(1, $principal)) * 100 / max($months, 0.0001), 4),
            'effective_annual_rate' => round(($interest / max(1, $principal)) * 100 / max($years, 0.0001), 4),
        ];
    }

    public function calculate(array $terms): array
    {
        $principal = (int) $terms['principal_amount'];
        $duration = (int) $terms['duration'];
        $unit = $terms['duration_unit'] ?? 'months';
        $rate = (float) $terms['interest_rate'];
        if ($principal <= 0 || $duration <= 0 || $rate < 0) {
            throw new InvalidArgumentException('Loan amounts, rate, and duration must be valid positive values.');
        }
        $multiplier = ($terms['interest_period'] ?? 'loan_term') === 'monthly' ? $this->months($duration, $unit) : 1;
        $interest = (int) round($principal * ($rate / 100) * $multiplier);
        $fees = (int) ($terms['fees_amount'] ?? 0);

        return $this->schedule($principal, $interest, $duration, $unit, $terms['repayment_frequency'], $terms['first_repayment_date']) + [
            'fees_amount' => $fees,
            'net_disbursement_amount' => $principal - $fees,
        ];
    }

    private function schedule(int $principal, int $interest, int $duration, string $unit, string $frequency, string $firstDate): array
    {
        $total = $principal + $interest;
        $installments = $this->installmentCount($duration, $unit, $frequency);
        $baseAmount = intdiv($total, $installments);
        $remainder = $total - ($baseAmount * $installments);
        $date = CarbonImmutable::parse($firstDate);
        $schedule = [];
        for ($number = 1; $number <= $installments; $number++) {
            $schedule[] = [
                'installment_number' => $number,
                'due_date' => $this->dateFor($date, $number - 1, $frequency)->toDateString(),
                'amount_due' => $baseAmount + ($number === $installments ? $remainder : 0),
                'amount_paid' => 0,
                'status' => 'upcoming',
            ];
        }

        return [
            'total_interest' => $interest, 'total_payable' => $total, 'installment_amount' => $baseAmount,
            'maturity_date' => end($schedule)['due_date'], 'schedule' => $schedule,
        ];
    }

    private function processingFee(LoanProduct $product, int $principal): int
    {
        $fee = $product->processing_fee_type === 'percentage'
            ? (int) round($principal * ((float) $product->processing_fee_value / 100))
            : (int) round((float) ($product->processing_fee_value ?? 0));

        if ($fee < 0 || $fee >= $principal) {
            throw new InvalidArgumentException('The processing fee must be less than the principal.');
        }

        return $fee;
    }

    public function months(int $duration, string $unit): float
    {
        return match ($unit) {
            'days' => $duration / 30.4375, 'weeks' => ($duration * 7) / 30.4375, default => $duration
        };
    }

    private function days(int $duration, string $unit): int
    {
        return match ($unit) {
            'days' => $duration, 'weeks' => $duration * 7, default => (int) round($duration * 365 / 12)
        };
    }

    private function installmentCount(int $duration, string $unit, string $frequency): int
    {
        $days = $this->days($duration, $unit);

        return max(1, match ($frequency) {
            'daily' => (int) ceil($days), 'weekly' => (int) ceil($days / 7),
            'biweekly' => (int) ceil($days / 14), 'monthly' => (int) ceil($days / 30.4375),
            default => (int) ceil($days / 30.4375),
        });
    }

    private function dateFor(CarbonImmutable $date, int $offset, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => $date->addDays($offset), 'weekly' => $date->addWeeks($offset),
            'biweekly' => $date->addWeeks($offset * 2), default => $date->addMonthsNoOverflow($offset),
        };
    }
}
