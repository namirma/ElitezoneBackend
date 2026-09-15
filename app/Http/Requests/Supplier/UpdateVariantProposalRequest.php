<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier;

use App\Models\ProductVariant;

/** پیشنهادِ ویرایشِ واریانت — همان قواعد، روی `{variant}`ِ روت. */
class UpdateVariantProposalRequest extends VariantProposalRequest
{
    protected function targetVariant(): ?ProductVariant
    {
        $variant = $this->route('variant');

        return $variant instanceof ProductVariant ? $variant : null;
    }
}
