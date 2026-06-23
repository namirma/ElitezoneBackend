<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $province_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereUserId($value)
 */
	class ActivityProvince extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property int|null $province_id
 * @property int|null $city_id
 * @property string $postal_address
 * @property string|null $building_number
 * @property string|null $unit
 * @property string|null $postal_code
 * @property string $receiver_name
 * @property string $receiver_mobile
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ProvinceCity|null $city
 * @property-read \App\Models\ActivityProvince|null $province
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereBuildingNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address wherePostalAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address wherePostalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereReceiverMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereReceiverName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereUserId($value)
 */
	class Address extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $slug
 * @property string $version
 * @property string|null $content
 * @property bool $is_active
 * @property bool $is_mandatory
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereIsMandatory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Agreement whereVersion($value)
 */
	class Agreement extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $attribute_type_id
 * @property string $name
 * @property string $display_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\AttributeType $attributeType
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductVariant> $productVariants
 * @property-read int|null $product_variants_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AttributeValue> $values
 * @property-read int|null $values_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereAttributeTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attribute whereUpdatedAt($value)
 */
	class Attribute extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string $input_type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attribute> $attributes
 * @property-read int|null $attributes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductCategory> $categories
 * @property-read int|null $categories_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType whereInputType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeType whereUpdatedAt($value)
 */
	class AttributeType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property string $display_value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Attribute $attribute
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductVariant> $productVariants
 * @property-read int|null $product_variants_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereDisplayValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AttributeValue whereValue($value)
 */
	class AttributeValue extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $icon
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Cart> $carts
 * @property-read int|null $carts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceAdjustment> $priceAdjustments
 * @property-read int|null $price_adjustments_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Badge whereUpdatedAt($value)
 */
	class Badge extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property int|null $customer_type_id
 * @property int|null $badge_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Badge|null $badge
 * @property-read \App\Models\CustomerType|null $customerType
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CartItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\CartPriceFilterCategory|null $pivot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceFilterCategory> $priceFilterCategories
 * @property-read int|null $price_filter_categories_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseParameterValue> $purchaseParameters
 * @property-read int|null $purchase_parameters_count
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereCustomerTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cart whereUserId($value)
 */
	class Cart extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $cart_id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property int $product_variant_id
 * @property string $action_type
 * @property numeric $quantity_change
 * @property numeric|null $unit_price_snapshot
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereActionType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereQuantityChange($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereUnitPriceSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartEvent whereUserId($value)
 */
	class CartEvent extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_variant_id
 * @property int $factory_id
 * @property int $factory_location_id
 * @property int|null $address_id
 * @property int $shipping_method_id
 * @property array<array-key, mixed> $quantities
 * @property numeric $weight Total logistics weight in KG
 * @property numeric $quantity Total quantity count
 * @property string|null $unit_name Snapshot of the unit name (e.g., Bundle, Branch)
 * @property numeric $price
 * @property numeric $final_price
 * @property numeric $vat_amount
 * @property numeric $total_with_vat
 * @property bool $with_vat
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\Cart $cart
 * @property-read \App\Models\Factory $factory
 * @property-read \App\Models\FactoryLocation $factoryLocation
 * @property-read \App\Models\ProductVariant $productVariant
 * @property-read \App\Models\ShippingMethod $shippingMethod
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereQuantities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereShippingMethodId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereTotalWithVat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereUnitName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereVatAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereWeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartItem whereWithVat($value)
 */
	class CartItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $cart_id
 * @property int $price_filter_category_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory wherePriceFilterCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CartPriceFilterCategory whereUpdatedAt($value)
 */
	class CartPriceFilterCategory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $category_id
 * @property int $attribute_type_id
 * @property bool $is_required
 * @property bool $is_filterable
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\AttributeType $attributeType
 * @property-read \App\Models\ProductCategory $category
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereAttributeTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereIsFilterable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereIsRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoryAttribute whereUpdatedAt($value)
 */
	class CategoryAttribute extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @property int $id
 * @property int $user_id
 * @property int $is_verified
 * @property string|null $verified_at
 * @property int $engineer_member
 * @property string|null $company_name
 * @property int $not_related
 * @property string|null $license_number شماره پروانه اشتغال
 * @property string|null $supervision_base پایه نظارت
 * @property string|null $design_base پایه طراحی
 * @property int|null $activity_history سابقه فعالیت
 * @property string|null $activity_provinces
 * @property string|null $project_done
 * @property string|null $project_in_progress
 * @property int|null $trusted_contractor
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereActivityHistory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereCompanyName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereDesignBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereEngineerMember($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereLicenseNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereProjectDone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereProjectInProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereSupervisionBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereTrustedContractor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorProfile whereVerifiedAt($value)
 */
	class ContractorProfile extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code
 * @property string $type
 * @property numeric $amount
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $owner
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Coupon whereUserId($value)
 */
	class Coupon extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $recency_days
 * @property int $frequency_count
 * @property numeric $monetary_total
 * @property int $r_score
 * @property int $f_score
 * @property int $m_score
 * @property string|null $segment
 * @property \Illuminate\Support\Carbon $last_calculated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereFScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereFrequencyCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereLastCalculatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereMScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereMonetaryTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereRScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereRecencyDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereSegment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerStat whereUserId($value)
 */
	class CustomerStat extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $has_consultant
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Cart> $carts
 * @property-read int|null $carts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceAdjustment> $priceAdjustments
 * @property-read int|null $price_adjustments_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereHasConsultant($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerType whereUpdatedAt($value)
 */
	class CustomerType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_id
 * @property string|null $description
 * @property int $is_available_for_ticket
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Ticket> $tickets
 * @property-read int|null $tickets_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department isAvailableForTicket()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereIsAvailableForTicket($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Department whereSlug($value)
 */
	class Department extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $user_id
 * @property string $national_code
 * @property string $trace_code
 * @property string $name
 * @property string $family
 * @property string|null $father_name
 * @property string|null $id_number
 * @property string|null $birth_date
 * @property string|null $university
 * @property string|null $field
 * @property string|null $course_type
 * @property string|null $entry_date
 * @property string|null $graduate_date
 * @property string|null $gpa
 * @property string|null $status
 * @property string $admin_status
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereAdminStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereCourseType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereEntryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereFamily($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereFatherName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereField($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereGpa($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereGraduateDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereIdNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereTraceCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereUniversity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EduixRecord whereUserId($value)
 */
	class EduixRecord extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $employer_id
 * @property int $consultant_id
 * @property string $status
 * @property string|null $reject_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant whereConsultantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant whereEmployerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant whereRejectReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerConsultant whereUpdatedAt($value)
 */
	class EmployerConsultant extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @property int $id
 * @property int $user_id
 * @property int $has_consultant
 * @property string $status
 * @property string|null $project_done
 * @property string|null $project_in_progress
 * @property string|null $activity_provinces
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereHasConsultant($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereProjectDone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereProjectInProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerProfile whereUserId($value)
 */
	class EmployerProfile extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $advisor_id
 * @property string $mobile
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $advisor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite whereAdvisorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite whereSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmployerSmsInvite whereUpdatedAt($value)
 */
	class EmployerSmsInvite extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static create(array $array)
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @property int $id
 * @property int $user_id
 * @property int $is_verified
 * @property string|null $verified_at
 * @property int $engineer_member
 * @property bool $is_saze90_user
 * @property string|null $license_number شماره پروانه اشتغال
 * @property string|null $supervision_base پایه نظارت
 * @property string|null $design_base پایه طراحی
 * @property string|null $member_number شماره عضویت
 * @property string|null $fields_of_study رشته مهندسی
 * @property int $student
 * @property int|null $province_id
 * @property int|null $activity_province_id
 * @property int|null $city_id
 * @property int|null $activity_city_id
 * @property string|null $university_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereActivityCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereActivityProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereDesignBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereEngineerMember($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereFieldsOfStudy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereIsSaze90User($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereLicenseNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereMemberNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereStudent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereSupervisionBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereUniversityName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EngineerProfile whereVerifiedAt($value)
 */
	class EngineerProfile extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $supplier_user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $logo
 * @property string|null $api_key
 * @property string|null $api_url
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\FactoryLocation> $locations
 * @property-read int|null $locations_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductPrice> $prices
 * @property-read int|null $prices_count
 * @property-read \App\Models\User|null $supplier
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereApiUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereSupplierUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Factory whereUpdatedAt($value)
 */
	class Factory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $factory_id
 * @property string $name
 * @property string $type
 * @property string|null $city
 * @property string|null $address
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Factory $factory
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductPrice> $prices
 * @property-read int|null $prices_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FactoryLocation whereUpdatedAt($value)
 */
	class FactoryLocation extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $current_stock
 * @property numeric $sales_velocity_30d
 * @property int $search_volume_7d
 * @property numeric $predicted_demand_next_7d
 * @property numeric $recommended_restock_amount
 * @property string $priority
 * @property string|null $reason
 * @property string $calculated_at
 * @property-read \App\Models\ProductVariant $variant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereCalculatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereCurrentStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction wherePredictedDemandNext7d($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereRecommendedRestockAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereSalesVelocity30d($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryPrediction whereSearchVolume7d($value)
 */
	class InventoryPrediction extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property string $document_code کد سند طبق FinancialCodes مثل LO, G, A
 * @property string $invoice_number شماره سریال تولید شده سیستم
 * @property numeric $amount مبلغ فاکتور/رسید
 * @property string|null $file_path مسیر فایل PDF ذخیره شده
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereDocumentCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereFilePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereInvoiceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereUserId($value)
 */
	class Invoice extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $organization_name
 * @property string|null $project_address
 * @property string $file_path
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property string $status
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereFilePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereOrganizationName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereProjectAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JobExperience whereUserId($value)
 */
	class JobExperience extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static create(array $array)
 * @method static updateOrCreate(array $array, array $array1)
 * @property int $id
 * @property string $project_code
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ListopherItem> $items
 * @property-read int|null $items_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher whereProjectCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Listopher whereUpdatedAt($value)
 */
	class Listopher extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $listopher_id
 * @property string $pos
 * @property string $shape
 * @property numeric $length
 * @property string $size
 * @property numeric|null $hook
 * @property numeric|null $overlap
 * @property int $quantity
 * @property numeric|null $length_total
 * @property numeric|null $b
 * @property numeric|null $h
 * @property-read \App\Models\Listopher $listopher
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereB($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereH($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereHook($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereLength($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereLengthTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereListopherId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereOverlap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem wherePos($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereShape($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ListopherItem whereSize($value)
 */
	class ListopherItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string|null $code
 * @property string $type
 * @property numeric|null $value
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property int $minimum_quantity
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer current()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereMinimumQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Offer whereValue($value)
 */
	class Offer extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $placer_user_id
 * @property int|null $beneficiary_user_id
 * @property int|null $consultant_user_id مشاور فنی که کمیسیون CO را دریافت می‌کند
 * @property int|null $address_id
 * @property string $order_number
 * @property string $status
 * @property numeric $subtotal
 * @property numeric $discount_amount
 * @property numeric $shipping_cost
 * @property numeric $vat_amount
 * @property numeric $final_amount
 * @property string|null $payment_method
 * @property string $payment_status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $weighbridge_registered_at
 * @property string|null $initial_invoice_ref
 * @property string|null $final_invoice_ref
 * @property string|null $tax_invoice_ref
 * @property numeric|null $expert_fee_amount
 * @property string|null $expert_fee_payment_status
 * @property numeric|null $engineer_fee_amount
 * @property string|null $engineer_fee_payment_status
 * @property numeric|null $contractor_fee_amount
 * @property string|null $contractor_fee_payment_status
 * @property string|null $expert_fee_invoice_ref
 * @property string|null $cancel_reason
 * @property string|null $survey_status
 * @property \Illuminate\Support\Carbon|null $prepared_at
 * @property \Illuminate\Support\Carbon|null $shipped_at
 * @property string|null $payment_receipt_ref
 * @property string|null $espadana_order_ref
 * @property string|null $waybill_ref
 * @property \Illuminate\Support\Carbon|null $reservation_expires_at تاریخ انقضای رزرو (2 ساعته یا تمدید شده)
 * @property numeric|null $partial_payment_amount مبلغ پرداخت شده برای تمدید رزرو
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property numeric|null $official_price OP: قیمت رسمی محصول
 * @property numeric|null $weight_before_scale WBS: وزن قبل از باسکول
 * @property numeric $commission_rate %TC: درصد کمیسیون
 * @property numeric $owner_discount_rate %OW: درصد تخفیف خریدار
 * @property numeric $saze_share_rate %SSG: درصد سهم سازه
 * @property numeric $consultant_share_rate %CO: درصد سهم مشاور
 * @property bool $trans_commission +%TC snapshot: کمیسیون روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)
 * @property bool $trans_vat +VAT snapshot: مالیات روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)
 * @property numeric $vat_rate %VAT: درصد ارزش افزوده
 * @property numeric|null $payment_of_owner POO: پرداختی خریدار
 * @property numeric|null $payment_to_supplier PTS: پرداختی به سوپلایر
 * @property numeric|null $payment_to_consultant PTC: پرداختی به مشاور
 * @property numeric|null $payment_to_reserve PTR: مبلغ پرداخت رزرو
 * @property \Illuminate\Support\Carbon|null $validation_of_reserve VOR: تاریخ اعتبار رزرو
 * @property numeric|null $weight_after_scale WAS: وزن باسکول
 * @property \Illuminate\Support\Carbon|null $weighing_completed_at
 * @property string|null $consultant_moadian_ref Doc Q: شماره فاکتور مودیان صادر شده توسط مشاور فنی
 * @property \Illuminate\Support\Carbon|null $consultant_hold_released_at زمان آزادسازی هولد کمیسیون مشاور
 * @property string|null $supplier_moadian_ref Doc O: شماره فاکتور مودیان صادر شده توسط سوپلایر
 * @property \Illuminate\Support\Carbon|null $supplier_hold_released_at زمان آزادسازی هولد مالیات سوپلایر در WVAT
 * @property bool $supplier_debt_pending آیا سوپلایر بدهی باسکول دارد که هنوز تسویه نشده؟
 * @property numeric $supplier_debt_amount مبلغ بدهی سوپلایر بابت کسری باسکول (ریال)
 * @property string|null $system_moadian_ref Doc P: شماره فاکتور مودیان صادر شده توسط سازه (پلتفرم) برای خریدار
 * @property \Illuminate\Support\Carbon|null $system_hold_released_at زمان آزادسازی هولد مالیات پلتفرم (sazeVat) از WVAT
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Activitylog\Models\Activity> $activities
 * @property-read int|null $activities_count
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\User|null $beneficiary
 * @property-read \App\Models\User|null $consultant
 * @property-read array $consultant_progress
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read array $dashboard_progress
 * @property-read string|void $expire_at_days
 * @property-read array $supplier_progress
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User $placer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Shipment> $shipments
 * @property-read int|null $shipments_count
 * @property-read \App\Models\ShippingMethod|null $shippingMethod
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderSurvey> $surveys
 * @property-read int|null $surveys_count
 * @method static \App\Models\Builders\OrderBuilder<static>|Order cancelled()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order current()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order delivered()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order expired()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order forSupplier(int $supplierId)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order forUser(int $userId)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order newModelQuery()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order newQuery()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order paid()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order query()
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereAddressId($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereBeneficiaryUserId($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereCancelReason($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereCommissionRate($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereConsultantHoldReleasedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereConsultantMoadianRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereConsultantShareRate($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereConsultantUserId($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereContractorFeeAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereContractorFeePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereCreatedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereDiscountAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereEngineerFeeAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereEngineerFeePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereEspadanaOrderRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereExpertFeeAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereExpertFeeInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereExpertFeePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereFinalAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereFinalInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereId($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereInitialInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereNotes($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereOfficialPrice($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereOrderNumber($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereOwnerDiscountRate($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaidAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePartialPaymentAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaymentMethod($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaymentOfOwner($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaymentReceiptRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaymentToConsultant($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaymentToReserve($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePaymentToSupplier($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePlacerUserId($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order wherePreparedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereReservationExpiresAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSazeShareRate($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereShippedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereShippingCost($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereStatus($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSubtotal($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSupplierDebtAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSupplierDebtPending($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSupplierHoldReleasedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSupplierMoadianRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSurveyStatus($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSystemHoldReleasedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereSystemMoadianRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereTaxInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereTransCommission($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereTransVat($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereUpdatedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereValidationOfReserve($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereVatAmount($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereVatRate($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereWaybillRef($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereWeighbridgeRegisteredAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereWeighingCompletedAt($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereWeightAfterScale($value)
 * @method static \App\Models\Builders\OrderBuilder<static>|Order whereWeightBeforeScale($value)
 */
	class Order extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $order_id
 * @property int|null $shipment_id
 * @property int|null $product_variant_id
 * @property int|null $supplier_user_id User ID of the supplier fulfilling this item
 * @property int|null $factory_id
 * @property int|null $factory_location_id
 * @property string $product_name
 * @property string|null $variant_sku
 * @property array<array-key, mixed> $quantity_details
 * @property numeric $weight Total logistics weight in KG (Required for Weighbridge)
 * @property numeric $quantity Sales quantity (Count, Area, Volume, etc.)
 * @property string|null $unit_name Snapshot of the unit name (e.g., Bundle, M2, Branch)
 * @property numeric $unit_price
 * @property numeric $total_price
 * @property numeric $shipping_cost
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Factory|null $originFactory
 * @property-read \App\Models\FactoryLocation|null $originFactoryLocation
 * @property-read \App\Models\ProductVariant|null $productVariant
 * @property-read \App\Models\Shipment|null $shipment
 * @property-read \App\Models\User|null $supplier
 * @method static \Database\Factories\OrderItemFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem sumQuantity()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereProductName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereQuantityDetails($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereShipmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereShippingCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereSupplierUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereTotalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereUnitName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereVariantSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereWeight($value)
 */
	class OrderItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $user_id
 * @property int|null $duration_minutes
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereDurationMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereToStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereUserId($value)
 */
	class OrderStatusHistory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderSurvey whereUserId($value)
 */
	class OrderSurvey extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $unit
 * @property numeric|null $amount
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderUnit whereUpdatedAt($value)
 */
	class OrderUnit extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $display_name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereUpdatedAt($value)
 */
	class Permission extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $doc_number
 * @property string|null $shenaseh_variz
 * @property string|null $sender_iban شبای فرستنده از ItemSystemMetaData پادیوم
 * @property string|null $sender_name نام فرستنده از ItemSystemMetaData پادیوم
 * @property string|null $national_code
 * @property int $creditor_amount
 * @property int|null $user_id
 * @property int|null $wallet_txn_id
 * @property string|null $matched_by روش تطبیق: shenaseh | iban | null
 * @property string|null $deposited_at_jalali
 * @property Carbon|null $processed_at
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\WalletTransaction|null $walletTransaction
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereCreditorAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereDepositedAtJalali($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereDocNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereMatchedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereSenderIban($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereSenderName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereShenasehVariz($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PodiumDepositRecord whereWalletTxnId($value)
 */
	class PodiumDepositRecord extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $customer_type_id
 * @property int|null $badge_id
 * @property float $adjustment_amount
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Badge|null $badge
 * @property-read \App\Models\CustomerType $customerType
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment whereAdjustmentAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment whereCustomerTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceAdjustment whereUpdatedAt($value)
 */
	class PriceAdjustment extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $price_filter_type_id
 * @property string $name
 * @property string $code
 * @property numeric $amount
 * @property bool $is_percentage
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceFilterCategory> $categories
 * @property-read int|null $categories_count
 * @property-read \App\Models\PriceFilterType $filterType
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereIsPercentage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter wherePriceFilterTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilter whereUpdatedAt($value)
 */
	class PriceFilter extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceFilter> $filters
 * @property-read int|null $filters_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterCategory whereUpdatedAt($value)
 */
	class PriceFilterCategory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $apply_on
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceFilter> $filters
 * @property-read int|null $filters_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType forGeneral()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType forSpecial()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereApplyOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceFilterType whereUpdatedAt($value)
 */
	class PriceFilterType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_variant_id
 * @property int|null $product_price_id
 * @property int|null $factory_id
 * @property int|null $factory_location_id
 * @property float|null $general_price
 * @property float|null $special_price
 * @property float|null $golden_figure
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Factory|null $factory
 * @property-read \App\Models\ProductPrice|null $productPrice
 * @property-read \App\Models\ProductVariant $productVariant
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereGeneralPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereGoldenFigure($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereProductPriceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereSpecialPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceHistory whereUserId($value)
 */
	class PriceHistory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $value
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PriceSetting whereValue($value)
 */
	class PriceSetting extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $cuid
 * @property int $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $shipping_promo
 * @property string $sku
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $view_count
 * @property int $items_per_page
 * @property numeric|null $aggregate_min_order_value
 * @property numeric|null $aggregate_max_order_value
 * @property int|null $aggregate_limit_order_unit_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\OrderUnit|null $aggregateLimitOrderUnit
 * @property-read \App\Models\ProductCategory $category
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductImage> $images
 * @property-read int|null $images_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Offer> $offers
 * @property-read int|null $offers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderUnit> $orderUnits
 * @property-read int|null $order_units_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductPrice> $variantPrices
 * @property-read int|null $variant_prices_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductVariant> $variants
 * @property-read int|null $variants_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductVideo> $videos
 * @property-read int|null $videos_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product featured()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereAggregateLimitOrderUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereAggregateMaxOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereAggregateMinOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereIsFeatured($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereItemsPerPage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereShippingPromo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereViewCount($value)
 */
	class Product extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AttributeType> $attributeTypes
 * @property-read int|null $attribute_types_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductCategory whereUpdatedAt($value)
 */
	class ProductCategory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property string $image_path
 * @property bool $is_primary
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereImagePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereIsPrimary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductImage whereUpdatedAt($value)
 */
	class ProductImage extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $factory_id
 * @property int $factory_location_id
 * @property numeric $general_price
 * @property numeric $special_price
 * @property numeric $golden_figure
 * @property \Illuminate\Support\Carbon $last_updated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Factory $factory
 * @property-read \App\Models\FactoryLocation $factoryLocation
 * @property-read \App\Models\ProductVariant $productVariant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereGeneralPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereGoldenFigure($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereLastUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereSpecialPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPrice whereUpdatedAt($value)
 */
	class ProductPrice extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property int $product_variant_id
 * @property string $role_code
 * @property numeric $final_price
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\ProductVariant $variant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereRoleCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductPriceCache whereUpdatedAt($value)
 */
	class ProductPriceCache extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string $sku
 * @property bool $is_active
 * @property int $stock_quantity
 * @property int $view_count
 * @property numeric|null $discount_value
 * @property string|null $discount_type
 * @property \Illuminate\Support\Carbon|null $discount_start_date
 * @property \Illuminate\Support\Carbon|null $discount_end_date
 * @property numeric|null $min_order_value
 * @property numeric|null $max_order_value
 * @property int|null $limit_order_unit_id
 * @property array<array-key, mixed>|null $images
 * @property array<array-key, mixed>|null $videos
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CartItem> $cartItems
 * @property-read int|null $cart_items_count
 * @property-read \App\Models\OrderUnit|null $limitOrderUnit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductPrice> $prices
 * @property-read int|null $prices_count
 * @property-read \App\Models\Product $product
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StockSubscription> $stockSubscriptions
 * @property-read int|null $stock_subscriptions_count
 * @property-read \App\Models\ProductVariantAttribute|null $details
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attribute> $variantAttributes
 * @property-read int|null $variant_attributes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Wishlist> $wishlists
 * @property-read int|null $wishlists_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereDiscountValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereImages($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereLimitOrderUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereMaxOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereMinOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereStockQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereVideos($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereViewCount($value)
 */
	class ProductVariant extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $attribute_id
 * @property int $attribute_value_id
 * @property-read \App\Models\Attribute $attribute
 * @property-read \App\Models\AttributeValue $attributeValue
 * @property-read \App\Models\ProductVariant $productVariant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereAttributeValueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariantAttribute whereProductVariantId($value)
 */
	class ProductVariantAttribute extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property string $video_path
 * @property string|null $thumbnail_path
 * @property string|null $title
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereThumbnailPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVideo whereVideoPath($value)
 */
	class ProductVideo extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $session_id
 * @property \Illuminate\Support\Carbon $viewed_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductView whereViewedAt($value)
 */
	class ProductView extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property \Illuminate\Support\Carbon $view_date
 * @property int $views
 * @property int $unique_sessions
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereUniqueSessions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereViewDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductViewDaily whereViews($value)
 */
	class ProductViewDaily extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string $keyword
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereKeyword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProfileSearchHistory whereUserId($value)
 */
	class ProfileSearchHistory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $ProvinceID
 * @property string $Name
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProvinceCity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProvinceCity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProvinceCity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProvinceCity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProvinceCity whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProvinceCity whereProvinceID($value)
 */
	class ProvinceCity extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $order
 * @property int $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseParameterValue> $values
 * @property-read int|null $values_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterGroup whereUpdatedAt($value)
 */
	class PurchaseParameterGroup extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $group_id
 * @property string $name
 * @property string $slug
 * @property string $code
 * @property string|null $description
 * @property int $order
 * @property int $is_active
 * @property int|null $price_filter_category_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PurchaseParameterGroup $group
 * @property-read \App\Models\PriceFilterCategory|null $priceFilterCategory
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue wherePriceFilterCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseParameterValue whereUpdatedAt($value)
 */
	class PurchaseParameterValue extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static create(mixed $validated)
 * @property int $id
 * @property string $name
 * @property string $mobile
 * @property string $email
 * @property string $subject
 * @property string $message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Question whereUpdatedAt($value)
 */
	class Question extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static where(string $string, $type_id)
 * @property int $id
 * @property string $name
 * @property string|null $display_name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 */
	class Role extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $query
 * @property int $results_count
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereQuery($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereResultsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Search whereUserId($value)
 */
	class Search extends \Eloquent {}
}

namespace App\Models{
/**
 * One delivery leg of an order: items from a single origin factory location to a single
 * destination address, with its own freight cost and lifecycle status.
 *
 * @see ShippingCostCalculator
 * @property int $id
 * @property int $order_id
 * @property int|null $factory_id
 * @property int|null $factory_location_id
 * @property int|null $address_id
 * @property int|null $destination_province_id
 * @property int|null $destination_city_id
 * @property numeric $weight
 * @property numeric $billed_count
 * @property \App\Enums\Shipping\ShippingCostBasis $cost_basis
 * @property numeric $shipping_cost
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Address|null $destinationAddress
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Factory|null $originFactory
 * @property-read \App\Models\FactoryLocation|null $originFactoryLocation
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereBilledCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereCostBasis($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereDestinationCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereDestinationProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereShippingCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereWeight($value)
 */
	class Shipment extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $delivery_notice
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CartItem> $cartItems
 * @property-read int|null $cart_items_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod whereDeliveryNotice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingMethod whereUpdatedAt($value)
 */
	class ShippingMethod extends \Eloquent {}
}

namespace App\Models{
/**
 * Freight rate for an origin (factory location) → destination (province / optional city).
 *
 * Cost is always `flat_cost + variable`, where the variable component depends on
 * {@see self::COST_BASIS}: 'weight' uses per_kg_cost × kg (e.g. rebar), 'count' uses
 * per_count_cost × sales-count (e.g. brick / wall-mesh). Each route picks its own basis.
 *
 * @see ShippingCostCalculator
 * @property int $id
 * @property int $factory_location_id
 * @property int $destination_province_id
 * @property int|null $destination_city_id
 * @property numeric $flat_cost
 * @property numeric $per_kg_cost
 * @property \App\Enums\Shipping\ShippingCostBasis $cost_basis
 * @property numeric $per_count_cost
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\FactoryLocation $factoryLocation
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereCostBasis($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereDestinationCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereDestinationProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereFlatCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate wherePerCountCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate wherePerKgCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingRate whereUpdatedAt($value)
 */
	class ShippingRate extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $product_variant_id
 * @property string|null $notified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ProductVariant $productVariant
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockSubscription whereUserId($value)
 */
	class StockSubscription extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static create(array $array)
 * @method static where(string $string, string $string1)
 * @method static parentStatus($null)
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property int|null $parent_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SupplierActivityCategory> $children
 * @property-read int|null $children_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SupplierProfile> $suppliers
 * @property-read int|null $suppliers_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierActivityCategory withoutChildren()
 */
	class SupplierActivityCategory extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static where(array $array)
 * @method static withoutChildren()
 * @property int $id
 * @property int $user_id
 * @property string|null $activity_description
 * @property string|null $brands
 * @property int $brands_not_related
 * @property int|null $activity_history
 * @property string|null $activity_provinces
 * @property string|null $production_amount میزان تولید
 * @property string|null $standards استاندارد‌های کسب شده
 * @property int $standards_not_related
 * @property string|null $website
 * @property int $website_not_related
 * @property string|null $company
 * @property bool $trans_commission +%TC: آیا کمیسیون روی هزینه حمل اعمال می‌شود؟
 * @property bool $trans_vat +VAT: آیا مالیات بر ارزش افزوده روی هزینه حمل اعمال می‌شود؟
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SupplierActivityCategory> $activities
 * @property-read int|null $activities_count
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereActivityDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereActivityHistory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereBrands($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereBrandsNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereProductionAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereStandards($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereStandardsNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereTransCommission($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereTransVat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereWebsite($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SupplierProfile whereWebsiteNotRelated($value)
 */
	class SupplierProfile extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string|null $code
 * @property int $user_id
 * @property int|null $order_id
 * @property int|null $province_id
 * @property int|null $city_id
 * @property int $department_id
 * @property int|null $previous_department_id
 * @property int|null $admin_id
 * @property \Illuminate\Support\Carbon|null $assigned_at
 * @property \Illuminate\Support\Carbon|null $first_responded_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $sla_due_at
 * @property int $reopened_count
 * @property string $subject
 * @property string $message
 * @property string $status
 * @property string $priority
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $admin
 * @property-read \App\Models\Department $department
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TicketFile> $files
 * @property-read int|null $files_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TicketNote> $notes
 * @property-read int|null $notes_count
 * @property-read \App\Models\Order|null $order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TicketReply> $replies
 * @property-read int|null $replies_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket breachingSla()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket open()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereAssignedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereDepartmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereFirstRespondedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket wherePreviousDepartmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereReopenedCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereSlaDueAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ticket withoutTrashed()
 */
	class Ticket extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $ticket_id
 * @property int|null $ticket_reply_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Ticket|null $ticket
 * @property-read \App\Models\TicketReply|null $ticketReply
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereOriginalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereTicketReplyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketFile whereUpdatedAt($value)
 */
	class TicketFile extends \Eloquent {}
}

namespace App\Models{
/**
 * Internal staff note attached to a single ticket ("یادداشت های تیکت").
 *
 * Never exposed to the customer-facing ticket resource.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $admin_id
 * @property string $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $admin
 * @property-read \App\Models\Ticket|null $ticket
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketNote whereUpdatedAt($value)
 */
	class TicketNote extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property int|null $admin_id
 * @property string $message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TicketFile> $files
 * @property-read int|null $files_count
 * @property-read \App\Models\Ticket|null $ticket
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketReply whereUserId($value)
 */
	class TicketReply extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $type_id
 * @property int|null $badge_id
 * @property numeric|null $badge_purchase_amount Invoice amount (Rials) the consultant paid for their badge; basis for the lifetime commission cap. NULL = uncapped.
 * @property string $mobile
 * @property bool $is_active
 * @property string|null $national_code
 * @property string|null $birth_date
 * @property string|null $secret
 * @property string|null $secret_expires_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Address> $addresses
 * @property-read int|null $addresses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Agreement> $agreements
 * @property-read int|null $agreements_count
 * @property-read \App\Models\EmployerConsultant|null $pivot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $associatedEmployers
 * @property-read int|null $associated_employers_count
 * @property-read \App\Models\Badge|null $badge
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Badge> $badges
 * @property-read int|null $badges_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserBankAccount> $bankAccounts
 * @property-read int|null $bank_accounts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $beneficiaryOrders
 * @property-read int|null $beneficiary_orders_count
 * @property-read \App\Models\Cart|null $cart
 * @property-read \App\Models\ContractorProfile|null $contractor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EduixRecord> $eduixRecords
 * @property-read int|null $eduix_records_count
 * @property-read \App\Models\EmployerProfile|null $employer
 * @property-read \App\Models\EngineerProfile|null $engineer
 * @property-read float $balance
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string $full_name
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\JobExperience> $jobExperiences
 * @property-read int|null $job_experiences_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserNote> $notes
 * @property-read int|null $notes_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $placedOrders
 * @property-read int|null $placed_orders_count
 * @property-read \App\Models\UserProfile|null $profile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $registeredClients
 * @property-read int|null $registered_clients_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Search> $searches
 * @property-read int|null $searches_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StockSubscription> $stockSubscriptions
 * @property-read int|null $stock_subscriptions_count
 * @property-read \App\Models\SupplierProfile|null $supplier
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $technicalConsultants
 * @property-read int|null $technical_consultants_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \App\Models\UserType|null $type
 * @property-read \App\Models\Wallet|null $wallet
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Wishlist> $wishlists
 * @property-read int|null $wishlists_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User orWhereHasPermission(\BackedEnum|array|string $permission = '', ?mixed $team = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User orWhereHasRole(\BackedEnum|array|string $role = '', ?mixed $team = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBadgePurchaseAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDoesntHavePermissions()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDoesntHaveRoles()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereHasPermission(\BackedEnum|array|string $permission = '', ?mixed $team = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereHasRole(\BackedEnum|array|string $role = '', ?mixed $team = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSecretExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string $bank_name
 * @property string $account_holder_name
 * @property string|null $shaba
 * @property string|null $card_number
 * @property string $status
 * @property string|null $rejection_reason
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Activitylog\Models\Activity> $activities
 * @property-read int|null $activities_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereAccountHolderName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereBankName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereCardNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereShaba($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBankAccount whereUserId($value)
 */
	class UserBankAccount extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int|null $admin_id
 * @property string $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $admin
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNote whereUserId($value)
 */
	class UserNote extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static where(array $array)
 * @method static create(array $array)
 * @property int $id
 * @property int|null $user_id
 * @property int|null $sender_id
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $read_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereReadAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereSenderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNotification whereUserId($value)
 */
	class UserNotification extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static updateOrCreate(array $array, array $array1)
 * @method static create(array $array)
 * @method static where(array $array)
 * @method static firstOrCreate(array $array)
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property string|null $family
 * @property string|null $avatar
 * @property string|null $email
 * @property string|null $email_verification_token
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $national_code
 * @property string|null $birthday
 * @property string|null $national_id
 * @property string|null $company
 * @property string|null $economic_code
 * @property string|null $register_id
 * @property string|null $phone
 * @property int|null $province_id
 * @property int|null $city_id
 * @property string $entity
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read string|void $expire_at_days
 * @property-read string|void $updated_at_diff
 * @property-read string|void $updated_at_p
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereAvatar($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereBirthday($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEconomicCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEmailVerificationToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereEntity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereFamily($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereNationalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereRegisterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserProfile whereUserId($value)
 */
	class UserProfile extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $icon
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $user
 * @property-read int|null $user_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserType whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserType whereSlug($value)
 */
	class UserType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property numeric $balance
 * @property numeric $balance_hold
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WalletTransaction> $transactions
 * @property-read int|null $transactions_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereBalanceHold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet whereUserId($value)
 */
	class Wallet extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $wallet_id
 * @property int|null $order_id
 * @property numeric $amount
 * @property string $type
 * @property string $status
 * @property string|null $description
 * @property array<array-key, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Activitylog\Models\Activity> $activities
 * @property-read int|null $activities_count
 * @property-read mixed $receipt_details
 * @property-read string $status_color
 * @property-read string $status_label
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\Wallet $wallet
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereWalletId($value)
 */
	class WalletTransaction extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductVariant> $variants
 * @property-read int|null $variants_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wishlist whereUserId($value)
 */
	class Wishlist extends \Eloquent {}
}

