<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * یک مدل نمی‌تواند هم `HasFactory` داشته باشد و هم ریلیشنی به نامِ `factory()`.
 *
 * متدِ خودِ کلاس همیشه بر متدِ trait مقدم است، پس `factory()` از یک متدِ **استاتیکِ**
 * کارخانه به یک ریلیشنِ نمونه‌ای تبدیل می‌شود و `Model::factory()` با
 * «Non-static method cannot be called statically» فتال می‌دهد. `CLAUDE.md` این را
 * ثبت کرده — «هرگز ریلیشنی را `factory()` نام نگذارید، یک بار ۲۵ تست را شکست» — و
 * `OrderItem`/`Shipment` به همین دلیل `originFactory()` نام دارند.
 *
 * ولی قاعده فقط روی همان دو مدل اعمال شد. پنج مدلِ دیگر امروز همین تصادم را
 * دارند: `CartItem`, `FactoryFeature`, `FactoryLocation`, `PriceHistory`,
 * `ProductPrice`.
 *
 * **این تست عمداً آن پنج را سرخ نمی‌کند.** تصادم تا وقتی بی‌اثر است که کسی
 * `Model::factory()` صدا نزند، و برای هیچ‌کدامشان کلاسِ کارخانه‌ای وجود ندارد.
 * ریلیشن در ~۲۰ نقطه (از جمله eager-loadهای رشته‌ای) استفاده می‌شود، پس تغییرِ
 * نامش یک ریفکتورِ جداست، نه چیزی که لای یک تغییرِ دیگر انجام شود.
 *
 * کاری که این تست می‌کند: لحظه‌ای که تصادم از «نهفته» به «زنده» تبدیل شود — یعنی
 * کسی برای یکی از این مدل‌ها کارخانه بسازد — با پیامی که دقیقاً می‌گوید چه شده
 * سرخ می‌شود. همان الگوی `AdminPermissionCoverageTest`: جاروی کلِ سطح، نه نمونه.
 */
class FactoryMethodCollisionTest extends TestCase
{
    /**
     * مدل‌هایی که امروز تصادم دارند و کارخانه ندارند، پس بی‌اثرند.
     *
     * افزودن به این فهرست **راهِ حل نیست** — ریلیشن را `originFactory()` کنید.
     *
     * @var list<string>
     */
    private const KNOWN_LATENT = [
        \App\Models\CartItem::class,
        \App\Models\FactoryFeature::class,
        \App\Models\FactoryLocation::class,
        \App\Models\PriceHistory::class,
        \App\Models\ProductPrice::class,
    ];

    /** @return list<class-string> هر مدلی که `HasFactory` را به کار می‌برد. */
    private function modelsUsingHasFactory(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            /** @var SplFileInfo $file */
            $class = 'App\\Models\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            );

            if (! class_exists($class)) {
                continue;
            }

            if (in_array(HasFactory::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /** آیا `factory()` روی این کلاس نمونه‌ای است (یعنی ریلیشن، نه کارخانه)؟ */
    private function shadowsFactory(string $model): bool
    {
        if (! method_exists($model, 'factory')) {
            return false;
        }

        return ! (new ReflectionMethod($model, 'factory'))->isStatic();
    }

    #[Test]
    public function no_model_both_shadows_factory_and_has_a_factory_class(): void
    {
        $live = [];
        $checked = 0;

        foreach ($this->modelsUsingHasFactory() as $model) {
            $checked++;

            if (! $this->shadowsFactory($model)) {
                continue;
            }

            // تصادم هست. زنده است یا نهفته؟ زنده یعنی کسی کارخانه‌اش را ساخته.
            $factoryClass = 'Database\\Factories\\'.(new ReflectionClass($model))->getShortName().'Factory';

            if (class_exists($factoryClass)) {
                $live[] = $model.'  ←  '.$factoryClass.' وجود دارد، ولی factory() یک ریلیشن است';
            }
        }

        $this->assertGreaterThan(
            20,
            $checked,
            'جارو به مدل‌ها نرسید — مسیر app/Models عوض شده؟'
        );

        $this->assertSame(
            [],
            $live,
            'این مدل‌ها `HasFactory` دارند ولی `factory()` را با یک ریلیشن پوشانده‌اند، '.
            "و کلاسِ کارخانه‌شان هم ساخته شده — پس `Model::factory()` فتال می‌دهد.\n".
            "راهِ حل: ریلیشن را `originFactory()` نام‌گذاری کنید (مثلِ OrderItem/Shipment).\n  ".
            implode("\n  ", $live)
        );
    }

    #[Test]
    public function the_known_latent_collisions_have_not_silently_spread(): void
    {
        $shadowing = array_values(array_filter(
            $this->modelsUsingHasFactory(),
            fn (string $model): bool => $this->shadowsFactory($model)
        ));

        $unexpected = array_diff($shadowing, self::KNOWN_LATENT);

        $this->assertSame(
            [],
            array_values($unexpected),
            'مدلِ تازه‌ای ریلیشنی به نامِ `factory()` گرفته است. این متدِ استاتیکِ '.
            "`HasFactory::factory()` را می‌پوشانَد و `Model::factory()` را فتال می‌کند.\n".
            "نامش را `originFactory()` بگذارید — به KNOWN_LATENT اضافه نکنید.\n  ".
            implode("\n  ", $unexpected)
        );
    }
}
