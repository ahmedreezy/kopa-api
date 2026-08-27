<?php

namespace App\Services\Loans;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class LoanCalculationService
{
    public function calculate(array $terms): array
    {
        $principal = (int) $terms['principal_amount'];
        $rate = (string) $terms['interest_rate'];
        $duration = (int) $terms['duration'];
        $unit = $terms['duration_unit'] ?? 'months';
        $frequency = $terms['repayment_frequency'];
        $fees = (int) ($terms['fees_amount'] ?? 0);

        if ($principal <= 0 || $duration <= 0 || bccomp($rate, '0', 4) < 0) {
            throw new InvalidArgumentException('Loan amounts, rate, and duration must be valid positive values.');
        }

        $periodMultiplier = ($terms['interest_period'] ?? 'loan_term') === 'monthly'
            ? $this->months($duration, $unit)
            : 1;
        $interest = (int) round((float) bcmul((string) $principal, bcdiv($rate, '100', 8), 8) * $periodMultiplier);
        $total = $principal + $interest + $fees;
        $installments = $this->installmentCount($duration, $unit, $frequency);
        $baseAmount = intdiv($total, $installments);
        $remainder = $total - ($baseAmount * $installments);
        $firstDate = CarbonImmutable::parse($terms['first_repayment_date']);
        $schedule = [];

        for ($number = 1; $number <= $installments; $number++) {
            $schedule[] = [
                'installment_number' => $number,
                'due_date' => $this->dateFor($firstDate, $number - 1, $frequency)->toDateString(),
                'amount_due' => $baseAmount + ($number === $installments ? $remainder : 0),
                'amount_paid' => 0,
                'status' => 'upcoming',
            ];
        }

        return [
            'total_interest' => $interest,
            'total_payable' => $total,
            'installment_amount' => $baseAmount,
            'maturity_date' => end($schedule)['due_date'],
            'schedule' => $schedule,
        ];
    }

    private function months(int $duration, string $unit): int
    {
        return match ($unit) {
            'days' => max(1, (int) ceil($duration / 30)),
            'weeks' => max(1, (int) ceil($duration / 4)),
            default => $duration,
        };
    }

    private function installmentCount(int $duration, string $unit, string $frequency): int
    {
        $days = match ($unit) {
            'days' => $duration, 'weeks' => $duration * 7, default => $duration * 30
        };

        return max(1, match ($frequency) {
            'daily' => $days,
            'weekly' => (int) ceil($days / 7),
            'biweekly' => (int) ceil($days / 14),
            'monthly' => (int) ceil($days / 30),
            default => (int) ceil($days / 30),
        });
    }

    private function dateFor(CarbonImmutable $date, int $offset, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => $date->addDays($offset),
            'weekly' => $date->addWeeks($offset),
            'biweekly' => $date->addWeeks($offset * 2),
            default => $date->addMonthsNoOverflow($offset),
        };
    }
}
