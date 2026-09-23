<?php

namespace Database\Seeders;

use App\Models\Factory;
use App\Models\FactoryLocation;
use App\Models\FactoryStock;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\Concerns\SeedsProductAttributes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * کاتالوگ نمایشی برای استیجینگ — تا تیم تولید سایتِ پر ببیند.
 *
 * ⚠️ **چرا `ProductSeeder` کافی نبود.** آن سیدر محصول و قیمت می‌سازد ولی
 * `factories.supplier_user_id` را خالی می‌گذارد. قاعده‌ی نمایش در
 * `FilterProductsAction::hideProductsWithExpiredPrices()` می‌گوید:
 *
 *   محصولی که **ردیف قیمت دارد** فقط وقتی دیده می‌شود که کارخانه‌ی آن قیمت،
 *   تأمین‌کننده‌ای با `supplier_profiles.reserve_time` **در آینده** داشته باشد.
 *
 * پس با داده‌ی فعلی، تنها محصولی که قیمت داشت از کاتالوگ **حذف** می‌شد و آنکه
 * می‌ماند نه قیمت داشت نه عکس. سایت خالی به نظر می‌رسید بی‌آنکه هیچ خطایی
 * بدهد — نه ۵۰۰، نه لاگی، فقط یک آرایه‌ی خالی.
 *
 * این سیدر آن حلقه را می‌بندد: تأمین‌کننده‌ی نمایشی می‌سازد، کارخانه‌ها را به
 * او وصل می‌کند، `reserve_time` را زنده نگه می‌دارد، و کاتالوگ را در هر چهار
 * دسته پر می‌کند.
 *
 * `updateOrCreate` در همه‌جا: اجرای دوباره چیزی را دوبرابر نمی‌کند.
 *
 * ⚠️ **`reserve_time` تاریخ انقضا دارد.** با گذشتنش کاتالوگ دوباره خالی
 * می‌شود. یک سال جلو تنظیم شده و اجرای دوباره‌ی همین سیدر تمدیدش می‌کند.
 *
 * اجرا:  php artisan db:seed --class=StagingCatalogSeeder
 */
class StagingCatalogSeeder extends Seeder
{
    use SeedsProductAttributes;

    /** موبایلِ تأمین‌کننده‌ی نمایشی — کلید idempotency این سیدر. */
    private const DEMO_SUPPLIER_MOBILE = '09120000001';

    public function run(): void
    {
        $supplier = $this->demoSupplier();
        $factories = $this->attachFactoriesTo($supplier);

        if ($factories->isEmpty()) {
            $this->command?->error('StagingCatalogSeeder: no factories found — run ProductSeeder first.');

            return;
        }

        $this->ensurePlaceholderImages();
        $this->ensureAttributeVocabulary();

        $created = 0;
        foreach ($this->catalogue() as $slug => $spec) {
            $created += $this->seedProduct($slug, $spec, $factories) ? 1 : 0;
        }

        $this->command?->info(sprintf(
            'StagingCatalogSeeder: supplier #%d live until %s · %d product(s) · %d price row(s).',
            $supplier->id,
            $supplier->supplier?->{SupplierProfile::RESERVE_TIME},
            $created,
            ProductPrice::count(),
        ));
    }

