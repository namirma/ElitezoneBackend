<?php

namespace Tests\Feature\Admin;

use App\Events\Order\OrderPaid;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\User;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * پنلِ پیامک‌ها: فهرستِ کامل، ویرایشِ متن که واقعاً به رندر می‌رسد، حفاظتِ کدِ ورود،
 * قالبِ سفارشی روی رویداد، ارسالِ آزمایشی با نتیجه‌ی صادقانه، و گزارشِ ارسال.
 */
class AdminSmsTemplateTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        (new SmsTemplateSeeder)->run();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $this->grantAllAdminPermissions($user);
    }

    private function template(string $key): SmsTemplate
    {
        return SmsTemplate::query()->where(SmsTemplate::KEY, $key)->firstOrFail();
    }

    #[Test]
    public function guests_and_non_admins_are_kept_out(): void
    {
        $this->getJson('/api/admin/sms-templates')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/sms-templates')->assertForbidden();
    }

    #[Test]
    public function every_lang_key_is_listed_with_the_envelope_beside_data(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/sms-templates?per_page=100')->assertOk();

        $keys = collect($response->json('data'))->pluck('key');

        foreach (array_keys(trans('sms')) as $langKey) {
            $this->assertContains($langKey, $keys, "کلیدِ {$langKey} از فهرست جا مانده.");
        }

        $response->assertJsonPath('meta.total', $keys->count());
        $this->assertTrue($response->json('data.0.is_protected') !== null);
    }

    #[Test]
    public function a_key_added_to_lang_after_seeding_appears_on_the_next_listing(): void
    {
        Sanctum::actingAs($this->admin());
        $this->template('order_placed')->delete();

        $this->getJson('/api/admin/sms-templates?per_page=100')->assertOk();

        $this->assertDatabaseHas(SmsTemplate::TABLE, [SmsTemplate::KEY => 'order_placed']);
    }

    #[Test]
    public function editing_the_body_is_what_the_renderer_returns(): void
    {
        Sanctum::actingAs($this->admin());
        $row = $this->template('order_placed');

        $this->putJson("/api/admin/sms-templates/{$row->id}", [
            'body' => 'متنِ ویرایش‌شده :order_number',
            'is_active' => true,
            'conditions' => ['roles' => [], 'min_amount' => '', 'quiet_hours' => null],
        ])->assertOk()->assertJsonPath('data.body', 'متنِ ویرایش‌شده :order_number')->assertJsonPath('data.conditions', null);

        $this->assertSame('متنِ ویرایش‌شده EZ-7', SmsTemplate::render('order_placed', ['order_number' => 'EZ-7']));
    }

    #[Test]
    public function an_unknown_placeholder_in_the_body_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $row = $this->template('order_placed');

        $this->putJson("/api/admin/sms-templates/{$row->id}", ['body' => 'سفارش :order_numbr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    #[Test]
    public function a_protected_template_cannot_be_deactivated_or_conditioned(): void
    {
        Sanctum::actingAs($this->admin());
        $row = $this->template('loginMessage');
        $this->assertTrue($row->{SmsTemplate::IS_PROTECTED});

        $this->putJson("/api/admin/sms-templates/{$row->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['is_active']);

        $this->putJson("/api/admin/sms-templates/{$row->id}", ['conditions' => ['quiet_hours' => ['from' => '22:00', 'to' => '08:00']]])
            ->assertStatus(422)->assertJsonValidationErrors(['conditions']);

        $this->postJson("/api/admin/sms-templates/{$row->id}/toggle")->assertStatus(422);

        $this->assertTrue($row->refresh()->{SmsTemplate::IS_ACTIVE});
    }

    #[Test]
    public function toggle_flips_an_ordinary_template_and_delete_refuses_a_builtin(): void
    {
        Sanctum::actingAs($this->admin());
        $row = $this->template('cart_expiring');

        $this->postJson("/api/admin/sms-templates/{$row->id}/toggle")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertNull(SmsTemplate::render('cart_expiring', ['minutes' => 5]));

        $this->deleteJson("/api/admin/sms-templates/{$row->id}")->assertStatus(422);
        $this->assertDatabaseHas(SmsTemplate::TABLE, [SmsTemplate::ID => $row->id]);
    }

    #[Test]
    public function conditions_are_validated_not_stored_blindly(): void
    {
        Sanctum::actingAs($this->admin());
        $row = $this->template('cart_expiring');

        $this->putJson("/api/admin/sms-templates/{$row->id}", ['conditions' => ['roles' => ['ceo']]])
            ->assertStatus(422);
        $this->putJson("/api/admin/sms-templates/{$row->id}", ['conditions' => ['min_amount' => -5]])
            ->assertStatus(422);
        $this->putJson("/api/admin/sms-templates/{$row->id}", ['conditions' => ['quiet_hours' => ['from' => '25:99', 'to' => '08:00']]])
            ->assertStatus(422);
        $this->putJson("/api/admin/sms-templates/{$row->id}", ['conditions' => ['quiet_hours' => ['from' => '08:00', 'to' => '08:00']]])
            ->assertStatus(422);
        $this->putJson("/api/admin/sms-templates/{$row->id}", ['conditions' => ['spam' => true]])
            ->assertStatus(422);

        $this->putJson("/api/admin/sms-templates/{$row->id}", [
            'conditions' => ['roles' => ['employer', 'engineer'], 'min_amount' => 500000, 'quiet_hours' => ['from' => '22:00', 'to' => '08:00']],
        ])->assertOk()->assertJsonPath('data.conditions.roles', ['employer', 'engineer'])
            ->assertJsonPath('data.conditions.min_amount', 500000)
            ->assertJsonPath('data.conditions.quiet_hours.from', '22:00');
    }

    #[Test]
    public function the_events_endpoint_describes_what_a_custom_template_may_bind_to(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/sms-templates/events')->assertOk();

        $paid = collect($response->json('data'))->firstWhere('event', OrderPaid::class);
        $this->assertNotNull($paid);
        $this->assertContains('order_number', $paid['placeholders']);
        $this->assertContains('supplier', array_column($paid['audiences'], 'value'));
    }

    #[Test]
    public function a_custom_template_is_created_on_a_catalogued_event_only(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/sms-templates', [
            'title' => 'x', 'event' => 'App\\Events\\Nope', 'audience' => 'buyer', 'body' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['event']);

        $this->postJson('/api/admin/sms-templates', [
            'title' => 'x', 'event' => OrderPaid::class, 'audience' => 'driver', 'body' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['audience']);

        $this->postJson('/api/admin/sms-templates', [
            'title' => 'x', 'event' => OrderPaid::class, 'audience' => 'supplier', 'body' => 'سلام :code',
        ])->assertStatus(422)->assertJsonValidationErrors(['body']);

        $response = $this->postJson('/api/admin/sms-templates', [
            'title' => 'خبرِ پرداخت به تأمین‌کننده',
            'event' => OrderPaid::class,
            'audience' => 'supplier',
            'body' => 'سفارش :order_number پرداخت شد.',
        ])->assertCreated();

        $key = $response->json('data.key');
        $this->assertStringStartsWith('custom_', $key);
        $response->assertJsonPath('data.is_custom', true)->assertJsonPath('data.event_label', 'پرداخت سفارش');

        // سفارشی حذف می‌شود
        $this->deleteJson("/api/admin/sms-templates/{$response->json('data.id')}")->assertOk();
        $this->assertDatabaseMissing(SmsTemplate::TABLE, [SmsTemplate::KEY => $key]);
    }

    #[Test]
    public function a_test_send_reports_the_honest_outcome_and_lands_in_the_log(): void
    {
        Sanctum::actingAs($this->admin());
        $row = $this->template('order_placed');

        $this->postJson("/api/admin/sms-templates/{$row->id}/test", ['mobile' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors(['mobile']);

        // محیطِ تست: TSMS پیکربندی نشده → نتیجه‌ی صادقانه «unconfigured»، نه «ارسال شد».
        $this->postJson("/api/admin/sms-templates/{$row->id}/test", ['mobile' => '+989120000030'])
            ->assertOk()
            ->assertJsonPath('data.status', SmsLog::STATUS_UNCONFIGURED)
            ->assertJsonPath('data.mobile', '09120000030')
            ->assertJsonPath('data.body', __('sms.order_placed', ['order_number' => '«order_number»']));

        $this->assertDatabaseHas(SmsLog::TABLE, ['mobile' => '09120000030', 'template_key' => 'order_placed', 'status' => SmsLog::STATUS_UNCONFIGURED]);

        // با درگاهِ پیکربندی‌شده و شماره‌ی خارج از allowlist → skipped_allowlist
        config()->set('services.tsms.link', 'https://sms.test/send');
        config()->set('services.tsms.number', '3000');
        config()->set('services.tsms.dev_allowlist', ['09130000001']);
        Http::fake(['https://sms.test/*' => Http::response('ok', 200)]);

        $this->postJson("/api/admin/sms-templates/{$row->id}/test", ['mobile' => '09120000031', 'params' => ['order_number' => 'EZ-9']])
            ->assertOk()->assertJsonPath('data.status', SmsLog::STATUS_SKIPPED_ALLOWLIST)
            ->assertJsonPath('data.body', __('sms.order_placed', ['order_number' => 'EZ-9']));
        Http::assertNothingSent();

        // و شماره‌ی داخلِ allowlist → sent
        $this->postJson("/api/admin/sms-templates/{$row->id}/test", ['mobile' => '09130000001'])
            ->assertOk()->assertJsonPath('data.status', SmsLog::STATUS_SENT);
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_log_endpoint_filters_and_paginates(): void
    {
        Sanctum::actingAs($this->admin());

        SmsLog::record('09120000040', 'order_placed', 'a', SmsLog::STATUS_SENT);
        SmsLog::record('09120000041', 'loginMessage', 'b', SmsLog::STATUS_SKIPPED_ALLOWLIST);

        $this->getJson('/api/admin/sms-logs?status=nonsense')->assertStatus(422);

        $this->getJson('/api/admin/sms-logs?key=loginMessage&status='.SmsLog::STATUS_SKIPPED_ALLOWLIST)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.mobile', '09120000041');

        $this->getJson('/api/admin/sms-logs?mobile=0912000004')
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/admin/sms-logs?from=2099-01-01')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }
}
