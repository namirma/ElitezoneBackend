<?php

namespace Tests\Feature\Content;

use App\Models\PageBlock;
use App\Services\Content\PageBlockSchema;
use Database\Seeders\PageBlocksSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سه بار همین نشست، محتوای سیدشده و اسکیمایی که قرار بود توصیفش کند بی‌صدا از
 * هم جدا افتادند — بدونِ اینکه هیچ تستی قرمز شود: ویدیوی هوم با کلیدهای مرده‌ی
 * `src`/`poster` (کامپوننت `videoSrc`/`posterSrc` می‌خواهد)، `SkQuality` زیرِ
 * نوعِ واقعیِ `SkGlance`، و `SkTechnology` زیرِ نوعِ واقعیِ `PdScale`. این تست
 * همان درفت را می‌بندد: سیدر را واقعاً اجرا می‌کند و هر ردیفِ نتیجه را — هم
 * سطحِ بالا، هم هر کارتِ داخلِ فهرست‌های آبجکتی — با تعریفی که خودِ
 * `PageBlockSchema` اعلام کرده مقایسه می‌کند.
 *
 * همه‌ی ردیف‌ها را کامل بررسی می‌کند و همه‌ی نقض‌ها را یک‌جا جمع می‌زند — نه
 * توقف روی اولین `assertArrayHasKey` — چون هدف یک گزارشِ کاملِ درفت است، نه
 * فقط اثباتِ اینکه دست‌کم یک جای کار خراب است.
 */
class SeededBlocksMatchSchemaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * کلیدهایی که کامپوننتِ واقعی می‌خواند ولی اسکیما عمداً اعلامشان نکرده —
     * تصمیمی محصولی و از پیش مستند (`.superpowers/sdd/schema-audit-report.md`،
     * سؤالِ باز ۴: `SkProducts`ی `titleFeat`)، نه درفتی که این تسک باید ببندد.
     * بدونِ این استثنا، همین گاردِ تازه یک یافته‌ی از پیش‌شناخته و تأییدشده را
     * با باگِ واقعی قاطی می‌کرد و برای همیشه قرمز می‌ماند.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_UNDECLARED_SHAPE_KEYS = [
        'feature' => ['titleFeat'],
    ];

    #[Test]
    public function every_seeded_row_matches_the_type_it_claims(): void
    {
        $this->seed(PageBlocksSeeder::class);

        $schema = new PageBlockSchema;
        $violations = [];

        foreach (PageBlock::all() as $block) {
            $page = (string) $block->{PageBlock::PAGE};
            $order = (int) $block->{PageBlock::SORT_ORDER};
            $type = (string) $block->{PageBlock::TYPE};
            /** @var array<string, mixed> $data */
            $data = $block->{PageBlock::DATA} ?? [];

            $where = "صفحه‌ی «{$page}»، ترتیبِ {$order}، نوعِ «{$type}»";

            if (! $schema->has($type)) {
                $violations[] = "{$where} — این نوع در PageBlockSchema تعریف نشده.";

                continue;
            }

            $this->collectFieldViolations($schema->for($type)['fields'], $data, $where, $violations);
        }

        $this->assertSame([], $violations, "یافته‌ها:\n".implode("\n", $violations));
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    private function collectFieldViolations(array $fields, array $data, string $where, array &$violations): void
    {
        foreach (array_keys($data) as $key) {
            if (! array_key_exists($key, $fields)) {
                $violations[] = "{$where} — کلیدِ «{$key}» در data هست ولی این نوع اعلامش نکرده؛ فرمِ ادمین هرگز نشانش نمی‌دهد و ویرایشِ ادمین حذفش می‌کند.";
            }
        }

        foreach ($fields as $fieldName => $field) {
            if (($field['required'] ?? false) && ! array_key_exists($fieldName, $data)) {
                $violations[] = "{$where} — فیلدِ اجباریِ «{$fieldName}» در data نیست.";
            }

            $this->collectListFieldViolations($fieldName, $field, $data, $where, $violations);
        }
    }

    /**
     * وقتی فیلد یک فهرستِ آبجکتی است (مثلِ `cards`)، هر عضوش را هم با شکلی که
     * `of` نام می‌برد می‌سنجد — همان‌جایی که دِرفتِ `SkQuality`/`SkTechnology`
     * واقعاً افتاده بود: سطحِ بالا (`title`/`cards`) درست بود، فقط کارت‌های
     * داخلِ `cards` به شکلِ نوعِ غلط بسته شده بودند.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    private function collectListFieldViolations(string $fieldName, array $field, array $data, string $where, array &$violations): void
    {
        if (($field['type'] ?? null) !== 'list' || ! isset($data[$fieldName]) || ! is_array($data[$fieldName])) {
            return;
        }

        $shapeName = $field['of'] ?? null;
        $shape = PageBlockSchema::SHAPES[$shapeName] ?? null;

        if ($shape === null) {
            // `of` این‌جا نامِ یک ویجتِ اسکالر است (مثلِ textarea/number)، نه
            // کلیدِ یک شکلِ آبجکتی — چیزی برای سنجیدنِ کلید نیست.
            return;
        }

        $allowedExtraKeys = self::KNOWN_UNDECLARED_SHAPE_KEYS[$shapeName] ?? [];

        foreach ($data[$fieldName] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (array_keys($item) as $key) {
                if (in_array($key, $allowedExtraKeys, true)) {
                    continue;
                }

                if (! array_key_exists($key, $shape)) {
                    $violations[] = "{$where} — کارتِ #{$index} در «{$fieldName}»: کلیدِ «{$key}» در داده هست ولی شکلِ «{$shapeName}» اعلامش نکرده.";
                }
            }
        }
    }
}
