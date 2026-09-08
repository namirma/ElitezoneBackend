<?php

namespace Tests\Feature\Content;

use App\Services\Content\PageBlockSchema;
use Illuminate\Validation\Rules\In;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageBlockSchemaTest extends TestCase
{
    private PageBlockSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new PageBlockSchema;
    }

    #[Test]
    public function every_type_declares_a_label_and_at_least_one_field(): void
    {
        foreach ($this->schema->all() as $type => $definition) {
            $this->assertArrayHasKey('label', $definition, "«{$type}» لیبل ندارد");
            $this->assertNotEmpty($definition['fields'], "«{$type}» هیچ فیلدی ندارد");
        }
    }

    #[Test]
    public function every_field_uses_a_known_widget_type(): void
    {
        $known = ['text', 'textarea', 'image', 'link', 'list', 'number', 'color', 'select', 'boolean', 'glass'];

        foreach ($this->schema->all() as $type => $definition) {
            foreach ($definition['fields'] as $name => $field) {
                $this->assertContains(
                    $field['type'],
                    $known,
                    "«{$type}.{$name}» نوعِ ناشناخته‌ی «{$field['type']}» دارد — بک‌آفیس ویجتی برایش ندارد"
                );
            }
        }
    }

    #[Test]
    public function every_shape_member_uses_a_known_widget_type(): void
    {
        // `every_field_uses_a_known_widget_type` فقط فیلدهای سطحِ بالا را می‌بیند؛
        // یک تایپوی «selct» داخلِ SHAPES هیچ‌جا رد نمی‌شد. این تست همان چک را
        // برای اعضای فهرست‌های آبجکتی (SHAPES) هم می‌زند.
        $known = ['text', 'textarea', 'image', 'link', 'list', 'number', 'color', 'select', 'boolean', 'glass'];

        foreach (PageBlockSchema::SHAPES as $shape => $members) {
            foreach ($members as $name => $member) {
                $widgetType = is_array($member) ? $member['type'] : $member;

                $this->assertContains(
                    $widgetType,
                    $known,
                    "«{$shape}.{$name}» نوعِ ناشناخته‌ی «{$widgetType}» دارد — بک‌آفیس ویجتی برایش ندارد"
                );
            }
        }
    }

    #[Test]
    public function a_required_field_becomes_a_required_rule(): void
    {
        $rules = $this->schema->rulesFor('story_card');

        $this->assertContains('required', $rules['data.title']);
        $this->assertContains('string', $rules['data.title']);
    }

    #[Test]
    public function a_list_field_gets_both_an_array_rule_and_a_member_rule(): void
    {
        $rules = $this->schema->rulesFor('story_card');

        $this->assertContains('array', $rules['data.items']);
        $this->assertArrayHasKey('data.items.*', $rules);
    }

    #[Test]
    public function a_number_list_field_gets_a_numeric_member_rule_not_a_string_one(): void
    {
        // `captionEnBold` (story_card) و `bodyStrong` (media_text) هر دو
        // `['type' => 'list', 'of' => 'number']` هستند — اندیس‌های عددی‌ای که
        // StoryCard.jsx/PdIntro.jsx برای بولدکردنِ بخشی از متنِ ساده می‌خوانند.
        // listRules() هر عضوِ فهرستِ بدونِ‌شکل را — صرف‌نظر از `of` — همیشه رشته
        // اعتبارسنجی می‌کرد، یعنی هر اندیس باید یک رشته باشد، نه عدد.
        $rules = $this->schema->rulesFor('story_card');
        $memberRule = $rules['data.captionEnBold.*'];

        $this->assertContains('numeric', $memberRule);
        $this->assertNotContains('string', $memberRule);
    }

    #[Test]
    public function a_shape_number_list_member_gets_a_numeric_member_rule_not_a_string_one(): void
    {
        // `feature.bodyStrong` (کارت‌های `feature_cards`) هم مثلِ
        // `story_card.captionEnBold`/`media_text.bodyStrong` یک
        // `['type' => 'list', 'of' => 'number']` است — با این تفاوت که این‌جا
        // عضوِ یک SHAPE است، نه فیلدِ سطحِ بالا، پس مسیرِ کد فرق دارد:
        // `listRules()` باید برای این عضو، بازگشتی دوباره خودش را صدا بزند
        // (همان مسیرِ `flip_panel.paragraphs`) و در آن فراخوانیِ بازگشتی هم
        // قاعده‌ی عددی را بدهد، نه رشته‌ای.
        $rules = $this->schema->rulesFor('feature_cards');
        $memberRule = $rules['data.cards.*.bodyStrong.*'];

        $this->assertContains('numeric', $memberRule);
        $this->assertNotContains('string', $memberRule);
    }

    #[Test]
    public function a_required_list_field_becomes_a_required_array_rule(): void
    {
        // این فیلدها در اسکیما `required => true` دارند اما listRules() تا پیش از
        // این، قاعده‌ی سطحِ آرایه را همیشه hardcode روی nullable می‌گذاشت — یعنی
        // یک feature_cards بدون هیچ کارتی هم قبول می‌شد.
        //
        // فهرست جفت‌جفت است، نه map: `hero_actions` دو فهرستِ اجباری دارد. اما
        // تنها چهارتای این ده مورد را کامپوننتِ سایت بدونِ گارد `map` می‌کند —
        // `hero_actions.lines`، `hero_actions.actions`، `card_grid.cards` و
        // `media_text.body` — که برایشان آرایه‌ی خالی/غایب یعنی صفحه‌ی شکسته.
        // شش موردِ دیگر پارامترشان مقدارِ پیش‌فرض دارد (`Block8Audiences.jsx:31`،
        // `FlipCard.jsx:30`، `SkGlance.jsx:15`، `SkProducts.jsx:16`،
        // `Block7Callouts.jsx:15`، `Block6Trust.jsx:18`) پس غایب‌بودن به یک
        // کپیِ هاردکد سقوط می‌کند، نه شکستنِ صفحه. با این‌همه `required` برای
        // هر ده‌تا درست است: یک `null` صریح یا آرایه‌ی خالی هم رد می‌شود، و هر دو
        // یا صفحه را می‌شکنند یا یک بلوکِ خالی رندر می‌کنند.
        $cases = [
            ['glass_cards', 'cards'],
            ['media_text', 'body'],
            ['flip_card', 'paragraphs'],
            ['stat_carousel', 'cards'],
            ['feature_cards', 'cards'],
            ['callout_list', 'callouts'],
            ['image_glass_band', 'body'],
            ['hero_actions', 'lines'],
            ['hero_actions', 'actions'],
            ['card_grid', 'cards'],
        ];

        foreach ($cases as [$type, $field]) {
            $rules = $this->schema->rulesFor($type);

            $this->assertContains(
                'required',
                $rules["data.{$field}"],
                "«{$type}.{$field}» در اسکیما required است ولی قاعده‌اش nullable ماند"
            );
        }
    }

    #[Test]
    public function a_shape_select_member_with_options_is_enforced_by_rule_in(): void
    {
        // action.tone یک select با دو گزینه‌ی مجاز (solid/stroke) است، اما
        // listRules() پیش از این، اعضای شکل را همیشه به‌عنوان رشته‌ی آزاد
        // اعتبارسنجی می‌کرد — یعنی {"tone": "banana"} قبول می‌شد.
        $rules = $this->schema->rulesFor('hero_actions');
        $toneRules = $rules['data.actions.*.tone'];

        $inRule = null;
        foreach ($toneRules as $rule) {
            if ($rule instanceof In) {
                $inRule = $rule;
            }
        }

        $this->assertNotNull($inRule, '«action.tone» باید با Rule::in به گزینه‌های مجازش محدود شود');
        $this->assertSame('in:"solid","stroke"', (string) $inRule);
    }

    #[Test]
    public function hero_actions_limits_its_button_count_to_match_its_hint(): void
    {
        // هینتِ hero_actions می‌گوید «یک یا دو دکمه‌ی فراخوان»، پس قاعده باید
        // دقیقاً همین را اجرا کند.
        $rules = $this->schema->rulesFor('hero_actions');

        $this->assertContains('max:2', $rules['data.actions']);
    }

    #[Test]
    public function a_select_field_with_no_options_throws_instead_of_silently_rejecting_everything(): void
    {
        // Rule::in(null) رد نمی‌کند، بلکه بی‌صدا همه‌چیز را رد می‌کند — این خطای
        // برنامه‌نویس (فراموش‌کردنِ options) باید همان‌جا با استثنا متوقف شود.
        $method = new \ReflectionMethod(PageBlockSchema::class, 'selectOptions');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);

        $method->invoke($this->schema, ['type' => 'select'], 'demo_type.demo_field');
    }

    #[Test]
    public function an_unknown_type_has_no_rules_and_is_not_declared(): void
    {
        $this->assertFalse($this->schema->has('not_a_block'));
        $this->assertSame([], $this->schema->rulesFor('not_a_block'));
    }

    #[Test]
    public function only_the_types_whose_component_takes_an_id_prop_declare_it(): void
    {
        // فقط بلوک‌های پادیوم/فولاد کویر روی `<section id="...">` امکانِ
        // anchor دارند — `HashScroll` (که در `AppLayout` نصب است) با همین
        // id به `/podium#...`/`/steel-kavirCo#...` اسکرول می‌کند. بلوک‌های
        // هوم (`hero_media`, `video`, `story_card`, `image_glass_band`,
        // `callout_list`, `glass_cards`, `faq`) کامپوننتشان اصلاً پراپِ id
        // نمی‌گیرد، پس نباید اسکیما هم این فیلد را رویشان اعلام کند — یک
        // فیلدِ اضافه که هیچ کامپوننتی نمی‌خواندش، فرمِ ادمین را با
        // ورودیِ بی‌اثر پر می‌کرد.
        $withId = [
            'hero_actions', 'card_grid', 'media_text', 'flip_card', 'cta_band',
            'sk_hero', 'stat_carousel', 'feature_cards', 'banner_carousel', 'flip_cards',
            'card_row', 'latin_media_text',
        ];

        foreach ($this->schema->all() as $type => $definition) {
            $hasId = array_key_exists('id', $definition['fields']);

            if (in_array($type, $withId, true)) {
                $this->assertTrue($hasId, "«{$type}» باید فیلدِ id داشته باشد — کامپوننتش آن را روی <section> می‌گذارد");
            } else {
                $this->assertFalse($hasId, "«{$type}» نباید فیلدِ id داشته باشد — کامپوننتش این پراپ را نمی‌گیرد");
            }
        }
    }

    #[Test]
    public function the_type_list_is_pinned_so_the_site_cannot_fall_behind_it(): void
    {
        // سایت رجیستریِ خودش را دارد (`blockRegistry.jsx`) و **نمی‌تواند** این
        // آرایه‌ی PHP را بخواند. اگر نوعی این‌جا اضافه شود و آن‌جا نه، بلوک روی
        // صفحه بی‌صدا غیب می‌شود — نه خطایی، نه تستِ قرمزی.
        //
        // این تست همان‌جایی می‌شکند که تغییر انجام می‌شود: هر که نوعی اضافه کند
        // مجبور است این فهرست را هم به‌روز کند و همین‌جا یادآوری را می‌بیند.
        //
        // ⛔ با افزودنِ نوعِ جدید، `src/features/content/ui/blockRegistry.jsx`
        //    در ریپوی فرانت هم باید یک کامپوننت بگیرد، وگرنه بلوک رندر نمی‌شود.
        $this->assertSame([
            'hero_media', 'video', 'story_card', 'image_glass_band', 'callout_list',
            'glass_cards', 'faq', 'hero_actions', 'card_grid', 'media_text',
            'flip_card', 'cta_band', 'sk_hero', 'stat_carousel', 'feature_cards',
            'banner_carousel', 'flip_cards', 'card_row', 'latin_media_text',
        ], $this->schema->types());
    }

    #[Test]
    public function every_field_of_every_type_has_a_non_empty_label(): void
    {
        // بک‌آفیس فرم را از همین آرایه می‌سازد — بدونِ `label` روی هر فیلد،
        // ادمین به‌جای متنِ فارسی، کلیدِ خامِ فیلد (`headlineLine1`,
        // `posterSrc`, ...) را کنارِ ورودی می‌بیند. این تست دقیقاً همان شکافی
        // را می‌گیرد که این تسک برای پرکردنش باز شد.
        foreach ($this->schema->all() as $type => $definition) {
            foreach ($definition['fields'] as $fieldName => $field) {
                $this->assertArrayHasKey(
                    'label',
                    $field,
                    "«{$type}.{$fieldName}» هیچ label ندارد"
                );
                $this->assertNotSame(
                    '',
                    trim((string) ($field['label'] ?? '')),
                    "«{$type}.{$fieldName}» label خالی دارد"
                );
            }
        }
    }

    /**
     * پیش از این، `data.glass` فقط `['nullable', 'array']` بود — بدونِ قاعده‌ی
     * فرزند، لاراول نمی‌توانست کلیدهای ناشناخته را حذف کند و هر JSONای که
     * ادمین می‌فرستاد همان‌طور ذخیره می‌شد.
     */
    #[Test]
    public function glass_has_a_declared_child_rule_for_each_known_control(): void
    {
        $rules = $this->schema->rulesFor('video'); // ویدیو یک فیلدِ glass دارد

        foreach (['variant', 'fill', 'frost', 'depth', 'lightAngle', 'radius', 'rimSide', 'rimFlat'] as $control) {
            $this->assertArrayHasKey("data.glass.{$control}", $rules, "«data.glass.{$control}» قاعده ندارد");
        }
    }

    #[Test]
    public function an_unknown_glass_variant_is_rejected_by_rule_in(): void
    {
        $rules = $this->schema->rulesFor('video');

        $inRule = null;
        foreach ($rules['data.glass.variant'] as $rule) {
            if ($rule instanceof In) {
                $inRule = $rule;
            }
        }

        $this->assertNotNull($inRule, '«data.glass.variant» باید با Rule::in به واریانت‌های مجاز محدود شود');
        $this->assertSame('in:"clear","light","dark","control"', (string) $inRule);
    }

    /**
     * نگهبانِ رگرسیون: `link` سطحِ بالا همیشه `.to` را به ۲۰۴۸ کاراکتر محدود
     * کرده، اما همین شکل وقتی عضوِ یک SHAPE بود (مثلِ `audience.link` زیرِ
     * `glass_cards.cards`) هیچ قاعده‌ی فرزندی نداشت — یعنی تنها مسیرِ باقی‌مانده
     * که ستون هر JSONای را بدونِ توصیفِ اسکیما قبول می‌کرد.
     */
    #[Test]
    public function a_shape_level_link_member_caps_its_to_field_like_the_top_level_link(): void
    {
        $rules = $this->schema->rulesFor('glass_cards');

        $this->assertContains('max:2048', $rules['data.cards.*.link.to']);
        $this->assertContains('max:120', $rules['data.cards.*.link.label']);
    }

    #[Test]
    public function the_three_select_fields_serve_persian_labelled_options(): void
    {
        $cardGridFields = $this->schema->for('card_grid')['fields'];

        $this->assertSame([
            ['value' => 'light', 'label' => 'روشن'],
            ['value' => 'dark', 'label' => 'تیره'],
        ], $cardGridFields['tone']['options']);

        $this->assertSame([
            ['value' => 'rtl', 'label' => 'راست‌به‌چپ'],
            ['value' => 'ltr', 'label' => 'چپ‌به‌راست'],
        ], $cardGridFields['flow']['options']);

        $this->assertSame([
            ['value' => 'solid', 'label' => 'توپر'],
            ['value' => 'stroke', 'label' => 'خط‌دار'],
        ], PageBlockSchema::SHAPES['action']['tone']['options']);
    }

    #[Test]
    public function rule_in_still_validates_bare_values_after_labelling_the_options(): void
    {
        $rules = $this->schema->rulesFor('card_grid');

        $inRule = null;
        foreach ($rules['data.tone'] as $rule) {
            if ($rule instanceof In) {
                $inRule = $rule;
            }
        }

        $this->assertNotNull($inRule);
        $this->assertSame('in:"light","dark"', (string) $inRule);
    }

    #[Test]
    public function every_member_of_every_shape_has_a_non_empty_label(): void
    {
        // همان شکاف، برای اعضای فهرست‌های آبجکتی (`SHAPES`) — یک کارتِ
        // `card_grid` بدونِ لیبل روی `heading`/`note` هم همین مشکل را دارد،
        // فقط یک لایه پایین‌تر.
        foreach (PageBlockSchema::SHAPES as $shape => $members) {
            foreach ($members as $name => $member) {
                $this->assertIsArray(
                    $member,
                    "«{$shape}.{$name}» آرایه نیست — نمی‌تواند label داشته باشد"
                );
                $this->assertArrayHasKey(
                    'label',
                    $member,
                    "«{$shape}.{$name}» هیچ label ندارد"
                );
                $this->assertNotSame(
                    '',
                    trim((string) ($member['label'] ?? '')),
                    "«{$shape}.{$name}» label خالی دارد"
                );
            }
        }
    }
}
