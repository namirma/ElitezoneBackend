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
    public function an_unknown_type_has_no_rules_and_is_not_declared(): void
    {
        $this->assertFalse($this->schema->has('not_a_block'));
        $this->assertSame([], $this->schema->rulesFor('not_a_block'));
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
        ], $this->schema->types());
    }
}
