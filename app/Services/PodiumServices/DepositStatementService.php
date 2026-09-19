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
     * **همه‌ی** صورت‌حساب‌های بازه — صفحه‌به‌صفحه تا انتها.
     *
     * چرا لازم است: `getStatements()` یک صفحه می‌گیرد و پیش از این تنها فراخوانش
     * (`getIncomingDeposits`) پارامترهای صفحه‌بندی را نه می‌گرفت و نه پاس می‌داد،
     * پس همیشه فقط ۵۰ ردیفِ **اول** خوانده می‌شد. و چون بانک واریز و برداشت را
     * با هم برمی‌گرداند و فیلترِ «فقط واریز» *بعد* از بریده‌شدن اجرا می‌شد، یک
     * بازه با ۵۰ برداشتِ اول، «صفر واریز» گزارش می‌کرد — که از «واریزی نبود»
     * قابل تشخیص نیست.
     *
     * حلقه وقتی می‌ایستد که صفحه‌ای کوتاه‌تر از `page_size` برگردد. `max_pages`
     * گاردِ حلقه‌ی بی‌پایان است اگر بانک همیشه صفحه‌ی پر بدهد.
     *
     * @return list<array<string,mixed>>
     */
    public function getAllStatements(
        string $fromDate,
        string $toDate,
        string $fromTime = '00:00:00',
        string $toTime = '23:59:59',
    ): array {
        $pageSize = max(1, (int) Config::get('podium.deposit.statement.page_size', 100));
        $maxPages = max(1, (int) Config::get('podium.deposit.statement.max_pages', 500));

        $all = [];
        $firstIndex = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $chunk = $this->getStatements($fromDate, $toDate, $fromTime, $toTime, $firstIndex, $pageSize);
            $count = count($chunk);

            foreach ($chunk as $stmt) {
                $all[] = $stmt;
            }

            // صفحه‌ی کوتاه = پایانِ بازه.
            if ($count < $pageSize) {
                return $all;
            }

            $firstIndex += $pageSize;
        }

        // به سقف خوردیم — یعنی احتمالاً هنوز ردیفِ خوانده‌نشده هست. بی‌صدا رد
        // نمی‌شویم: همین حالت بود که واریزها را نامرئی می‌کرد.
        Log::warning('DepositStatementService: به سقفِ صفحه‌بندی خوردیم؛ ممکن است ردیفی خوانده نشده باشد', [
            'from' => "{$fromDate} {$fromTime}",
            'to' => "{$toDate} {$toTime}",
            'max_pages' => $maxPages,
            'page_size' => $pageSize,
            'fetched' => count($all),
        ]);

        return $all;
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
        // فیلتر روی **کلِ** بازه اجرا می‌شود، نه روی صفحه‌ی اول.
        $statements = $this->getAllStatements($fromDate, $toDate, $fromTime, $toTime);

        return array_values(array_filter($statements, function (array $stmt): bool {
            $creditor = (int) ($stmt['CreditorAmount'] ?? 0);

            return $creditor > 0;
        }));
    }
}
