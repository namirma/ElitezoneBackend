<?php

declare(strict_types=1);

namespace App\Services\Financial;

class WeighbridgeCalculatorService
{
    public function __construct(
        protected SettlementCalculatorService $baseCalculator
    ) {}

    /**
     * محاسبه اختلاف ریالی (برای خریدار و سوپلایر)
     * خروجی آرایه‌ای شامل اختلاف POO و اختلاف PTS است
     */
    public function calculateDifferences(float $wbs, float $was, float $op, float $ow, float $ssg, float $co, float $vat): array
    {
        // محاسبه اختلاف وزن
        $weightDiff = abs($was - $wbs);

        // اختلاف سهم خریدار (POO Diff)
        $diffPOO = $this->baseCalculator->calculatePOO(0, $op, $weightDiff, $ow, $vat);

        // اختلاف سهم سوپلایر (PTS Diff)
        $diffPTS = $this->baseCalculator->calculatePTS(0, $op, $weightDiff, $ow, $ssg, $co, $vat);

        return [
            'poo_diff' => $diffPOO,
            'pts_diff' => $diffPTS,
        ];
    }
}
