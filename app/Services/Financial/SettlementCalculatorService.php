<?php

declare(strict_types=1);

namespace App\Services\Financial;

class SettlementCalculatorService
{
    /**
     * محاسبه POO (مبلغ پرداختی خریدار)
     * فرمول: [Trans + OP x WBS] x (1-%OW) x (1+VAT)
     */
    public function calculatePOO(float $trans, float $op, float $wbs, float $owPercent, float $vatPercent): float
    {
        $base = $trans + ($op * $wbs);
        $afterDiscount = $base * (1 - ($owPercent / 100));
        return $afterDiscount * (1 + ($vatPercent / 100));
    }

    /**
     * محاسبه PTS (مبلغ پرداختی به سوپلایر)
     * فرمول: [Trans + OP x WBS] x (1 - (%OW + %SSG + %CO)) x (1+VAT)
     */
    public function calculatePTS(
        float $trans, float $op, float $wbs,
        float $owPercent, float $ssgPercent, float $coPercent, float $vatPercent
    ): float {
        $base = $trans + ($op * $wbs);
        $totalDeduction = ($owPercent + $ssgPercent + $coPercent) / 100;

        $share = $base * (1 - $totalDeduction);
        return $share * (1 + ($vatPercent / 100));
    }
}
