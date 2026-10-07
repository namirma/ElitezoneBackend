<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\UserProfile;
use App\Sms\PlaceholderCatalog;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «استاندارد لحن و نگارش پیامک‌های Elite Zone» — مدیریت، دورِ ششم، پرسشِ ۷ (۱۴۰۵/۰۷/۱۵):
 * «به‌طورِ کلی برای متنِ همه‌ی پیامک‌ها در الیت‌زون از دستورالعملِ زیر استفاده کن.»
 * (docs/specs/SMS_STYLE_GUIDE.md). این تست روی **همه‌ی** قالب‌ها می‌گردد، نه نمونه — قالبِ تازه‌ای که
 * دستورالعمل را رعایت نکند همین‌جا قرمز می‌شود.
 */
class SmsStyleGuideTest extends TestCase
{
    use DatabaseTransactions;

    /** بندِ ۱۷ِ دستورالعمل + خطاب‌های بندِ ۳. */
    private const FORBIDDEN = [
        'کاربر گرامی', 'مشتری گرامی', 'سوپلایر گرامی', 'کارفرمای محترم', 'تأمین‌کننده محترم', 'الیت‌یار محترم',
        'جنابعالی', 'به استحضار', 'احتراما', 'بدین‌وسیله', 'خواهشمند است', 'مقتضی', 'سامانه مذکور',
        'با تشکر از حسن انتخاب', 'سلام و عرض ادب', 'عرض سلام',
    ];

    /** @return array<string, string> */
    private function templates(): array
    {
        $all = Lang::get('sms', [], 'fa');
        $this->assertIsArray($all);

        return array_filter($all, 'is_string');
    }

    #[Test]
    public function every_template_follows_the_wording_rules(): void
    {
        $problems = [];

        foreach ($this->templates() as $key => $body) {
            foreach (self::FORBIDDEN as $phrase) {
                if (str_contains($body, $phrase)) {
                    $problems[] = "{$key}: «{$phrase}»";
                }
            }

            // بندِ ۸: اصطلاحاتِ ثابت — «الیت‌زون» و «الیت‌یار» با نیم‌فاصله، نه انگلیسی یا با فاصله/خط‌تیره.
            foreach (['الیت زون', 'الیت-زون', 'EliteZone', 'Elite Zone', 'الیت یار', 'سوپلایر'] as $variant) {
                if (str_contains($body, $variant)) {
                    $problems[] = "{$key}: «{$variant}»";
                }
            }

            // بندِ ۱۶: پیامکِ عملیاتی به ایموجی وابسته نیست.
            if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $body)) {
                $problems[] = "{$key}: ایموجی";
            }

            // بندِ ۵: امضا «الیت‌زون» — جز کدهای یک‌بارمصرف که الگوی خودِ دستورالعمل (بندِ ۱۳) امضا ندارد.
            if (SmsTemplate::categoryFor((string) $key) !== SmsTemplate::CATEGORY_AUTH && ! str_ends_with(trim($body), 'الیت‌زون')) {
                $problems[] = "{$key}: امضای «الیت‌زون» در انتها نیست";
            }
        }

        $this->assertSame([], $problems, "پیامک‌هایی که با دستورالعملِ مدیریت نمی‌خوانند:\n".implode("\n", $problems));
    }

    #[Test]
    public function every_template_has_one_of_the_guide_categories(): void
    {
        foreach (array_keys($this->templates()) as $key) {
            $category = SmsTemplate::categoryFor((string) $key);
            $this->assertNotNull($category, "قالبِ «{$key}» دسته ندارد (بندِ ۱۸).");
            $this->assertArrayHasKey($category, SmsTemplate::CATEGORIES);
        }
    }

    #[Test]
    public function a_template_with_a_salutation_reads_cleanly_without_a_name(): void
    {
        foreach ($this->templates() as $key => $body) {
            if (! str_contains($body, ':first_name')) {
                continue;
            }

            $this->assertStringStartsWith(':first_name عزیز،', $body, "{$key}: خطاب باید اولِ پیام باشد (بندِ ۵).");

            $rendered = SmsTemplate::interpolate($body, ['first_name' => '']);
            $this->assertStringNotContainsString(':first_name', $rendered, $key);
            $this->assertStringNotContainsString('عزیز،', $rendered, $key);
            $this->assertSame(ltrim($rendered), $rendered, "{$key}: بی‌نام نباید با فاصله یا خطِ خالی شروع شود.");
        }
    }

    /**
     * نمونه‌مقدارهای `PlaceholderCatalog` همان چیزی است که پنل در «ارسالِ آزمایشی» و پیوستِ سندِ مدیریت می‌گذارد.
     * نمونه‌ی `tracking_line` یک‌بار خطِ تازه را اولش داشت و امضا را به کدِ پیگیری می‌چسباند («8231الیت‌زون»).
     */
    #[Test]
    public function every_template_rendered_with_the_panel_samples_ends_with_the_signature_on_its_own_line(): void
    {
        $catalog = SmsTemplateSeeder::catalog();

        foreach ($this->templates() as $key => $body) {
            if (SmsTemplate::categoryFor((string) $key) === SmsTemplate::CATEGORY_AUTH) {
                continue;
            }

            $params = [];
            foreach (PlaceholderCatalog::describe($catalog[$key][3] ?? [], (string) $key) as $d) {
                $params[$d['name']] = $d['example'];
            }

            $lines = explode("\n", trim(SmsTemplate::interpolate($body, $params)));
            $this->assertSame('الیت‌زون', trim((string) end($lines)), "«{$key}» با نمونه‌مقدارها امضا را در خطِ جدا ندارد.");
        }
    }

    #[Test]
    public function display_numbers_are_persian_and_codes_stay_latin(): void
    {
        $rendered = SmsTemplate::interpolate('مبلغ :amount ریال، کد :code، سفارش :order_number', [
            'amount' => '1,200,000', 'code' => '482913', 'order_number' => 'EZ-ORD-RB-140502-70300100',
        ]);

        $this->assertSame('مبلغ ۱,۲۰۰,۰۰۰ ریال، کد 482913، سفارش EZ-ORD-RB-140502-70300100', $rendered);
    }

    #[Test]
    public function the_salutation_uses_persian_letters_even_when_the_registry_name_is_arabic(): void
    {
        $user = User::factory()->create();
        UserProfile::updateOrCreate([UserProfile::USER_ID => $user->id], [UserProfile::NAME => 'سيدعلي كاظمي']);

        $this->assertSame('سیدعلی کاظمی', SmsTemplate::firstNameFor($user->mobile));
        $this->assertSame('', SmsTemplate::firstNameFor('09999999990'));
    }
}
