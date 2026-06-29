<?php

namespace App\Traits;

trait ConvertNumberToWord
{
    public function numberToWords($number): string
    {
        $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
        $thousands = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون'];

        if ($number == 0) {
            return 'صفر';
        }

        $number = str_pad((string) $number, ceil(strlen((string) $number) / 3) * 3, '0', STR_PAD_LEFT);
        $parts = str_split($number, 3);
        $result = [];

        foreach ($parts as $key => $part) {
            $part = (int) $part;
            if ($part == 0) {
                continue;
            }

            $hundredsDigit = floor($part / 100);
            $tensDigit = floor(($part % 100) / 10);
            $onesDigit = $part % 10;
            $partText = [];

            if ($hundredsDigit > 0) {
                $names = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
                $partText[] = $names[$hundredsDigit];
            }

            if ($tensDigit > 1) {
                $partText[] = $tens[$tensDigit];
                if ($onesDigit > 0) {
                    $partText[] = $ones[$onesDigit];
                }
            } elseif ($tensDigit == 1) {
                $partText[] = $ones[10 + $onesDigit];
            } elseif ($onesDigit > 0) {
                $partText[] = $ones[$onesDigit];
            }

            $level = count($parts) - $key - 1;
            $result[] = implode(' و ', $partText).($level > 0 ? ' '.$thousands[$level] : '');
        }

        return implode(' و ', $result);
    }
}
