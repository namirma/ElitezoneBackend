<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ستون‌هایی که «قابلِ NULL بودن»شان قبلاً باگ ساخته.
 *
 * چرا این تست وجود دارد: اسکیمای دیتابیسِ توسعه با مایگریشن‌ها واگرا شده بود —
 * `users.type_id` آنجا nullable بود در حالی که
 * `2014_10_12_000000_create_users_table` آن را `foreignId()` یعنی NOT NULL تعریف
 * می‌کند. این واگرایی یک کلاسِ کاملِ باگ را نامرئی می‌کرد: کدی که کاربر را دستی
 * می‌سازد و `type_id` نمی‌دهد روی آن ماشین کار می‌کرد و روی سرورِ تازه
 * «Field 'type_id' doesn't have a default value» می‌داد.
 *
 * `PlatformWalletUserSeeder` دقیقاً همین‌طور شکست — و چون اولین ورودیِ
 * `DatabaseSeeder` است، کلِ `db:seed` را روی هر دیتابیسِ تازه می‌خواباند، در حالی
 * که روی ماشینِ توسعه سبز بود.
 *
 * این تست دیگر اجازه نمی‌دهد چنین واگرایی بی‌صدا بماند: روی هر ماشینی که سوییت
 * اجرا شود، ناهم‌خوانی همان‌جا قرمز می‌شود.
 */
class SchemaMatchesMigrationsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function columnExpectations(): array
    {
        return [
            // ستون، جدول، آیا باید nullable باشد؟

            // عمداً nullable (مهاجرتِ 2026_08_08_100100): NULL یعنی «هنوز نقشی
            // انتخاب نکرده». قبلاً NOT NULL بود و ثبت‌نام یک UserTypeِ ساختگی
            // («مشتری») می‌نشاند تا ستون را پر کند — که «بی‌نقش» و «نقشش مشتری
            // است» را از هم غیرقابلِ تفکیک می‌کرد. حالا هر سه نقطه‌ای که کاربر
            // می‌سازند (register، PlatformWalletUserSeeder، UserFactory) صراحتاً
            // NULL می‌نویسند، پس رفتار روی هر محیطی یکسان است.
            'users.type_id is nullable' => ['users', 'type_id', true],

            // این یکی عمداً nullable شد: حسابِ داخلی (ادمینِ پنل، کیف‌پولِ پلتفرم)
            // واقعاً کد ملی ندارد، و چون ایندکس UNIQUE دارد، `''` باعث
            // «Duplicate entry» روی ردیفِ دوم می‌شد.
            'user_profiles.national_code is nullable' => ['user_profiles', 'national_code', true],

            // اسنپ‌شاتِ مالی؛ تا پیش از پر شدن باید بتواند خالی بماند.
            'orders.official_price is nullable' => ['orders', 'official_price', true],
        ];
    }

    #[Test]
    #[DataProvider('columnExpectations')]
    public function a_columns_nullability_matches_what_the_code_assumes(string $table, string $column, bool $shouldBeNullable): void
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotNull($row, "ستون {$table}.{$column} وجود ندارد.");

        $isNullable = strtoupper((string) $row->IS_NULLABLE) === 'YES';

        $this->assertSame(
            $shouldBeNullable,
            $isNullable,
            sprintf(
                '%s.%s باید %s باشد ولی %s است — اسکیمای این دیتابیس با مایگریشن‌ها نمی‌خواند.',
                $table,
                $column,
                $shouldBeNullable ? 'nullable' : 'NOT NULL',
                $isNullable ? 'nullable' : 'NOT NULL',
            )
        );
    }
}
