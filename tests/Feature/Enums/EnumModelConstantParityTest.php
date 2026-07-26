<?php

namespace Tests\Feature\Enums;

use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Enums\Wallet\WalletTransactionStatus;
use App\Enums\Wallet\WalletTransactionType;
use App\Models\Order;
use App\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * پین‌کردن برابری Enum ها با ثابت‌های رشته‌ای مدل.
 *
 * کدبیس در runtime از ثابت‌های مدل (Order::STATUS_CANCELLED) استفاده می‌کند و
 * Enum ها آینهٔ type-safe همان مقادیرند. بدون این تست، اضافه‌شدن یک ثابت جدید به
 * مدل بدون کیس Enum (یا برعکس) بی‌صدا واگرا می‌شد — دقیقاً همان اتفاقی که برای
 * TYPE_HOLD_RELEASE افتاده بود و این تست آن را آشکار کرد.
 */
class EnumModelConstantParityTest extends TestCase
{
    #[Test]
    public function order_status_enum_matches_model_constants(): void
    {
        $this->assertEnumMatchesConstants(OrderStatus::class, Order::class, 'STATUS_');
    }

    #[Test]
    public function payment_status_enum_matches_model_constants(): void
    {
        // پیشوند PAYMENT_ روی مدل بین «مقدار وضعیت» و «نام ستون» مشترک است
        // (PAYMENT_METHOD, PAYMENT_OF_OWNER, …)، پس اینجا لیست صریح لازم است.
        $modelValues = [
            Order::PAYMENT_PENDING,
            Order::PAYMENT_PAID,
            Order::PAYMENT_FAILED,
            Order::PAYMENT_REFUND_PENDING,
            Order::PAYMENT_REFUNDED,
        ];

        $this->assertEqualsCanonicalizing(
            $modelValues,
            array_column(PaymentStatus::cases(), 'value'),
            'PaymentStatus enum و ثابت‌های Order::PAYMENT_* واگرا شده‌اند.'
        );
    }

    #[Test]
    public function wallet_transaction_status_enum_matches_model_constants(): void
    {
        $this->assertEnumMatchesConstants(WalletTransactionStatus::class, WalletTransaction::class, 'STATUS_');
    }

    #[Test]
    public function wallet_transaction_type_enum_matches_model_constants(): void
    {
        $this->assertEnumMatchesConstants(WalletTransactionType::class, WalletTransaction::class, 'TYPE_');
    }

    /**
     * مقادیر Enum باید دقیقاً برابر مجموعهٔ ثابت‌های رشته‌ایِ مدل با پیشوند داده‌شده باشد.
     */
    private function assertEnumMatchesConstants(string $enumClass, string $modelClass, string $prefix): void
    {
        $constants = (new ReflectionClass($modelClass))->getConstants();

        $modelValues = [];
        foreach ($constants as $name => $value) {
            if (str_starts_with($name, $prefix) && is_string($value)) {
                $modelValues[] = $value;
            }
        }

        $this->assertNotEmpty($modelValues, "هیچ ثابتی با پیشوند {$prefix} روی {$modelClass} پیدا نشد.");

        $this->assertEqualsCanonicalizing(
            $modelValues,
            array_column($enumClass::cases(), 'value'),
            "{$enumClass} با ثابت‌های {$prefix}* مدل {$modelClass} واگرا شده است."
        );
    }
}
