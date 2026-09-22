<?php

namespace Tests\Feature\Order;

use App\Http\Resources\Order\ShipmentResource;
use App\Models\EmployerEliteYAR;
use App\Models\EngineerProfile;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Carbon 3 (شاخه‌ی L13) از `diffIn*` عددِ اعشاری برمی‌گرداند؛ Carbon 2 عددِ صحیح.
 * دو جایی که آن عدد مستقیم به کاربر می‌رسید.
 */
class CarbonThreeIntegerDiffTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_retry_delay_after_a_rejected_invite_is_whole_hours(): void
    {
        // ستونِ timestamp ثانیه‌ای است؛ «حالا» کسرِ ثانیه دارد → 1.5001 ساعت → «22.4999».
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00.400000'));

        $employer = User::factory()->create();
        $employer->addRole(Role::firstOrCreate(['name' => 'employer'], ['display_name' => 'کارفرما']));

        // تأییدِ لانچ = عضویتِ سازه ۹۰ (پرچمِ مدرکیِ پایین بدونِ روشِ مدرکیِ روشن نمی‌شمارد).
        $eliteYar = User::factory()->create([User::IS_SAZE90_USER => true]);
        $eliteYar->addRole(Role::firstOrCreate(['name' => 'elite_yar'], ['display_name' => 'الیت یار']));
        EngineerProfile::create([
            EngineerProfile::USER_ID => $eliteYar->id,
            EngineerProfile::ENGINEER_MEMBER => false,
            EngineerProfile::STUDENT => false,
            EngineerProfile::IS_VERIFIED => true,
        ]);

        DB::table('employer_elite_yar')->insert([
            EmployerEliteYAR::EMPLOYER_ID => $employer->id,
            EmployerEliteYAR::ELITE_YAR_ID => $eliteYar->id,
            EmployerEliteYAR::STATUS => EmployerEliteYAR::STATUS_REJECTED,
            'created_at' => '2026-09-15 08:30:00',
            'updated_at' => '2026-09-15 08:30:00',
        ]);

        Sanctum::actingAs($employer);

        $this->postJson('/api/profile/elite-yars/invite', [User::MOBILE => $eliteYar->mobile])
            ->assertStatus(429)
            ->assertJsonPath('message', __('elite_yar.retry_delay', ['hours' => 23]));
    }

    #[Test]
    public function shipment_remaining_seconds_is_an_integer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00.400000'));

        $shipment = (new Shipment)->forceFill([
            Shipment::DELIVERY_DEADLINE => '2026-09-15 10:01:40',
        ]);

        $delivery = (new ShipmentResource($shipment))->resolve(request())['delivery'];

        $this->assertSame(99, $delivery['remaining_seconds']);
    }
}
