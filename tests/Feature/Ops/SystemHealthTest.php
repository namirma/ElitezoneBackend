<?php

namespace Tests\Feature\Ops;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ops\SystemHealthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * صفحهٔ سلامتِ سامانه.
 *
 * مهم‌ترین خاصیتی که باید پین شود این است که صفحه در حضورِ مشکل **سبز نشود**؛
 * یک داشبوردِ سلامت که مشکل را قورت بدهد از نبودنش بدتر است، چون به آن اعتماد
 * می‌شود. پس هر بخش جداگانه تست می‌شود که مشکلش را به `healthy` سراسری برساند.
 */
class SystemHealthTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private SystemHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health = app(SystemHealthService::class);
    }

    #[Test]
    public function the_report_always_carries_all_four_sections(): void
    {
        $report = $this->health->report();

        $this->assertSame(
            ['queue', 'backup', 'ledger', 'orders'],
            array_keys($report['sections']),
        );
        $this->assertArrayHasKey('healthy', $report);
        $this->assertArrayHasKey('checked_at', $report);
    }

    #[Test]
    public function a_ledger_drift_makes_the_whole_report_unhealthy(): void
    {
        // موجودیِ بدونِ پشتوانهٔ دفتر — همان الگویی که در پروداکشن یعنی پولِ خلق‌شده.
        Wallet::factory()->create([Wallet::BALANCE => 250_000_000]);

        $report = $this->health->report();

        $this->assertFalse($report['sections']['ledger']['healthy']);
        $this->assertGreaterThan(0, $report['sections']['ledger']['drifted_wallets']);
        $this->assertFalse($report['healthy'], 'یک مغایرتِ مالی باید کلِ گزارش را ناسالم کند.');
    }

    #[Test]
    public function a_stuck_order_is_reported_with_its_idle_time(): void
    {
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_PREPARING]);

        // مستقیم روی دیتابیس، چون هر save روی مدل updated_at را دوباره تازه می‌کند
        // و سفارش هرگز «کهنه» نمی‌شد.
        DB::table('orders')->where(Order::ID, $order->id)->update([
            Order::UPDATED_AT => now()->subDays(10),
        ]);

        $section = $this->health->report()['sections']['orders'];

        $this->assertGreaterThan(0, $section['count']);
        $this->assertFalse($section['healthy']);

        $row = collect($section['sample'])->firstWhere('id', $order->id);
        $this->assertNotNull($row, 'سفارشِ گیرکرده باید در نمونه بیاید.');
        $this->assertGreaterThanOrEqual(240, $row['idle_hours']);
    }

    #[Test]
    public function a_recently_touched_order_is_not_called_stuck(): void
    {
        Order::factory()->create([Order::STATUS => Order::STATUS_PREPARING]);

        $section = $this->health->report()['sections']['orders'];

        $this->assertSame(0, $section['count']);
        $this->assertTrue($section['healthy']);
    }

    #[Test]
    public function a_terminal_order_is_never_counted_as_stuck(): void
    {
        // ماندن در وضعیتِ نهایی طبیعی است؛ شمردنش فقط صفحه را برای همیشه قرمز می‌کرد.
        $order = Order::factory()->create([Order::STATUS => Order::STATUS_COMPLETED]);

        DB::table('orders')->where(Order::ID, $order->id)->update([
            Order::UPDATED_AT => now()->subYear(),
        ]);

        $this->assertSame(0, $this->health->report()['sections']['orders']['count']);
    }

    #[Test]
    public function stuck_orders_are_broken_down_by_status(): void
    {
        // تفکیک مهم‌تر از عددِ کل است: انباشت در یک وضعیتِ خاص یعنی یک مرحله
        // از فرآیند متوقف شده، نه اینکه همه‌چیز کند است.
        foreach ([Order::STATUS_PREPARING, Order::STATUS_PREPARING, Order::STATUS_SHIPPED] as $status) {
            $order = Order::factory()->create([Order::STATUS => $status]);
            DB::table('orders')->where(Order::ID, $order->id)->update([
                Order::UPDATED_AT => now()->subDays(5),
            ]);
        }

        $byStatus = $this->health->report()['sections']['orders']['by_status'];

        $this->assertSame(2, $byStatus[Order::STATUS_PREPARING]);
        $this->assertSame(1, $byStatus[Order::STATUS_SHIPPED]);
    }

    #[Test]
    public function the_ledger_section_is_cached_and_says_when_it_was_computed(): void
    {
        config(['ops.health.ledger_cache_minutes' => 10]);

        $first = $this->health->report()['sections']['ledger'];
        $this->assertArrayHasKey('computed_at', $first);

        // مغایرتِ تازه بلافاصله دیده نمی‌شود — این عمدی است و به همین دلیل
        // `computed_at` در پاسخ هست: کهنگی باید معلوم باشد، نه پنهان.
        Wallet::factory()->create([Wallet::BALANCE => 999_000_000]);

        $second = $this->health->report()['sections']['ledger'];
        $this->assertSame($first['drifted_wallets'], $second['drifted_wallets']);
    }

    #[Test]
    public function disabling_the_cache_makes_the_ledger_section_read_live(): void
    {
        // برای وقتی که کسی وسطِ بررسیِ یک مغایرت است و کهنگی آزاردهنده می‌شود.
        config(['ops.health.ledger_cache_minutes' => 0]);

        $before = $this->health->report()['sections']['ledger']['drifted_wallets'];

        Wallet::factory()->create([Wallet::BALANCE => 999_000_000]);

        $after = $this->health->report()['sections']['ledger']['drifted_wallets'];

        $this->assertSame($before + 1, $after);
    }

    #[Test]
    public function a_missing_backup_is_reported_rather_than_throwing(): void
    {
        // دیسکی که اصلاً وجود ندارد نباید کلِ صفحه را بیندازد — خودِ همین وضعیت
        // یک یافته است، نه یک خطای برنامه.
        config(['backup.backup.destination.disks' => ['a-disk-that-does-not-exist']]);

        $section = $this->health->report()['sections']['backup'];

        $this->assertFalse($section['healthy']);
        $this->assertNotSame([], $section['problems']);
        $this->assertNull($section['latest']);
    }

    #[Test]
    public function the_queue_section_reuses_the_same_thresholds_as_the_command(): void
    {
        // اگر آستانه‌ها دو نسخه شوند، پنل و ترمینال دو حرفِ متفاوت می‌زنند.
        config(['ops.queue.max_failed_jobs' => 0, 'queue.default' => 'database']);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now(),
        ]);

        $section = $this->health->report()['sections']['queue'];

        $this->assertFalse($section['healthy']);
        $this->assertGreaterThan(0, $section['stats']['failed_total']);
    }

    #[Test]
    public function the_admin_endpoint_returns_the_report(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/system-health')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'healthy',
                    'checked_at',
                    'problems',
                    'sections' => ['queue', 'backup', 'ledger', 'orders'],
                ],
            ]);
    }

    #[Test]
    public function the_endpoint_is_closed_to_an_admin_without_the_financial_permission(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'description' => 'Administrator',
        ]);

        // ادمین، ولی بدونِ manage-financial — شمارِ مغایرت‌های مالی نباید نشتی کند.
        $admin = User::factory()->create();
        $admin->addRole($role);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/system-health')->assertForbidden();
    }

    private function makeAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'description' => 'Administrator',
        ]);

        $admin = User::factory()->create();
        $admin->addRole($role);
        $this->grantAllAdminPermissions($admin);

        return $admin;
    }
}
