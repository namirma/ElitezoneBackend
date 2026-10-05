<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * پین‌کردن رفتار AdminSupplierController::updateFormula پس از حذف
 * TRANS_COMMISSION/TRANS_VAT از $fillable روی SupplierProfile — بروزرسانی
 * باید از طریق forceFill انجام شود، وگرنه mass-assignment guard مقادیر را
 * silently drop می‌کند.
 */
class AdminSupplierControllerTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private User $admin;

    private User $supplier;

    private SupplierProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'description' => 'Administrator',
        ]);
        $this->admin = User::factory()->create();
        $this->admin->addRole($adminRole);
        $this->grantAllAdminPermissions($this->admin);

        $supplierRole = Role::firstOrCreate(['name' => 'supplier'], [
            'display_name' => 'Supplier',
            'description' => 'Supplier',
        ]);
        $this->supplier = User::factory()->create();
        $this->supplier->addRole($supplierRole);

        // fresh() re-reads the row so trans_commission/trans_vat reflect the DB
        // column defaults — create() does not backfill them into the in-memory model.
        $this->profile = SupplierProfile::create([
            SupplierProfile::USER_ID => $this->supplier->id,
            SupplierProfile::COMPANY => 'Test Rebar Co',
        ])->fresh();
    }

    public function test_trans_commission_and_trans_vat_are_not_mass_assignable(): void
    {
        $fillable = (new SupplierProfile)->getFillable();

        $this->assertNotContains(SupplierProfile::TRANS_COMMISSION, $fillable);
        $this->assertNotContains(SupplierProfile::TRANS_VAT, $fillable);
    }

    public function test_default_formula_flags_are_true(): void
    {
        $this->assertTrue((bool) $this->profile->{SupplierProfile::TRANS_COMMISSION});
        $this->assertTrue((bool) $this->profile->{SupplierProfile::TRANS_VAT});
    }

    public function test_admin_can_update_the_formula_flags(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/suppliers/{$this->supplier->id}/formula", [
            'trans_commission' => false,
            'trans_vat' => false,
        ])->assertOk();

        $fresh = $this->profile->fresh();

        $this->assertFalse((bool) $fresh->{SupplierProfile::TRANS_COMMISSION});
        $this->assertFalse((bool) $fresh->{SupplierProfile::TRANS_VAT});
    }

    /** پرسشِ ۴ مدیریت (۱۴۰۵/۰۷/۱۳): درصدِ کارمزد به ازای قراردادِ هر تأمین‌کننده؛ خالی = سراسری. */
    public function test_admin_can_set_and_clear_the_contract_commission_percent(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/suppliers/{$this->supplier->id}/formula", [
            'trans_commission' => true,
            'trans_vat' => true,
            'commission_percent' => 2.5,
        ])->assertOk()
            ->assertJsonPath('data.commission_percent', 2.5)
            ->assertJsonPath('data.effective_commission_percent', 2.5);

        $this->assertEqualsWithDelta(2.5, (float) $this->profile->fresh()->{SupplierProfile::COMMISSION_PERCENT}, 0.001);

        // خالی ⇒ برمی‌گردد به مقدارِ سراسری (پیش‌فرضِ ۱٪)، نه صفر.
        $this->putJson("/api/admin/suppliers/{$this->supplier->id}/formula", [
            'trans_commission' => true,
            'trans_vat' => true,
            'commission_percent' => null,
        ])->assertOk()
            ->assertJsonPath('data.commission_percent', null);

        $this->assertNull($this->profile->fresh()->{SupplierProfile::COMMISSION_PERCENT});

        // خارج از بازه‌ی tc_percentage (۰ تا ۲۰) پذیرفته نمی‌شود.
        $this->putJson("/api/admin/suppliers/{$this->supplier->id}/formula", [
            'trans_commission' => true,
            'trans_vat' => true,
            'commission_percent' => 25,
        ])->assertStatus(422)->assertJsonValidationErrors(['commission_percent']);
    }

    public function test_updating_the_formula_flags_back_to_true_persists(): void
    {
        $this->profile->forceFill([
            SupplierProfile::TRANS_COMMISSION => false,
            SupplierProfile::TRANS_VAT => false,
        ])->save();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/suppliers/{$this->supplier->id}/formula", [
            'trans_commission' => true,
            'trans_vat' => true,
        ])->assertOk();

        $fresh = $this->profile->fresh();

        $this->assertTrue((bool) $fresh->{SupplierProfile::TRANS_COMMISSION});
        $this->assertTrue((bool) $fresh->{SupplierProfile::TRANS_VAT});
    }
}
