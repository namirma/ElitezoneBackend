<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use App\Models\Product;

/** پیشنهادِ ویرایشِ محصول — همان قواعد، روی `{product}`ِ روت. */
class UpdateProductProposalRequest extends ProductProposalRequest
{
    protected function targetProduct(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
