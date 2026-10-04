<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Support\OrderDocumentCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * هر لینکِ سندی که تایم‌لاینِ سفارش می‌سازد باید به یک سندِ واقعیِ کاتالوگ برسد.
 *
 * روتِ دانلود (`/profile/orders/{order}/invoice/{type}`) فقط اسلاگ‌های
 * `OrderDocumentCatalog::downloadableSlugs()` را می‌پذیرد. تایم‌لاین سه اسلاگِ ناموجود
 * می‌ساخت و هر سه ۴۰۴ می‌دادند: `tax_buyer` (فاکتورِ مودیانِ خریدار، سندِ P — در کاتالوگ
 * `tax`)، `loading_order` (دستورِ بارگیری، سندِ C — `loading`) و `exit_permit` (بارنامه /
 * مجوزِ خروج، سندِ K — `bill_of_lading`).
 */
class TimelineDocumentLinksResolveTest extends TestCase
{
    #[Test]
    public function every_timeline_document_link_names_a_downloadable_document(): void
    {
        $source = file_get_contents(app_path('Models/Concerns/HasOrderProgress.php'));
        preg_match_all('#/invoice/([a-z_]+)#', $source, $m);

        $this->assertNotEmpty($m[1], 'no document links found — did the timeline move?');

        $allowed = OrderDocumentCatalog::downloadableSlugs();
        foreach (array_unique($m[1]) as $slug) {
            $this->assertContains($slug, $allowed, "timeline links /invoice/{$slug}, which the download route rejects with 404");
        }
    }
}