    /**
     * تأمین‌کننده‌ی نمایشی با پروفایلِ تأییدشده و پنجره‌ی قیمتِ زنده.
     *
     * `reserve_time` قلبِ ماجراست — بدونش کلِ کاتالوگ نامرئی می‌ماند.
     */
    private function demoSupplier(): User
    {
        $user = User::updateOrCreate(
            [User::MOBILE => self::DEMO_SUPPLIER_MOBILE],
            [User::IS_ACTIVE => true],
        );

        SupplierProfile::updateOrCreate(
            [SupplierProfile::USER_ID => $user->id],
            [
                SupplierProfile::COMPANY => 'تأمین‌کننده‌ی نمایشی الیت‌زون',
                SupplierProfile::STATUS => SupplierProfile::STATUS_APPROVED,
                // یک سال جلو: استیجینگ نباید هفته‌ی بعد بی‌صدا خالی شود.
                SupplierProfile::RESERVE_TIME => now()->addYear(),
                SupplierProfile::TRANS_COMMISSION => true,
                SupplierProfile::TRANS_VAT => true,
            ],
        );

        // نقش برای کاتالوگ لازم نیست (گاردِ نمایش فقط `reserve_time` را
        // می‌بیند) ولی بدون آن پنل تأمین‌کننده روی همین کاربر باز نمی‌شود.
        //
        // وجودِ ردیفِ نقش چک می‌شود چون `addRole` روی دیتابیسی که
        // `UserTypeSeeder` رویش نرفته، با `ModelNotFoundException` می‌میرد و
        // کلِ کاتالوگ را با پیامی که هیچ ربطی به کاتالوگ ندارد می‌خواباند.
        if (Role::where('name', 'supplier')->exists()) {
            if (! $user->hasRole('supplier')) {
                $user->addRole('supplier');
            }
        } else {
            $this->command?->warn('StagingCatalogSeeder: role "supplier" missing — run UserTypeSeeder to enable the supplier panel.');
        }

        return $user->refresh();
    }

    /** کارخانه‌های موجود را به تأمین‌کننده‌ی نمایشی گره می‌زند. */
    private function attachFactoriesTo(User $supplier)
    {
        Factory::query()->whereNull('supplier_user_id')->update(['supplier_user_id' => $supplier->id]);

        return Factory::query()->with('locations')->get();
    }

    /**
     * تصویرِ جانشین per-دسته.
     *
     * PNG و نه SVG — قاعده‌ی سراسری پروژه SVG را روی دیسکِ public ممنوع کرده
     * (XSS ذخیره‌شده). بدون فایلِ واقعی، هر کارتِ محصول یک ۴۰۴ می‌داد.
     */
    private function ensurePlaceholderImages(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->command?->warn('StagingCatalogSeeder: ext-gd missing — skipping placeholder images.');

            return;
        }

        $palette = [
            'rebar' => [0x37, 0x4151],
            'steel' => [0x1F, 0x2937],
            'brick' => [0x9A, 0x3412],
            'taps' => [0x0F, 0x766E],
        ];

