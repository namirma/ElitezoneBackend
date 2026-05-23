<?php

namespace Database\Factories;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $unitPrice = $this->faker->randomFloat(2, 50_000, 5_000_000);
        $quantity  = $this->faker->randomFloat(2, 1, 100);

        return [
            OrderItem::PRODUCT_NAME      => 'محصول '.$this->faker->word(),
            OrderItem::VARIANT_SKU       => strtoupper($this->faker->bothify('SKU-####??')),
            OrderItem::QUANTITY_DETAILS  => [],   // cast 'array' so an empty array serialises to '[]'
            OrderItem::QUANTITY          => $quantity,
            OrderItem::WEIGHT            => 0,
            OrderItem::UNIT_NAME         => 'عدد',
            OrderItem::UNIT_PRICE        => $unitPrice,
            OrderItem::TOTAL_PRICE       => $unitPrice * $quantity,
            // order_id and supplier_user_id are intentionally NOT defaulted —
            // tests must wire them to a created Order and User.
        ];
    }
}
