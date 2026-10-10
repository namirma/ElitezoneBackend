<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Faq;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /search/site` — جست‌وجوی سراسریِ سایتِ جدید: فقط محتوای منتشرشده، با یکسان‌سازیِ
 * ارقامِ فارسی/عربی و حروفِ «ي/ك».
 */
class SiteSearchTest extends TestCase
{
    use DatabaseTransactions;

    private string $nonce;

    protected function setUp(): void
    {
        parent::setUp();
        // نشانه‌ی یکتا تا ردیف‌های سیدشده‌ی دیتابیسِ تست در نتیجه نیایند.
        $this->nonce = 'zq'.Str::lower(Str::random(6));
    }

    private function product(string $name, bool $active = true): Product
    {
        $category = ProductCategory::create([
            ProductCategory::NAME => 'دسته',
            ProductCategory::SLUG => 'cat-'.Str::random(8),
            'code' => 'C_'.Str::random(6),
        ]);

        return Product::create([
            Product::CATEGORY_ID => $category->id,
            Product::NAME => $name,
            Product::SLUG => 'p-'.Str::random(8),
            Product::SKU => 'S_'.Str::random(6),
            Product::IS_ACTIVE => $active,
        ]);
    }

    private function variant(Product $product, string $name, bool $active = true): ProductVariant
    {
        return ProductVariant::create([
            ProductVariant::PRODUCT_ID => $product->id,
            ProductVariant::NAME => $name,
            ProductVariant::SKU => 'V_'.Str::random(6),
            ProductVariant::IS_ACTIVE => $active,
        ]);
    }

    #[Test]
    public function it_finds_published_rows_across_letter_and_digit_forms(): void
    {
        $n = $this->nonce;
        $rebar = $this->product("میلگرد {$n}");
        $visible = $this->variant($rebar, "میلگرد {$n} ۱۴ ذوب‌آهن");
        $this->variant($rebar, "میلگرد {$n} ۱۴ خاموش", active: false);
        $this->variant($this->product("میلگرد {$n} پنهان", active: false), "میلگرد {$n} ۱۴ زیرِ محصولِ خاموش");

        // حروفِ عربی + ارقامِ لاتین در جست‌وجو، حروفِ فارسی + ارقامِ فارسی در داده.
        $data = $this->getJson('/api/search/site?q='.urlencode("ميلگرد {$n} 14"))->assertOk()->json('data');

        $this->assertSame([[
            'id' => $visible->id,
            'name' => $visible->name,
            'product_cuid' => $rebar->cuid,
            'product_name' => $rebar->name,
        ]], $data['variants']);

        // ارقامِ عربی‌هندی («١٤») هم همان را پیدا می‌کنند.
        $this->assertSame(
            [$visible->id],
            array_column($this->getJson('/api/search/site?q='.urlencode("{$n} ١٤"))->assertOk()->json('data.variants'), 'id')
        );

        $products = $this->getJson('/api/search/site?q='.urlencode($n))->assertOk()->json('data.products');
        $this->assertSame([['cuid' => $rebar->cuid, 'name' => $rebar->name]], $products, 'an inactive product never surfaces');
    }

    #[Test]
    public function it_returns_active_pages_and_faq_questions_only(): void
    {
        $n = $this->nonce;
        $page = Page::create([Page::TITLE => "قوانین {$n}", Page::SLUG => "rules-{$n}", Page::BODY => 'متن']);
        Page::create([Page::TITLE => "پیش‌نویس {$n}", Page::SLUG => "draft-{$n}", Page::BODY => 'متن', Page::IS_ACTIVE => false]);
        $faq = Faq::create([Faq::QUESTION => "چگونه {$n} بخرم؟", Faq::ANSWER => 'پاسخ']);
        Faq::create([Faq::QUESTION => "قدیمی {$n}؟", Faq::ANSWER => 'پاسخ', Faq::IS_ACTIVE => false]);

        $data = $this->getJson('/api/search/site?q='.urlencode($n))->assertOk()->json('data');

        $this->assertSame([['slug' => "rules-{$n}", 'title' => $page->title, 'route' => "/p/rules-{$n}"]], $data['pages']);
        $this->assertSame([$faq->id], array_column($data['faqs'], 'id'));
    }

    #[Test]
    public function wildcards_are_literal_and_a_one_letter_query_is_refused(): void
    {
        $this->getJson('/api/search/site?q=x')->assertStatus(422);

        $data = $this->getJson('/api/search/site?q='.urlencode('%%'))->assertOk()->json('data');
        foreach (['products', 'variants', 'pages', 'faqs'] as $group) {
            $this->assertSame([], $data[$group], "«%%» must not enumerate {$group}");
        }
    }
}