        foreach ($palette as $slug => [$r, $rest]) {
            $path = "products/placeholder-{$slug}.png";

            if (Storage::disk('public')->exists($path)) {
                continue;
            }

            $img = imagecreatetruecolor(800, 600);
            imagefill($img, 0, 0, imagecolorallocate($img, $r, ($rest >> 8) & 0xFF, $rest & 0xFF));

            ob_start();
            imagepng($img);
            $bytes = (string) ob_get_clean();
            imagedestroy($img);

            Storage::disk('public')->put($path, $bytes);
        }
    }

    /**
     * کاتالوگ در هر چهار دسته.
     *
     * قیمت‌ها ریال بر کیلوگرم‌اند — مقیاسِ همان اعدادی که `ProductSeeder`
     * برای میلگرد گذاشته بود، تا ارقامِ سبد خرید واقع‌نما بمانند.
     *
     * @return array<string, array{category: string, name: string, variants: array<int, array{name: string, sku: string, stock: int, general: int}>}>
     */
    /**
     * واریانت‌های میلگرد — تولیدشده، نه فهرستِ دستی.
     *
     * سایدبار (`GET /products/{cuid}/filters`) فقط گزینه‌هایی را برمی‌گرداند
     * که **دستِ‌کم یک واریانتِ همین محصول** آن‌ها را دارد. پس اگر واریانت‌ها را
     * دستی بنویسیم، هر گزینه‌ای که جا بیفتد از سایدبار غیب می‌شود و کاتالوگِ
     * استیجینگ از سایتِ اصلی کوچک‌تر درمی‌آید — دقیقاً همان چیزی که با سه
     * واریانتِ قبلی اتفاق افتاد.
     *
     * روش: طولِ حلقه = بزرگ‌ترینِ فهرست‌ها، و بقیه round-robin. با این کار هر
     * مقدارِ هر گروه حتماً حداقل یک‌بار می‌آید.
     *
     * قیمت با قطر بالا می‌رود (میلگردِ نازک‌تر گران‌تر است، پس نزولی نسبت به
     * سایز) تا مرتب‌سازیِ «ارزان‌ترین» روی استیجینگ چیزِ بامعنایی نشان بدهد.
     *
     * @return array<int, array{name: string, sku: string, stock: int, general: int, attrs: array<string,string>}>
     */
    private static function rebarVariants(): array
    {
        $sizes = ['65', '80', '100', '120', '140', '160', '180', '200',
            '220', '250', '260', '280', '300', '320', '360', '400'];
        $lengths = ['6 متر', '7 متر', '7.2 متر', '7.8 متر', '7.9 متر', '8 متر', '8.7 متر',
            '8.9 متر', '8.95 متر', '9 متر', '9.1 متر', '10 متر', '10.5 متر',
            '11.7 متر', '12 متر', '15 متر', '15.5 متر', '18 متر', '20 متر', '25 متر'];
        $grades = ['A0', 'A2', 'A3', 'A4', 'A5'];
        $types = ['شاخه', 'کلاف'];

        $variants = [];
        $count = max(count($sizes), count($lengths), count($grades));

        for ($i = 0; $i < $count; $i++) {
            $size = $sizes[$i % count($sizes)];
            $length = $lengths[$i % count($lengths)];
            $grade = $grades[$i % count($grades)];
            $type = $types[$i % count($types)];

            $attrs = [
                'نوع محصول' => $type,
                'نوع سطح' => 'آجدار',
                'گرید' => $grade,
                'سایز (قطر)' => $size,
                'بسته‌بندی' => 'نرمال',
            ];

            // کلاف طولِ شاخه ندارد — ویژگیِ بی‌معنا در سایدبار گزینه‌ای می‌سازد
            // که چیزِ درستی را فیلتر نمی‌کند.
            if ($type === 'شاخه') {
                $attrs['طول شاخه'] = $length;
            }

            $variants[] = [
                'name' => "میلگرد {$type} {$grade} سایز {$size}",
                'sku' => sprintf('DEMO-RBR-%s-%s-%02d', $grade, $size, $i),
                'stock' => 120_000 + ($i * 5_000),
                'general' => 54_000 - ((int) $size * 10),
                'attrs' => $attrs,
            ];
        }

        return $variants;
    }

    private function catalogue(): array
    {
        return [
            // میلگرد آجدار — کاتالوگِ مرجع. واریانت‌ها با `rebarVariants()` تولید
            // می‌شوند تا **هر گزینه‌ی سایدبار دستِ‌کم یک واریانت داشته باشد**؛
            // فهرستِ دستی یعنی گزینه‌ای که هیچ واریانتی ندارد اصلاً در
            // `/filters` ظاهر نمی‌شود و سایدبارِ استیجینگ از سایتِ اصلی کوچک‌تر
            // درمی‌آید.
            'rebar-rolled' => ['desc' => 'میلگرد آجدار با سطح رزوه‌دار برای چسبندگی بیشتر به بتن — پرمصرف‌ترین میلگرد ساختمانی. تحویل از درب کارخانه یا انبارهای اصفهان، تهران و شیراز.', 'category' => 'rebar', 'name' => 'میلگرد آجدار', 'variants' => self::rebarVariants()],

            'beam-ipe' => ['desc' => 'تیرآهن IPE با مقطع I، پرکاربردترین پروفیل باربر در اسکلت فلزی ساختمان. مطابق استاندارد ملی ایران.', 'category' => 'steel', 'name' => 'تیرآهن IPE', 'variants' => [
                ['name' => 'IPE 14', 'sku' => 'DEMO-IPE-14', 'stock' => 90_000, 'general' => 52_000, 'attrs' => ['نوع محصول' => 'IPE', 'گرید' => 'ST37', 'سایز (قطر)' => '14', 'طول شاخه' => '12 متر', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'IPE 16', 'sku' => 'DEMO-IPE-16', 'stock' => 85_000, 'general' => 51_500, 'attrs' => ['نوع محصول' => 'IPE', 'گرید' => 'ST37', 'سایز (قطر)' => '16', 'طول شاخه' => '12 متر', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'IPE 18', 'sku' => 'DEMO-IPE-18', 'stock' => 70_000, 'general' => 51_000, 'attrs' => ['نوع محصول' => 'IPE', 'گرید' => 'ST37', 'سایز (قطر)' => '18', 'طول شاخه' => '12 متر', 'بسته‌بندی' => 'نرمال']],
            ]],
            'angle-bar' => ['desc' => 'نبشی فولادی نورد گرم برای بادبند، اتصالات و قاب‌بندی. ابعاد متنوع با ضخامت‌های استاندارد.', 'category' => 'steel', 'name' => 'نبشی فولادی', 'variants' => [
                ['name' => 'نبشی ۵×۵', 'sku' => 'DEMO-ANG-50', 'stock' => 120_000, 'general' => 48_500, 'attrs' => ['نوع محصول' => 'نبشی', 'گرید' => 'ST37', 'سایز (قطر)' => '50', 'طول شاخه' => '6 متر', 'بسته‌بندی' => 'بندیل']],
                ['name' => 'نبشی ۶×۶', 'sku' => 'DEMO-ANG-60', 'stock' => 110_000, 'general' => 48_000, 'attrs' => ['نوع محصول' => 'نبشی', 'گرید' => 'ST37', 'سایز (قطر)' => '60', 'طول شاخه' => '6 متر', 'بسته‌بندی' => 'بندیل']],
            ]],
            'steel-sheet' => ['desc' => 'ورق سیاه نورد گرم، مناسب مخزن‌سازی، سازه‌های صنعتی و ورق‌کاری ساختمانی.', 'category' => 'steel', 'name' => 'ورق سیاه', 'variants' => [
                ['name' => 'ضخامت ۲ میل', 'sku' => 'DEMO-SHT-02', 'stock' => 140_000, 'general' => 56_000, 'attrs' => ['نوع محصول' => 'ورق', 'گرید' => 'ST37', 'سایز (قطر)' => '2', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'ضخامت ۳ میل', 'sku' => 'DEMO-SHT-03', 'stock' => 130_000, 'general' => 55_500, 'attrs' => ['نوع محصول' => 'ورق', 'گرید' => 'ST37', 'سایز (قطر)' => '3', 'بسته‌بندی' => 'نرمال']],
            ]],
            'brick-sofal' => ['desc' => 'آجر سفال تیغه‌ای با وزن سبک و عایق حرارتی مناسب، برای دیوارچینی داخلی و پرکننده.', 'category' => 'brick', 'name' => 'آجر سفال', 'variants' => [
                ['name' => 'سفال ۱۰×۲۰×۲۰', 'sku' => 'DEMO-BRK-SF1', 'stock' => 400_000, 'general' => 12_500, 'attrs' => ['نوع محصول' => 'سفال', 'سایز (قطر)' => '10×20×20', 'بسته‌بندی' => 'پالت']],
                ['name' => 'سفال ۱۵×۲۰×۲۰', 'sku' => 'DEMO-BRK-SF2', 'stock' => 350_000, 'general' => 13_500, 'attrs' => ['نوع محصول' => 'سفال', 'سایز (قطر)' => '15×20×20', 'بسته‌بندی' => 'پالت']],
            ]],
            // ⚠️ دو حالتِ «ناموجود» عمداً — بدونشان دکمه‌ی «موجود شد خبرم کن» روی
            // استیجینگ هرگز دیده نمی‌شد و قابلِ آزمودن نبود:
            //   - `brick-fire`: **کلِ محصول** ناموجود (تنها واریانتش صفر)
            //   - `DEMO-VLV-GT3`: **یک واریانت** ناموجود، کنارِ واریانتِ موجود
            // موجودی هم روی واریانت صفر است هم روی `factory_stocks`، پس شارژِ دوباره
            // از پنلِ تأمین‌کننده جمع را درست بالا می‌برد و `stock:notify-subscribers`
            // آن را می‌بیند. اجرای دوباره‌ی سیدر این دو را دوباره صفر می‌کند — برای
            // تکرارِ آزمون همین مطلوب است.
            'brick-fire' => ['desc' => 'آجر نسوز نما با مقاومت حرارتی بالا، مناسب نماکاری، شومینه و کوره.', 'category' => 'brick', 'name' => 'آجر نسوز', 'variants' => [
                ['name' => 'نسوز نما قرمز', 'sku' => 'DEMO-BRK-FR1', 'stock' => 0, 'general' => 21_000, 'attrs' => ['نوع محصول' => 'نسوز', 'سایز (قطر)' => '22×11×5.5', 'بسته‌بندی' => 'پالت']],
            ]],
            'valve-gate' => ['desc' => 'شیر فلکه کشویی چدنی با آب‌بندی کامل، مناسب خطوط انتقال آب و تأسیسات ساختمانی.', 'category' => 'taps', 'name' => 'شیر فلکه کشویی', 'variants' => [
                ['name' => 'سایز ۲ اینچ', 'sku' => 'DEMO-VLV-GT2', 'stock' => 4_000, 'general' => 148_000, 'attrs' => ['نوع محصول' => 'کشویی', 'سایز (قطر)' => '2 اینچ', 'بسته‌بندی' => 'نرمال']],
                ['name' => 'سایز ۳ اینچ', 'sku' => 'DEMO-VLV-GT3', 'stock' => 0, 'general' => 186_000, 'attrs' => ['نوع محصول' => 'کشویی', 'سایز (قطر)' => '3 اینچ', 'بسته‌بندی' => 'نرمال']],
            ]],
            'valve-butterfly' => ['desc' => 'شیر پروانه‌ای ویفری با اهرم دستی، سبک و کم‌فضا برای خطوط لوله با قطر بالا.', 'category' => 'taps', 'name' => 'شیر پروانه‌ای', 'variants' => [
                ['name' => 'سایز ۴ اینچ', 'sku' => 'DEMO-VLV-BF4', 'stock' => 2_500, 'general' => 232_000, 'attrs' => ['نوع محصول' => 'پروانه‌ای', 'سایز (قطر)' => '4 اینچ', 'بسته‌بندی' => 'نرمال']],
            ]],
        ];
    }

    /**
     * واحدهای سفارش — بدون این، صفحه‌ی محصول می‌گوید «واحد سفارشی برای این
     * محصول تعریف نشده است و فعلاً قابل خرید نیست» و دکمه‌ی خرید کار نمی‌کند.
     *
     * نسخه‌ی اولِ این سیدر همین را جا انداخته بود: محصول، قیمت، عکس و موجودی
     * همه درست بودند و صفحه کامل به نظر می‌رسید، ولی جدولِ `product_order_units`
     * خالی بود. کاتالوگ دیده می‌شد و **هیچ‌کدام قابل خرید نبودند**.
     *
     * واحدها per-دسته‌اند چون منطقِ فروش فرق دارد: فولاد با ظرفیت کامیون و
     * بندیل هم فروخته می‌شود، شیرآلات فقط تکی.
     */
    private function attachOrderUnits(Product $product, string $categorySlug): void
    {
        $codes = match ($categorySlug) {
            'rebar', 'steel' => ['capacity' => 5, 'bundle' => 30, 'retail' => 2_000_000],
            'brick' => ['bundle' => 40, 'retail' => 500_000],
            default => ['retail' => 5_000],
        };

        foreach ($codes as $code => $maxQuantity) {
            $unit = OrderUnit::where(OrderUnit::CODE, $code)->first();

            if ($unit === null) {
                $this->command?->warn("StagingCatalogSeeder: OrderUnit '{$code}' missing — run ProductSeeder first.");

                continue;
            }

            $product->orderUnits()->syncWithoutDetaching([$unit->id => ['max_quantity' => $maxQuantity]]);
        }
    }

    /**
     * کف و سقفِ مقدار سفارش per-دسته.
     *
     * فولاد بر حسب کیلوگرم است و کمتر از دو تن عملاً حمل نمی‌شود؛ شیرآلات تکی
     * است و کفِ ۲۰۰۰ روی آن بی‌معنا می‌شد.
     *
     * @return array{0: int, 1: int}
     */
    private function orderBounds(string $categorySlug): array
    {
        return match ($categorySlug) {
            'rebar', 'steel' => [2_000, 200_000],
            'brick' => [1_000, 500_000],
            default => [1, 5_000],
        };
    }

    /** یک محصول با واریانت، قیمتِ هر انبار، موجودی و تصویر. */
    /**
     * گروه‌ها، گزینه‌ها و ردیفِ مقدارِ هر گزینه را می‌سازد.
     *
     * `ProductSeeder` گزینه‌های `type`/`grade`/`size` را می‌سازد ولی **ردیفِ
     * مقدار نمی‌سازد** — یعنی همان گزینه‌ها قابلِ وصل‌شدن به هیچ واریانتی
     * نیستند. این متد آن شکاف را هم پر می‌کند، نه فقط برای گزینه‌های تازه.
     */
    private function seedProduct(string $slug, array $spec, $factories): bool
    {
        $category = ProductCategory::where(ProductCategory::SLUG, $spec['category'])->first();

        if (! $category) {
            $this->command?->warn("StagingCatalogSeeder: category {$spec['category']} missing — skipping {$slug}.");

            return false;
        }

        $product = Product::updateOrCreate(
            [Product::SLUG => $slug],
            [
                Product::CATEGORY_ID => $category->id,
                Product::NAME => $spec['name'],
                Product::DESCRIPTION => $spec['desc'] ?? null,
                Product::SKU => strtoupper($slug),
                Product::IS_ACTIVE => true,
            ],
        );

        $this->attachOrderUnits($product, $spec['category']);

        ProductImage::updateOrCreate(
            [ProductImage::PRODUCT_ID => $product->id],
            [ProductImage::IMAGE_PATH => "products/placeholder-{$spec['category']}.png"],
        );

        foreach ($spec['variants'] as $v) {
            $variant = ProductVariant::updateOrCreate(
                [ProductVariant::SKU => $v['sku']],
                [
                    ProductVariant::PRODUCT_ID => $product->id,
                    ProductVariant::NAME => $v['name'],
                    ProductVariant::IS_ACTIVE => true,
                    ProductVariant::STOCK_QUANTITY => $v['stock'],
                    // بدون کف/سقف، فرم سفارش مقدار مجاز را نمی‌داند.
                    ProductVariant::MIN_ORDER_VALUE => $this->orderBounds($spec['category'])[0],
                    ProductVariant::MAX_ORDER_VALUE => $this->orderBounds($spec['category'])[1],
                ],
            );

            $this->attachAttributes($variant, $v['attrs'] ?? []);
            $this->priceVariantEverywhere($variant, $v['general'], $factories);
        }

        return true;
    }

    /**
     * قیمت و موجودی برای هر (کارخانه، انبار).
     *
     * انبارِ دور گران‌تر است تا اثرِ مبدأ روی قیمت در فرانت دیده شود — همان
     * الگویی که `ProductSeeder` برای میلگرد داشت.
     */
    private function priceVariantEverywhere(ProductVariant $variant, int $general, $factories): void
    {
        foreach ($factories as $factory) {
            foreach ($factory->locations as $i => $location) {
                /** @var FactoryLocation $location */
                $bump = $location->{FactoryLocation::TYPE} === 'factory_door' ? -500 : ($i * 400);
                $generalPrice = $general + $bump;
                $specialPrice = (int) round($generalPrice * 0.94);

                ProductPrice::updateOrCreate(
                    [
                        ProductPrice::PRODUCT_VARIANT_ID => $variant->id,
                        ProductPrice::FACTORY_ID => $factory->id,
                        ProductPrice::FACTORY_LOCATION_ID => $location->id,
                    ],
                    [
                        ProductPrice::GENERAL_PRICE => $generalPrice,
                        ProductPrice::SPECIAL_PRICE => $specialPrice,
                        ProductPrice::GOLDEN_FIGURE => $generalPrice - $specialPrice,
                        ProductPrice::LAST_UPDATED_AT => now(),
                    ],
                );

                FactoryStock::updateOrCreate(
                    [
                        'product_variant_id' => $variant->id,
                        'factory_id' => $factory->id,
                        'factory_location_id' => $location->id,
                    ],
                    ['quantity' => $variant->{ProductVariant::STOCK_QUANTITY}],
                );
            }
        }
    }
}
