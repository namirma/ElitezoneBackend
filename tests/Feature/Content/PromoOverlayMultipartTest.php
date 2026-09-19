<?php

namespace Tests\Feature\Content;

use App\Models\PromoOverlay;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * مسیرِ multipart — همان چیزی که وقتی ادمین تصویر انتخاب می‌کند اجرا می‌شود.
 *
 * این حالت جداست چون فرانت در آن `FormData` می‌فرستد و فیلدهای ساختاردار
 * (buttons/audience/routes) با نشانه‌گذاریِ براکتی می‌آیند، نه JSON. اگر
 * `toFormData` تودرتوها را درست سریالایز نکند، همین‌جا لو می‌رود.
 */
class PromoOverlayMultipartTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin']);
        $admin = User::factory()->create();
        $admin->addRole('admin');
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);
    }

    #[Test]
    public function an_image_upload_keeps_the_structured_fields_intact(): void
    {
        Storage::fake('public');

        // شکلِ براکتی — دقیقاً همان چیزی که toFormData تولید می‌کند
        $payload = [
            'title' => 'با تصویر',
            'image' => UploadedFile::fake()->image('overlay.png', 619, 368),
            'buttons' => [
                ['label' => 'تعیین نقش', 'style' => 'primary', 'action' => 'navigate', 'value' => '/role'],
            ],
            'audience' => [
                'op' => 'and',
                'rules' => [['type' => 'auth_state', 'value' => 'guest']],
            ],
            'routes' => ['include' => ['/rebar*'], 'exclude' => []],
            'delay_seconds' => 15,
            'frequency' => 'day',
            'is_active' => '1',
        ];

        $res = $this->post('/api/admin/promo-overlays', $payload);
        $res->assertCreated();

        $overlay = PromoOverlay::findOrFail($res->json('data.id'));

        $this->assertNotNull($overlay->image, 'image stored');
        Storage::disk('public')->assertExists($overlay->image);

        // ساختاردارها نباید به رشته‌ی «[object Object]» تبدیل شده باشند
        $this->assertIsArray($overlay->buttons);
        $this->assertSame('/role', $overlay->buttons[0]['value']);
        $this->assertSame('guest', $overlay->audience['rules'][0]['value']);
        $this->assertSame(['/rebar*'], $overlay->routes['include']);
        $this->assertSame(15, $overlay->delay_seconds);
    }

    #[Test]
    public function replacing_the_image_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $id = $this->post('/api/admin/promo-overlays', [
            'title' => 'اولی',
            'image' => UploadedFile::fake()->image('first.png'),
        ])->json('data.id');

        $first = PromoOverlay::find($id)->image;

        // به‌روزرسانیِ multipart از POST رد می‌شود (PHP روی PUT خام آپلود نمی‌خواند)
        $this->post("/api/admin/promo-overlays/{$id}", [
            'title' => 'دومی',
            'image' => UploadedFile::fake()->image('second.png'),
        ])->assertOk();

        $second = PromoOverlay::find($id)->image;

        $this->assertNotSame($first, $second, 'a new file was stored');
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function remove_image_clears_the_column_and_deletes_the_file(): void
    {
        Storage::fake('public');

        $id = $this->post('/api/admin/promo-overlays', [
            'title' => 'با تصویر',
            'image' => UploadedFile::fake()->image('pic.png'),
        ])->json('data.id');

        $stored = PromoOverlay::find($id)->image;
        Storage::disk('public')->assertExists($stored);

        // «حذف تصویر» در پنل: بدونِ فرستادنِ فایلِ تازه، فقط این پرچم می‌رود.
        $this->post("/api/admin/promo-overlays/{$id}", [
            'title' => 'بدون تصویر',
            'remove_image' => 1,
        ])->assertOk();

        $this->assertNull(
            PromoOverlay::find($id)->image,
            'ستون باید null شود — وگرنه سایت به فایلی که دیگر نیست لینک می‌دهد'
        );
        Storage::disk('public')->assertMissing($stored);
    }

    #[Test]
    public function remove_image_is_ignored_when_a_new_file_is_also_sent(): void
    {
        Storage::fake('public');

        $id = $this->post('/api/admin/promo-overlays', [
            'title' => 'اولی',
            'image' => UploadedFile::fake()->image('first.png'),
        ])->json('data.id');

        // اگر ادمین «حذف» بزند و بعد فایلِ تازه انتخاب کند، نیت آخر «تعویض»
        // است نه «حذف». آپلود باید برنده شود، وگرنه تصویرِ تازه بی‌صدا دور
        // ریخته می‌شد و ادمین فکر می‌کرد ذخیره نشده.
        $this->post("/api/admin/promo-overlays/{$id}", [
            'title' => 'دومی',
            'remove_image' => 1,
            'image' => UploadedFile::fake()->image('second.png'),
        ])->assertOk();

        $now = PromoOverlay::find($id)->image;

        $this->assertNotNull($now, 'فایلِ تازه باید ذخیره شده باشد، نه حذف');
        Storage::disk('public')->assertExists($now);
    }

    #[Test]
    public function an_svg_image_is_rejected(): void
    {
        Storage::fake('public');

        // قاعده‌ی سراسریِ ریپو: هیچ آپلودِ دیسکِ public نباید svg بپذیرد
        // (SVGِ حاوی <script> از همان origin سرو می‌شود = XSSِ ذخیره‌شده)
        $this->post('/api/admin/promo-overlays', [
            'title' => 'با svg',
            'image' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }
}
