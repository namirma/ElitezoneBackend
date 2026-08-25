<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفترهای مالی نباید با حذفِ والدشان ناپدید شوند.
 *
 * ⚠️ **مسئله.** `wallet_transactions.wallet_id`، `debt_entries.user_id` و
 * `invoices.order_id/user_id` همگی `cascadeOnDelete` بودند، و هیچ‌کدام از
 * `User`/`Order`/`Wallet`/`WalletTransaction`/`DebtEntry`/`Invoice` هم
 * SoftDeletes ندارند. یعنی یک `$user->delete()` — از یک اسکریپت عملیاتی، یک
 * tinker، یا اندپوینتی که فردا نوشته شود — کلِ تاریخچه‌ی مالیِ آن شخص را پاک
 * می‌کرد: مانده، دفترِ append-only تراکنش‌ها، دفترِ بدهی و اسناد مالیاتی.
 *
 * ‏`wallet_transactions` و `debt_entries` صریحاً «append-only» طراحی شده‌اند تا
 * `financial:doctor` بتواند موجودی را از رویشان بازپخش کند؛ cascade دقیقاً همان
 * تضمین را نقض می‌کند.
 *
 * امروز هیچ مسیری کاربر را hard-delete نمی‌کند (`AdminManagerController::destroy`
 * فقط نقش ادمین را برمی‌دارد)، پس این پیشگیرانه است نه رفعِ باگِ زنده.
 *
 * **RESTRICT و نه SET NULL:** این ستون‌ها NOT NULL‌اند و رابطه‌شان معنادار است —
 * تراکنشِ بی‌کیف‌پول یا بدهیِ بی‌صاحب چیزی را نجات نمی‌دهد. با RESTRICT، حذف
 * **شکست می‌خورد** و کسی که آن را زده متوجه می‌شود؛ سیاستِ درست «anonymize، نه
 * delete» است.
 */
return new class extends Migration
{
    /**
     * @return array<int, array{table: string, column: string, references: string, on: string}>
     */
    private function targets(): array
    {
        return [
            ['table' => 'wallet_transactions', 'column' => 'wallet_id', 'references' => 'id', 'on' => 'wallets'],
            ['table' => 'debt_entries', 'column' => 'user_id', 'references' => 'id', 'on' => 'users'],
            ['table' => 'invoices', 'column' => 'order_id', 'references' => 'id', 'on' => 'orders'],
            ['table' => 'invoices', 'column' => 'user_id', 'references' => 'id', 'on' => 'users'],
        ];
    }

    public function up(): void
    {
        foreach ($this->targets() as $t) {
            if (! Schema::hasTable($t['table']) || ! Schema::hasColumn($t['table'], $t['column'])) {
                continue;
            }

            $this->dropForeignIfExists($t['table'], $t['column']);

            Schema::table($t['table'], function (Blueprint $table) use ($t) {
                $table->foreign($t['column'])
                    ->references($t['references'])
                    ->on($t['on'])
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->targets() as $t) {
            if (! Schema::hasTable($t['table']) || ! Schema::hasColumn($t['table'], $t['column'])) {
                continue;
            }

            $this->dropForeignIfExists($t['table'], $t['column']);

            Schema::table($t['table'], function (Blueprint $table) use ($t) {
                $table->foreign($t['column'])
                    ->references($t['references'])
                    ->on($t['on'])
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * نامِ کلید خارجی را از information_schema می‌خواند، نه از قرارداد نام‌گذاری.
     *
     * ‏`dropForeign(['col'])` نامِ پیش‌فرضِ لاراول را می‌سازد؛ اگر کلید با نامِ
     * دیگری ساخته شده باشد (یا قبلاً حذف شده باشد) مهاجرت با خطا می‌ایستد.
     */
    private function dropForeignIfExists(string $table, string $column): void
    {
        $names = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->unique();

        foreach ($names as $name) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
        }
    }
};
