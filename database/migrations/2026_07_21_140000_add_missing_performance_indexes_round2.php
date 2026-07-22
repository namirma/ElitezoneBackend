<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ایندکس‌های گمشده روی مسیرهای داغِ مالی/حمل (یافته‌های CC-20/CC-21):
 *  - wallet_transactions(wallet_id, status): پرترددترین خواندنِ تاریخچهٔ کیف‌پولِ کاربر
 *    که با type/status فیلتر می‌شود؛ فقط ایندکس‌های تک‌ستونی داشت.
 *  - order_items.factory_id / factory_location_id: بدون ایندکس (کوئری‌های عملیات تأمین).
 *  - shipments.factory_id / destination_province_id / destination_city_id: بدون ایندکس.
 *
 * دفاعی نوشته شده: هر ایندکس فقط اگر ستون وجود داشته باشد و ایندکس از قبل نباشد اضافه می‌شود،
 * پس اجرای مجدد یا تفاوتِ محیط، migrate را نمی‌شکند.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->apply('wallet_transactions', ['wallet_id', 'status'], 'wt_wallet_status_idx');
        $this->apply('order_items', ['factory_id'], 'order_items_factory_id_idx');
        $this->apply('order_items', ['factory_location_id'], 'order_items_factory_location_id_idx');
        $this->apply('shipments', ['factory_id'], 'shipments_factory_id_idx');
        $this->apply('shipments', ['destination_province_id'], 'shipments_dest_province_idx');
        $this->apply('shipments', ['destination_city_id'], 'shipments_dest_city_idx');
    }

    public function down(): void
    {
        $this->drop('wallet_transactions', 'wt_wallet_status_idx');
        $this->drop('order_items', 'order_items_factory_id_idx');
        $this->drop('order_items', 'order_items_factory_location_id_idx');
        $this->drop('shipments', 'shipments_factory_id_idx');
        $this->drop('shipments', 'shipments_dest_province_idx');
        $this->drop('shipments', 'shipments_dest_city_idx');
    }

    private function apply(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ($columns as $c) {
            if (! Schema::hasColumn($table, $c)) {
                return;
            }
        }
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function ($t) use ($columns, $name) {
            $t->index($columns, $name);
        });
    }

    private function drop(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }
        Schema::table($table, function ($t) use ($name) {
            $t->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }
};
