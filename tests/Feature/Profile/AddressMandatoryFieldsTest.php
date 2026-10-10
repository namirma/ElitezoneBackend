<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Address;
use App\Models\Province;
use App\Models\ProvinceCity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تصمیمِ علی (۱۴۰۵/۰۷/۱۷): پلاک و کد پستیِ ۱۰ رقمی اجباری‌اند — هم در فرم و هم در
 * قاعده‌ی سمتِ سرور. ویرایشِ جزئیِ آدرسِ کامل آزاد است، ولی آدرسِ قدیمیِ بدونِ آن‌ها
 * با هیچ ویرایشی ناقص ذخیره نمی‌شود.
 */
class AddressMandatoryFieldsTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function payload(array $overrides = []): array
    {
        $province = Province::query()->first() ?? Province::create(['Name' => 'اصفهان']);
        $city = ProvinceCity::query()->where('ProvinceID', $province->id)->first()
            ?? ProvinceCity::create(['ProvinceID' => $province->id, 'Name' => 'اصفهان']);

        return array_merge([
            Address::PROVINCE_ID => $province->id,
            Address::CITY_ID => $city->id,
            Address::POSTAL_ADDRESS => 'خیابان چهارباغ، کوچه ۱۲',
            Address::BUILDING_NUMBER => '۱۲',
            Address::POSTAL_CODE => '8134567890',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ], $overrides);
    }

    private function address(array $attributes = []): Address
    {
        return Address::create(array_merge([
            Address::USER_ID => $this->user->id,
            Address::POSTAL_ADDRESS => 'نشانی قدیمی',
            Address::RECEIVER_NAME => 'گیرنده',
            Address::RECEIVER_MOBILE => '09120000000',
        ], $attributes));
    }

    #[Test]
    public function creating_an_address_requires_the_building_number_and_the_postal_code(): void
    {
        $this->postJson('/api/profile/addresses', $this->payload([
            Address::BUILDING_NUMBER => null,
            Address::POSTAL_CODE => null,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.building_number.0', '.وارد کردن پلاک الزامی است')
            ->assertJsonPath('errors.postal_code.0', '.وارد کردن کد پستی الزامی است');

        $this->postJson('/api/profile/addresses', $this->payload([Address::POSTAL_CODE => '12345']))
            ->assertStatus(422)
            ->assertJsonPath('errors.postal_code.0', '.کد پستی باید 10 رقمی باشد');

        $this->postJson('/api/profile/addresses', $this->payload())->assertCreated();
    }

    #[Test]
    public function an_old_address_without_them_cannot_be_saved_incomplete_again(): void
    {
        $old = $this->address();

        $this->putJson("/api/profile/addresses/{$old->id}", [Address::RECEIVER_NAME => 'نام تازه'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([Address::BUILDING_NUMBER, Address::POSTAL_CODE]);

        $this->putJson("/api/profile/addresses/{$old->id}", [
            Address::BUILDING_NUMBER => '4',
            Address::POSTAL_CODE => '8134567890',
        ])->assertOk();
    }

    #[Test]
    public function a_complete_address_still_takes_a_partial_edit_but_never_loses_them(): void
    {
        $address = $this->address([Address::BUILDING_NUMBER => '4', Address::POSTAL_CODE => '8134567890']);

        $this->putJson("/api/profile/addresses/{$address->id}", [Address::RECEIVER_NAME => 'نام تازه'])->assertOk();

        $this->putJson("/api/profile/addresses/{$address->id}", [Address::POSTAL_CODE => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors([Address::POSTAL_CODE]);
    }
}
