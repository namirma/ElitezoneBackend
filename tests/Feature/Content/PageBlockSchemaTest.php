<?php

namespace Tests\Feature\Content;

use App\Services\Content\PageBlockSchema;
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
            if ($rule instanceof \Illuminate\Validation\Rules\In) {
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
            'banner_carousel', 'flip_cards',
        ], $this->schema->types());
    }
}
