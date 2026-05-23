<?php

namespace App\DataTransferObjects\Product;

use Illuminate\Http\Request;

class ProductFilterData
{
    public function __construct(
        public readonly ?string $search,
        public readonly ?int $categoryId,
        public readonly ?string $sortKey,
        public readonly ?string $roleCode,
        public readonly ?array $filterCategories,
        public readonly bool $debugPrices,
        public readonly int $perPage,
        public readonly ?string $availability,
        public readonly bool $withVat
    ) {}

    public static function fromRequest(Request $request, string $activeRoleCode): self
    {
        return new self(
            search: $request->input('search'),
            categoryId: $request->integer('category') ?: null,
            sortKey: $request->input('sort', 'newest'),
            roleCode: $activeRoleCode,
            filterCategories: $request->input('filter_categories'),
            debugPrices: $request->boolean('debug_prices'),
            perPage: $request->integer('per_page', 12),
            availability: $request->input('availability', 'all'),
            withVat: $request->boolean('with_vat', true)
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'search' => $this->search,
            'category' => $this->categoryId,
            'sort' => $this->sortKey,
            'role_code' => $this->roleCode,
            'filter_categories' => $this->filterCategories,
            'availability' => $this->availability,
            'with_vat' => $this->withVat,
        ], fn ($value) => ! is_null($value));
    }
}
