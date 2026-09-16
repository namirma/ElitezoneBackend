<?php

namespace Tests\Feature\Product;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * Pins the product-review contract: user submission (one per user/product,
 * resubmission returns the same record to the pending queue), the public
 * approved-only listing + stats, and the admin moderation queue
 * (approve / reject with mandatory reason ≤ 1000).
 */
class ProductReviewTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function makeProduct(): Product
    {
        $category = ProductCategory::create([
            'name' => 'دسته تست نظرات',
            'slug' => 'review-cat-'.Str::random(8),
        ]);

        return Product::create([
            'cuid' => Str::random(20),
            'category_id' => $category->id,
            'name' => 'محصول تست نظرات',
            'slug' => 'review-product-'.Str::random(8),
            'sku' => 'SKU_'.Str::random(6),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $this->grantAllAdminPermissions($user);
    }

    /**
     * سفارش تحویل‌شده حاوی همین محصول برای کاربر — پیش‌نیاز گیت «خرید تأییدشده».
     */
    private function giveDeliveredPurchase(User $user, Product $product): void
    {
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'واریانت تست نظر',
            'sku' => 'RVSKU_'.Str::random(6),
        ]);

        $order = Order::create([
            Order::PLACER_USER_ID => $user->id,
            Order::ORDER_NUMBER => 'TEST-REVIEW-'.uniqid(),
            Order::STATUS => Order::STATUS_DELIVERED,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::FINAL_AMOUNT => 100_000,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'supplier_user_id' => User::factory()->create()->id,
        ]);
    }

    // ── User submission ───────────────────────────────────────────────────

    #[Test]
    public function guest_cannot_submit_a_review(): void
    {
        $product = $this->makeProduct();

        $this->postJson("/api/products/{$product->cuid}/reviews", ['rating' => 5])
            ->assertUnauthorized();
    }

    #[Test]
    public function user_without_delivered_purchase_cannot_review(): void
    {
        $product = $this->makeProduct();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/products/{$product->cuid}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    #[Test]
    public function rating_is_required_and_bounded_1_to_5(): void
    {
        $product = $this->makeProduct();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/products/{$product->cuid}/reviews", [])->assertUnprocessable();
        $this->postJson("/api/products/{$product->cuid}/reviews", ['rating' => 0])->assertUnprocessable();
        $this->postJson("/api/products/{$product->cuid}/reviews", ['rating' => 6])->assertUnprocessable();
    }

    #[Test]
    public function submission_creates_a_pending_review(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create();
        $this->giveDeliveredPurchase($user, $product);
        Sanctum::actingAs($user);

        $this->postJson("/api/products/{$product->cuid}/reviews", [
            'rating' => 4,
            'comment' => 'کیفیت خوب بود.',
        ])->assertCreated();

        $this->assertDatabaseHas('product_reviews', [
            ProductReview::USER_ID => $user->id,
            ProductReview::PRODUCT_ID => $product->id,
            ProductReview::RATING => 4,
            ProductReview::STATUS => ProductReview::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function resubmission_updates_the_same_record_and_resets_it_to_pending(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create();
        $this->giveDeliveredPurchase($user, $product);
        Sanctum::actingAs($user);

        $this->postJson("/api/products/{$product->cuid}/reviews", ['rating' => 2])->assertCreated();

        $review = ProductReview::where(ProductReview::USER_ID, $user->id)->first();
        $review->forceFill([
            ProductReview::STATUS => ProductReview::STATUS_REJECTED,
            ProductReview::REJECTION_REASON => 'متن نامناسب',
        ])->save();

        $this->postJson("/api/products/{$product->cuid}/reviews", ['rating' => 5])->assertCreated();

        $this->assertSame(1, ProductReview::where(ProductReview::USER_ID, $user->id)->count());

        $review->refresh();
        $this->assertSame(5, $review->rating);
        $this->assertSame(ProductReview::STATUS_PENDING, $review->status);
        $this->assertNull($review->rejection_reason);
    }

    // ── Public listing ────────────────────────────────────────────────────

    #[Test]
    public function public_listing_returns_only_approved_reviews_with_stats(): void
    {
        $product = $this->makeProduct();
        [$u1, $u2, $u3] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];

        foreach ([[$u1, 5, ProductReview::STATUS_APPROVED],
            [$u2, 3, ProductReview::STATUS_APPROVED],
            [$u3, 1, ProductReview::STATUS_PENDING]] as [$user, $rating, $status]) {
            ProductReview::create([
                ProductReview::USER_ID => $user->id,
                ProductReview::PRODUCT_ID => $product->id,
                ProductReview::RATING => $rating,
            ])->forceFill([ProductReview::STATUS => $status])->save();
        }

        $response = $this->getJson("/api/products/{$product->cuid}/reviews")->assertOk();

        $data = $response->json('data');
        $this->assertSame(2, $data['stats']['count']);
        $this->assertSame(4.0, (float) $data['stats']['average_rating']);
        $this->assertCount(2, $data['reviews']);
    }

    #[Test]
    public function public_listing_masks_the_reviewer_mobile_but_admin_sees_it(): void
    {
        // endpointِ عمومی بدونِ احراز است؛ قبلاً موبایلِ خامِ نویسنده را به هر کسی می‌داد.
        $product = $this->makeProduct();
        $author = User::factory()->create([User::MOBILE => '09123456789']);
        $review = ProductReview::create([
            ProductReview::USER_ID => $author->id,
            ProductReview::PRODUCT_ID => $product->id,
            ProductReview::RATING => 5,
        ]);
        $review->forceFill([ProductReview::STATUS => ProductReview::STATUS_APPROVED])->save();

        $this->getJson("/api/products/{$product->cuid}/reviews")
            ->assertOk()
            ->assertJsonPath('data.reviews.0.user.mobile', '0912****789');

        Sanctum::actingAs($this->admin());
        $this->getJson("/api/admin/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.user.mobile', '09123456789');
    }

    // ── My reviews ────────────────────────────────────────────────────────

    #[Test]
    public function my_reviews_lists_only_own_reviews_including_rejected(): void
    {
        $product = $this->makeProduct();
        $me = User::factory()->create();
        $other = User::factory()->create();

        ProductReview::create([
            ProductReview::USER_ID => $me->id,
            ProductReview::PRODUCT_ID => $product->id,
            ProductReview::RATING => 2,
        ])->forceFill([
            ProductReview::STATUS => ProductReview::STATUS_REJECTED,
            ProductReview::REJECTION_REASON => 'دلیل تست',
        ])->save();

        ProductReview::create([
            ProductReview::USER_ID => $other->id,
            ProductReview::PRODUCT_ID => $product->id,
            ProductReview::RATING => 5,
        ]);

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/profile/reviews')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('دلیل تست', $response->json('data.0.rejection_reason'));
    }

    // ── Admin moderation ──────────────────────────────────────────────────

    #[Test]
    public function non_admin_is_forbidden_on_moderation_queue(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/reviews')->assertForbidden();
    }

    #[Test]
    public function admin_can_approve_a_review(): void
    {
        $product = $this->makeProduct();
        $review = ProductReview::create([
            ProductReview::USER_ID => User::factory()->create()->id,
            ProductReview::PRODUCT_ID => $product->id,
            ProductReview::RATING => 4,
        ]);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/reviews/{$review->id}/approve")->assertOk();

        $this->assertSame(ProductReview::STATUS_APPROVED, $review->refresh()->status);
    }

    #[Test]
    public function admin_reject_requires_a_reason(): void
    {
        $product = $this->makeProduct();
        $review = ProductReview::create([
            ProductReview::USER_ID => User::factory()->create()->id,
            ProductReview::PRODUCT_ID => $product->id,
            ProductReview::RATING => 4,
        ]);

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/reviews/{$review->id}/reject", [])->assertUnprocessable();

        $this->postJson("/api/admin/reviews/{$review->id}/reject", [
            'rejection_reason' => 'ناسازگار با قوانین',
        ])->assertOk();

        $review->refresh();
        $this->assertSame(ProductReview::STATUS_REJECTED, $review->status);
        $this->assertSame('ناسازگار با قوانین', $review->rejection_reason);
    }
}
