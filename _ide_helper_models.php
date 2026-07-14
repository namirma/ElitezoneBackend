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
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince query()
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereUserId($value)
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
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ProvinceCity|null $city
 * @property-read \App\Models\ActivityProvince|null $province
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|Address newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Address newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Address query()
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereBuildingNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address wherePostalAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address wherePostalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereReceiverMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereReceiverName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Address whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement active()
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement query()
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereIsMandatory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Agreement whereVersion($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute query()
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute whereAttributeTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Attribute whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType query()
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType whereInputType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeType whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue query()
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue whereDisplayValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|AttributeValue whereValue($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Badge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Badge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Badge query()
 * @method static \Illuminate\Database\Eloquent\Builder|Badge whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Badge whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Badge whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Badge whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Badge whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Badge whereUpdatedAt($value)
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
 * @property-read \App\Models\CartPriceFilterCategory $pivot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceFilterCategory> $priceFilterCategories
 * @property-read int|null $price_filter_categories_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseParameterValue> $purchaseParameters
 * @property-read int|null $purchase_parameters_count
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|Cart newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Cart newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Cart query()
 * @method static \Illuminate\Database\Eloquent\Builder|Cart whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Cart whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Cart whereCustomerTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Cart whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Cart whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Cart whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Cart whereUserId($value)
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
 * @property string $quantity_change
 * @property string|null $unit_price_snapshot
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereActionType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereQuantityChange($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereUnitPriceSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartEvent whereUserId($value)
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
 * @property array $quantities
 * @property string $weight Total logistics weight in KG
 * @property string $quantity Total quantity count
 * @property string|null $unit_name Snapshot of the unit name (e.g., Bundle, Branch)
 * @property string $price
 * @property string $final_price
 * @property string $vat_amount
 * @property string $total_with_vat
 * @property bool $with_vat
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\Cart $cart
 * @property-read \App\Models\Factory $factory
 * @property-read \App\Models\FactoryLocation $factoryLocation
 * @property-read \App\Models\ProductVariant $productVariant
 * @property-read \App\Models\ShippingMethod $shippingMethod
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereQuantities($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereShippingMethodId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereTotalWithVat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereUnitName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereVatAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereWeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartItem whereWithVat($value)
 */
	class CartItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $cart_id
 * @property int $price_filter_category_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory wherePriceFilterCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CartPriceFilterCategory whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute query()
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereAttributeTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereIsFilterable($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereIsRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CategoryAttribute whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereActivityHistory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereCompanyName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereDesignBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereEngineerMember($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereLicenseNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereProjectDone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereProjectInProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereSupervisionBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereTrustedContractor($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractorProfile whereVerifiedAt($value)
 */
	class ContractorProfile extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $code
 * @property string $type
 * @property string $amount
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $owner
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon query()
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Coupon whereUserId($value)
 */
	class Coupon extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $recency_days
 * @property int $frequency_count
 * @property string $monetary_total
 * @property int $r_score
 * @property int $f_score
 * @property int $m_score
 * @property string|null $segment
 * @property \Illuminate\Support\Carbon $last_calculated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat query()
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereFScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereFrequencyCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereLastCalculatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereMScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereMonetaryTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereRScore($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereRecencyDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereSegment($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerStat whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType query()
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType whereHasConsultant($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|CustomerType whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Department isAvailableForTicket()
 * @method static \Illuminate\Database\Eloquent\Builder|Department newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Department newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Department query()
 * @method static \Illuminate\Database\Eloquent\Builder|Department whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Department whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Department whereIsAvailableForTicket($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Department whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Department whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Department whereSlug($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereAdminStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereCourseType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereEntryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereFamily($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereFatherName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereField($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereGpa($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereGraduateDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereIdNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereTraceCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereUniversity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EduixRecord whereUserId($value)
 */
	class EduixRecord extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerEliteYar newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerEliteYar newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerEliteYar query()
 */
	class EmployerEliteYar extends \Eloquent {}
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
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereHasConsultant($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereProjectDone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereProjectInProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerProfile whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite query()
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereAdvisorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EmployerSmsInvite whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereActivityCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereActivityProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereDesignBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereEngineerMember($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereFieldsOfStudy($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereIsSaze90User($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereLicenseNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereMemberNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereStudent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereSupervisionBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereUniversityName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|EngineerProfile whereVerifiedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Factory active()
 * @method static \Illuminate\Database\Eloquent\Builder|Factory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Factory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Factory query()
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereApiUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereSupplierUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Factory whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation active()
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation query()
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|FactoryLocation whereUpdatedAt($value)
 */
	class FactoryLocation extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $current_stock
 * @property string $sales_velocity_30d
 * @property int $search_volume_7d
 * @property string $predicted_demand_next_7d
 * @property string $recommended_restock_amount
 * @property string $priority
 * @property string|null $reason
 * @property string $calculated_at
 * @property-read \App\Models\ProductVariant $variant
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction query()
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereCalculatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereCurrentStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction wherePredictedDemandNext7d($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereRecommendedRestockAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereSalesVelocity30d($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InventoryPrediction whereSearchVolume7d($value)
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
 * @property string $amount مبلغ فاکتور/رسید
 * @property string|null $file_path مسیر فایل PDF ذخیره شده
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice query()
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereDocumentCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereFilePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereInvoiceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience query()
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereFilePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereOrganizationName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereProjectAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|JobExperience whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher query()
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereProjectCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Listopher whereUpdatedAt($value)
 */
	class Listopher extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $listopher_id
 * @property string $pos
 * @property string $shape
 * @property string $length
 * @property string $size
 * @property string|null $hook
 * @property string|null $overlap
 * @property int $quantity
 * @property string|null $length_total
 * @property string|null $b
 * @property string|null $h
 * @property-read \App\Models\Listopher $listopher
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereB($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereH($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereHook($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereLength($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereLengthTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereListopherId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereOverlap($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem wherePos($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereShape($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ListopherItem whereSize($value)
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
 * @property string|null $value
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property int $minimum_quantity
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder|Offer active()
 * @method static \Illuminate\Database\Eloquent\Builder|Offer current()
 * @method static \Illuminate\Database\Eloquent\Builder|Offer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Offer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Offer query()
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereMinimumQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Offer whereValue($value)
 */
	class Offer extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $placer_user_id
 * @property int|null $beneficiary_user_id
 * @property int|null $consultant_user_id الیت یار که کمیسیون CO را دریافت می‌کند
 * @property int|null $address_id
 * @property string $order_number
 * @property string $status
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $shipping_cost
 * @property string $vat_amount
 * @property string $final_amount
 * @property string|null $payment_method
 * @property string $payment_status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $weighbridge_registered_at
 * @property string|null $initial_invoice_ref
 * @property string|null $final_invoice_ref
 * @property string|null $tax_invoice_ref
 * @property string|null $expert_fee_amount
 * @property string|null $expert_fee_payment_status
 * @property string|null $engineer_fee_amount
 * @property string|null $engineer_fee_payment_status
 * @property string|null $contractor_fee_amount
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
 * @property string|null $partial_payment_amount مبلغ پرداخت شده برای تمدید رزرو
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $official_price OP: قیمت رسمی محصول
 * @property string|null $weight_before_scale WBS: وزن قبل از باسکول
 * @property string $commission_rate %TC: درصد کمیسیون
 * @property string $owner_discount_rate %OW: درصد تخفیف خریدار
 * @property string $saze_share_rate %SSG: درصد سهم سازه
 * @property string $consultant_share_rate %CO: درصد سهم مشاور
 * @property bool $trans_commission +%TC snapshot: کمیسیون روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)
 * @property bool $trans_vat +VAT snapshot: مالیات روی حمل؟ (از پروفایل سوپلایر در زمان پرداخت کپی می‌شود)
 * @property string $vat_rate %VAT: درصد ارزش افزوده
 * @property string|null $payment_of_owner POO: پرداختی خریدار
 * @property string|null $payment_to_supplier PTS: پرداختی به سوپلایر
 * @property string|null $payment_to_consultant PTC: پرداختی به مشاور
 * @property string|null $payment_to_reserve PTR: مبلغ پرداخت رزرو
 * @property \Illuminate\Support\Carbon|null $validation_of_reserve VOR: تاریخ اعتبار رزرو
 * @property string|null $weight_after_scale WAS: وزن باسکول
 * @property \Illuminate\Support\Carbon|null $weighing_completed_at
 * @property string|null $consultant_moadian_ref Doc Q: شماره فاکتور مودیان صادر شده توسط الیت یار
 * @property string|null $consultant_hold_released_at زمان آزادسازی هولد کمیسیون مشاور
 * @property string|null $supplier_moadian_ref Doc O: شماره فاکتور مودیان صادر شده توسط سوپلایر
 * @property \Illuminate\Support\Carbon|null $supplier_hold_released_at زمان آزادسازی هولد مالیات سوپلایر در WVAT
 * @property bool $supplier_debt_pending آیا سوپلایر بدهی باسکول دارد که هنوز تسویه نشده؟
 * @property string $supplier_debt_amount مبلغ بدهی سوپلایر بابت کسری باسکول (ریال)
 * @property string|null $system_moadian_ref Doc P: شماره فاکتور مودیان صادر شده توسط سازه (پلتفرم) برای خریدار
 * @property \Illuminate\Support\Carbon|null $system_hold_released_at زمان آزادسازی هولد مالیات پلتفرم (sazeVat) از WVAT
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Activitylog\Models\Activity> $activities
 * @property-read int|null $activities_count
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\User|null $beneficiary
 * @property-read \App\Models\User|null $eliteYar
 * @property-read string|void $created_at_diff
 * @property-read string|void $created_at_p
 * @property-read array $dashboard_progress
 * @property-read array $elite_yar_progress
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
 * @method static \App\Models\Builders\OrderBuilder|Order cancelled()
 * @method static \App\Models\Builders\OrderBuilder|Order current()
 * @method static \App\Models\Builders\OrderBuilder|Order delivered()
 * @method static \App\Models\Builders\OrderBuilder|Order expired()
 * @method static \App\Models\Builders\OrderBuilder|Order forSupplier(int $supplierId)
 * @method static \App\Models\Builders\OrderBuilder|Order forUser(int $userId)
 * @method static \App\Models\Builders\OrderBuilder|Order newModelQuery()
 * @method static \App\Models\Builders\OrderBuilder|Order newQuery()
 * @method static \App\Models\Builders\OrderBuilder|Order paid()
 * @method static \App\Models\Builders\OrderBuilder|Order query()
 * @method static \App\Models\Builders\OrderBuilder|Order whereAddressId($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereBeneficiaryUserId($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereCancelReason($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereCommissionRate($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereConsultantHoldReleasedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereConsultantMoadianRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereConsultantShareRate($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereConsultantUserId($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereContractorFeeAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereContractorFeePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereCreatedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereDiscountAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereEngineerFeeAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereEngineerFeePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereEspadanaOrderRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereExpertFeeAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereExpertFeeInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereExpertFeePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereFinalAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereFinalInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereId($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereInitialInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereNotes($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereOfficialPrice($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereOrderNumber($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereOwnerDiscountRate($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaidAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePartialPaymentAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaymentMethod($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaymentOfOwner($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaymentReceiptRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaymentStatus($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaymentToConsultant($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaymentToReserve($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePaymentToSupplier($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePlacerUserId($value)
 * @method static \App\Models\Builders\OrderBuilder|Order wherePreparedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereReservationExpiresAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSazeShareRate($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereShippedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereShippingCost($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereStatus($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSubtotal($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSupplierDebtAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSupplierDebtPending($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSupplierHoldReleasedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSupplierMoadianRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSurveyStatus($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSystemHoldReleasedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereSystemMoadianRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereTaxInvoiceRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereTransCommission($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereTransVat($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereUpdatedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereValidationOfReserve($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereVatAmount($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereVatRate($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereWaybillRef($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereWeighbridgeRegisteredAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereWeighingCompletedAt($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereWeightAfterScale($value)
 * @method static \App\Models\Builders\OrderBuilder|Order whereWeightBeforeScale($value)
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
 * @property array $quantity_details
 * @property string $weight Total logistics weight in KG (Required for Weighbridge)
 * @property string $quantity Sales quantity (Count, Area, Volume, etc.)
 * @property string|null $unit_name Snapshot of the unit name (e.g., Bundle, M2, Branch)
 * @property string $unit_price
 * @property string $total_price
 * @property string $shipping_cost
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Factory|null $originFactory
 * @property-read \App\Models\FactoryLocation|null $originFactoryLocation
 * @property-read \App\Models\ProductVariant|null $productVariant
 * @property-read \App\Models\Shipment|null $shipment
 * @property-read \App\Models\User|null $supplier
 * @method static \Database\Factories\OrderItemFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem sumQuantity()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereProductName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereQuantityDetails($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereShipmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereShippingCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereSupplierUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereTotalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereUnitName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereVariantSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderItem whereWeight($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereDurationMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereToStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderStatusHistory whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey query()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderSurvey whereUserId($value)
 */
	class OrderSurvey extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $unit
 * @property string|null $amount
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit query()
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit whereUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OrderUnit whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder|Permission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Permission whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Permission whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Permission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Permission whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Permission whereUpdatedAt($value)
 */
	class Permission extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int         $id
 * @property string      $doc_number
 * @property string|null $shenaseh_variz
 * @property string|null $sender_iban        شبای فرستنده از ItemSystemMetaData پادیوم
 * @property string|null $sender_name        نام فرستنده از ItemSystemMetaData پادیوم
 * @property string|null $national_code
 * @property int         $creditor_amount
 * @property int|null    $user_id
 * @property int|null    $wallet_txn_id
 * @property string|null $matched_by         روش تطبیق: shenaseh | iban | null
 * @property string|null $deposited_at_jalali
 * @property \Carbon\Carbon|null $processed_at
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\WalletTransaction|null $walletTransaction
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereCreditorAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereDepositedAtJalali($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereDocNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereMatchedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereSenderIban($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereSenderName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereShenasehVariz($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PodiumDepositRecord whereWalletTxnId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment active()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment query()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment whereAdjustmentAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment whereCustomerTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceAdjustment whereUpdatedAt($value)
 */
	class PriceAdjustment extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $price_filter_type_id
 * @property string $name
 * @property string $code
 * @property string $amount
 * @property bool $is_percentage
 * @property bool $is_active
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PriceFilterCategory> $categories
 * @property-read int|null $categories_count
 * @property-read \App\Models\PriceFilterType $filterType
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter active()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter query()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereIsPercentage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter wherePriceFilterTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilter whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory active()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterCategory whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType active()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType forGeneral()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType forSpecial()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType query()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereApplyOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceFilterType whereUpdatedAt($value)
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
 * @property string|null $general_price
 * @property string|null $special_price
 * @property string|null $golden_figure
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Factory|null $factory
 * @property-read \App\Models\ProductPrice|null $productPrice
 * @property-read \App\Models\ProductVariant $productVariant
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereGeneralPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereGoldenFigure($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereProductPriceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereSpecialPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceHistory whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PriceSetting whereValue($value)
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
 * @property string|null $aggregate_min_order_value
 * @property string|null $aggregate_max_order_value
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
 * @method static \Illuminate\Database\Eloquent\Builder|Product active()
 * @method static \Illuminate\Database\Eloquent\Builder|Product featured()
 * @method static \Illuminate\Database\Eloquent\Builder|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereAggregateLimitOrderUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereAggregateMaxOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereAggregateMinOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereIsFeatured($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereItemsPerPage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereShippingPromo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereViewCount($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory active()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductCategory whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage whereImagePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage whereIsPrimary($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductImage whereUpdatedAt($value)
 */
	class ProductImage extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $factory_id
 * @property int $factory_location_id
 * @property string $general_price
 * @property string $special_price
 * @property string $golden_figure
 * @property \Illuminate\Support\Carbon $last_updated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Factory $factory
 * @property-read \App\Models\FactoryLocation $factoryLocation
 * @property-read \App\Models\ProductVariant $productVariant
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereGeneralPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereGoldenFigure($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereLastUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereSpecialPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPrice whereUpdatedAt($value)
 */
	class ProductPrice extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property int $product_variant_id
 * @property string $role_code
 * @property string $final_price
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\ProductVariant $variant
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereRoleCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductPriceCache whereUpdatedAt($value)
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
 * @property string|null $discount_value
 * @property string|null $discount_type
 * @property \Illuminate\Support\Carbon|null $discount_start_date
 * @property \Illuminate\Support\Carbon|null $discount_end_date
 * @property string|null $min_order_value
 * @property string|null $max_order_value
 * @property int|null $limit_order_unit_id
 * @property array|null $images
 * @property array|null $videos
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
 * @property-read \App\Models\ProductVariantAttribute $details
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Attribute> $variantAttributes
 * @property-read int|null $variant_attributes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Wishlist> $wishlists
 * @property-read int|null $wishlists_count
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant active()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereDiscountEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereDiscountStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereDiscountType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereDiscountValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereImages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereLimitOrderUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereMaxOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereMinOrderValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereStockQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereVideos($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariant whereViewCount($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariantAttribute newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariantAttribute newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariantAttribute query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariantAttribute whereAttributeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariantAttribute whereAttributeValueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariantAttribute whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVariantAttribute whereProductVariantId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereThumbnailPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductVideo whereVideoPath($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductView whereViewedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereUniqueSessions($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereViewDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProductViewDaily whereViews($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory whereKeyword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProfileSearchHistory whereUserId($value)
 */
	class ProfileSearchHistory extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $ProvinceID
 * @property string $Name
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProvinceCity whereProvinceID($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup active()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup ordered()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup query()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterGroup whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue active()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue ordered()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue query()
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue wherePriceFilterCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PurchaseParameterValue whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Question newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Question newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Question query()
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Question whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Role whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Search newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Search newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Search query()
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereQuery($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereResultsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Search whereUserId($value)
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
 * @property string $weight
 * @property string $billed_count
 * @property \App\Enums\Shipping\ShippingCostBasis $cost_basis
 * @property string $shipping_cost
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Address|null $destinationAddress
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Factory|null $originFactory
 * @property-read \App\Models\FactoryLocation|null $originFactoryLocation
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment query()
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereAddressId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereBilledCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereCostBasis($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereDestinationCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereDestinationProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereFactoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereShippingCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Shipment whereWeight($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod query()
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod whereDeliveryNotice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingMethod whereUpdatedAt($value)
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
 * @property string $flat_cost
 * @property string $per_kg_cost
 * @property \App\Enums\Shipping\ShippingCostBasis $cost_basis
 * @property string $per_count_cost
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\FactoryLocation $factoryLocation
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate active()
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate query()
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereCostBasis($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereDestinationCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereDestinationProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereFactoryLocationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereFlatCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate wherePerCountCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate wherePerKgCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ShippingRate whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription query()
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription whereNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|StockSubscription whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierActivityCategory withoutChildren()
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
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereActivityDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereActivityHistory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereActivityProvinces($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereBrands($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereBrandsNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereProductionAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereStandards($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereStandardsNotRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereTransCommission($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereTransVat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereWebsite($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SupplierProfile whereWebsiteNotRelated($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket breachingSla()
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket open()
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket query()
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereAssignedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereDepartmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereFirstRespondedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket wherePreviousDepartmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereReopenedCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereSlaDueAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|Ticket withoutTrashed()
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
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile query()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereOriginalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereTicketReplyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketFile whereUpdatedAt($value)
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
 * @property-read \App\Models\Ticket $ticket
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote query()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketNote whereUpdatedAt($value)
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
 * @property-read \App\Models\Ticket $ticket
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply query()
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply whereTicketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TicketReply whereUserId($value)
 */
	class TicketReply extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $type_id
 * @property int|null $badge_id
 * @property string|null $badge_purchase_amount Invoice amount (Rials) the consultant paid for their badge; basis for the lifetime commission cap. NULL = uncapped.
 * @property string $mobile
 * @property bool $is_active
 * @property string|null $national_code
 * @property string|null $birth_date
 * @property string|null $secret
 * @property string|null $secret_expires_at
 * @property mixed|null $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Address> $addresses
 * @property-read int|null $addresses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Agreement> $agreements
 * @property-read int|null $agreements_count
 * @property-read \App\Models\EmployerEliteYar $pivot
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $technicalEliteYars
 * @property-read int|null $technical_elite_yars_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \App\Models\UserType|null $type
 * @property-read \App\Models\Wallet|null $wallet
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Wishlist> $wishlists
 * @property-read int|null $wishlists_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User orWhereHasPermission(\BackedEnum|array|string $permission = '', ?mixed $team = null)
 * @method static \Illuminate\Database\Eloquent\Builder|User orWhereHasRole(\BackedEnum|array|string $role = '', ?mixed $team = null)
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @method static \Illuminate\Database\Eloquent\Builder|User whereBadgeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereBadgePurchaseAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereDoesntHavePermissions()
 * @method static \Illuminate\Database\Eloquent\Builder|User whereDoesntHaveRoles()
 * @method static \Illuminate\Database\Eloquent\Builder|User whereHasPermission(\BackedEnum|array|string $permission = '', ?mixed $team = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|User whereHasRole(\BackedEnum|array|string $role = '', ?mixed $team = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereMobile($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereSecretExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereUpdatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereAccountHolderName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereBankName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereCardNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereRejectionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereShaba($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserBankAccount whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote whereAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNote whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereReadAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereSenderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserNotification whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereAvatar($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereBirthday($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereCompany($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereEconomicCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereEmailVerificationToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereEntity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereFamily($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereNationalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereNationalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereRegisterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserProfile whereUserId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|UserType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|UserType query()
 * @method static \Illuminate\Database\Eloquent\Builder|UserType whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|UserType whereSlug($value)
 */
	class UserType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string $balance
 * @property string $balance_hold
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WalletTransaction> $transactions
 * @property-read int|null $transactions_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet query()
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet whereBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet whereBalanceHold($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wallet whereUserId($value)
 */
	class Wallet extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $wallet_id
 * @property int|null $order_id
 * @property string $amount
 * @property string $type
 * @property string $status
 * @property string|null $description
 * @property array|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Activitylog\Models\Activity> $activities
 * @property-read int|null $activities_count
 * @property-read mixed $receipt_details
 * @property-read string $status_color
 * @property-read string $status_label
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\Wallet $wallet
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|WalletTransaction whereWalletId($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist query()
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Wishlist whereUserId($value)
 */
	class Wishlist extends \Eloquent {}
}

