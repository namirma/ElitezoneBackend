<?php

namespace App\Services\PodiumServices;

use App\Services\PodiumClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * سرویس دریافت صورت‌حساب سپرده از پادیوم
 *
 * API: دریافت صورت‌حساب سپرده (شناسه سرویس: ۴۶۶۴۵۵۶۳)
 * مستندات: https://clasor.pod.ir/.../دریافت-صورتحساب-سپرده/...
 *
 * هر Statement در خروجی می‌تواند شامل باشد:
 *   - CreditorAmount: مبلغ بستانکار (واریز ورودی به حساب ما)
 *   - DebtorAmount: مبلغ بدهکار (برداشت از حساب)
 *   - DocNumber: شماره سند یکتای بانک (کلید idempotency)
 *   - TransferMoneyBillNumber: شناسه قبض/واریز → شناسه واریز کاربر اینجاست
 *   - Date / Time: تاریخ و زمان شمسی
 *   - PayaResult.SourceIban: شبای فرستنده برای تراکنش‌های پایا (مستند رسمی)
 *   - SatnaResult.SourceIban: شبای فرستنده برای ساتنا (مستند رسمی)
 *   - SatnaResult.SourceName / SourceLastName: نام فرستنده برای ساتنا (مستند رسمی)
 *   - ItemSystemMetaData: فیلد غیررسمی — فقط در تراکنش‌های پل/Bridge موجود است
 */
class DepositStatementService
{
    public function __construct(protected PodiumClient $client) {}

    /**
     * دریافت لیست صورت‌حساب در یک بازه زمانی شمسی
     *
     * @param  string  $fromDate  تاریخ شروع شمسی (yyyy/mm/dd)
     * @param  string  $toDate  تاریخ پایان شمسی (yyyy/mm/dd)
     * @param  string  $fromTime  ساعت شروع (HH:mm:ss)
     * @param  string  $toTime  ساعت پایان (HH:mm:ss)
     * @param  int  $firstIndex  صفحه‌بندی — اولین نتیجه از صفر شروع می‌شود
     * @param  int  $resultCount  تعداد نتیجه
     * @return array آرایه‌ای از Statement‌ها
     *
     * @throws RuntimeException اگر پادیوم خطا برگرداند
     */
    public function getStatements(
        string $fromDate,
        string $toDate,
        string $fromTime = '00:00:00',
        string $toTime = '23:59:59',
        int $firstIndex = 0,
        int $resultCount = 50,
    ): array {
        $payload = [
            'apiKey' => Config::get('podium.deposit.sc_api_key'),
            'productEntityId' => Config::get('podium.deposit.product_entity_id'),
            'providerParameters' => [
                'body' => [
                    'DepositNumber' => Config::get('podium.deposit.deposit_number'),
                    'FromDate' => $fromDate,
                    'ToDate' => $toDate,
                    'FirstIndex' => $firstIndex,
                    'ResultCount' => $resultCount,
                    'FromTime' => $fromTime,
                    'ToTime' => $toTime,
                ],
            ],
        ];

        $res = $this->client->callSc2($payload);

        if ($res['hasError'] ?? true) {
            Log::error('DepositStatementService: Podium returned error', [
                'hasError' => $res['hasError'] ?? null,
                'referenceNumber' => $res['referenceNumber'] ?? null,
                'errorCode' => $res['errorCode'] ?? null,
                'message' => $res['message'] ?? null,
            ]);

            throw new RuntimeException(
                'Podium deposit statement API error. referenceNumber: '.($res['referenceNumber'] ?? 'N/A')
            );
        }

        // ساختار واقعی پاسخ پادیوم:
        // normalized.result → { IsSuccess, ResultData: [{DepositInfo, Statements:[]}] }
        $normalized = PodiumClient::normalize($res);

        // IsSuccess=false یعنی پادیوم سرویس را اجرا کرد ولی نتیجه‌ای نداشت
        if (data_get($normalized, 'result.IsSuccess') === false) {
            Log::warning('DepositStatementService: Podium returned IsSuccess=false', [
                'referenceNumber' => $res['referenceNumber'] ?? null,
            ]);

            return [];
        }

        $resultData = data_get($normalized, 'result.ResultData', []);

        if (! is_array($resultData)) {
            return [];
        }

        // جمع‌آوری Statements از همه گروه‌های سپرده
        $statements = [];
        foreach ($resultData as $depositGroup) {
            foreach ($depositGroup['Statements'] ?? [] as $stmt) {
                $statements[] = $stmt;
            }
        }

        return $statements;
    }

    /**
     * دریافت صورت‌حساب‌های جدید برای بازه زمانی مشخص
     * فقط ردیف‌هایی که CreditorAmount > 0 دارند (واریز ورودی)
     */
    public function getIncomingDeposits(
        string $fromDate,
        string $toDate,
        string $fromTime = '00:00:00',
        string $toTime = '23:59:59',
    ): array {
        $statements = $this->getStatements($fromDate, $toDate, $fromTime, $toTime);

        return array_filter($statements, function (array $stmt): bool {
            $creditor = (int) ($stmt['CreditorAmount'] ?? 0);

            return $creditor > 0;
        });
    }
}
