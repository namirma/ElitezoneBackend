<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;

class ProductFilterData
{
    public function __construct(
        public readonly ?string $search,
        public readonly ?int $categoryId,
        public readonly ?string $sortKey,
        public readonly ?string $roleCode,
        public readonly ?array $filterCategories,
        public readonly bool $debugPrices = false,
    ) {}

    /**
     * متد کارخانه (Factory Method) برای ساخت DTO از ریکوئست
     */
    public static function fromRequest(Request $request, string $activeRoleCode): self
    {
        return new self(
            search: $request->input('search'),
            categoryId: $request->input('category'),
            sortKey: $request->input('sort', 'newest'),
            roleCode: $activeRoleCode,
            filterCategories: $request->input('filter_categories'),
            debugPrices: $request->boolean('debug_prices')
        );
    }

    /**
     * تبدیل به آرایه (اگر جایی نیاز شد)
     */
    public function toArray(): array
    {
        return array_filter([
            'search'            => $this->search,
            'category'          => $this->categoryId,
            'sort'              => $this->sortKey,
            'active_code'       => $this->roleCode,
            'filter_categories' => $this->filterCategories,
        ], fn($value) => !is_null($value));
    }
}
