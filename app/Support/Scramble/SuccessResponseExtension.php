<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Infer\Extensions\Event\FunctionCallEvent;
use Dedoc\Scramble\Infer\Extensions\FunctionReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralBooleanType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\TypeManagers\ResourceCollectionTypeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\PaginatedResourceResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * پاسخِ `success()` و `created()` را برای Scramble قابلِ خواندن می‌کند.
 *
 * هر دو فقط `Response::success` را صدا می‌زنند و آن یک **ماکرو** است؛ Scramble از
 * ماکرو عبور نمی‌کند، پس ۴۹۱ از ۵۲۵ عملیات در `api.json` فقط `{"type":"object"}`
 * داشتند — قراردادی که فرانت از آن می‌خواند شکلِ هیچ پاسخی را نمی‌گفت. این
 * افزونه همان پاکتی را اعلام می‌کند که ماکرو واقعاً می‌سازد
 * (`ResponseServiceProvider`)، و `SuccessEnvelopeIsDocumentedTest` آن را با پاسخِ
 * واقعی مقایسه می‌کند تا دو طرف از هم جدا نشوند.
 */
class SuccessResponseExtension implements FunctionReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return in_array(ltrim($name, '\\'), ['success', 'created', 'error', 'trans'], true);
    }

    public function getFunctionReturnType(FunctionCallEvent $event): ?Type
    {
        return match (ltrim($event->getName(), '\\')) {
            'trans' => $this->arrayTranslation($event),
            'error' => $this->errorEnvelope($event),
            'created' => $this->envelope($event->getArg('data', 0, new NullType), new LiteralIntegerType(201)),
            default => $this->envelope($event->getArg('data', 0), $event->getArg('status', 2, new LiteralIntegerType(200))),
        };
    }

    /**
     * ‏`error($message, $errors, $status)` — همان ماکروی `Response::error`.
     *
     * بدونِ این، متدی که در یک شاخه `success()` و در شاخه‌ی دیگر `error()` برمی‌گرداند
     * نوعِ نامعلوم می‌گرفت و Scramble **هر دو** پاسخ را به `{"type":"object"}` تقلیل
     * می‌داد — ۱۰۹ از ۱۳۳ عملیاتِ بی‌سند همین بودند. `data` همیشه `null` است و
     * `errors` برای سازگاری با کلاینت‌های قدیمی کنارش می‌ماند.
     */
    private function errorEnvelope(FunctionCallEvent $event): Generic
    {
        return new Generic(JsonResponse::class, [
            new KeyedArrayType([
                new ArrayItemType_('status', new LiteralBooleanType(false)),
                new ArrayItemType_('message', new StringType),
                new ArrayItemType_('data', new NullType),
                new ArrayItemType_('errors', $event->getArg('errors', 1, new ArrayType)),
            ]),
            $event->getArg('status', 2),
            new KeyedArrayType,
        ]);
    }

    /**
     * کلیدِ ترجمه‌ای که **آرایه** است (`trans('elite_yar.rejection_reasons')`).
     *
     * ⚠️ با `__()` نمی‌شود: افزونه‌ی خودِ Scramble هر `__()` تک‌آرگومانی را
     * `TranslatedStringType` می‌گیرد، هنگامِ resolve روی آرایه TypeError می‌دهد و
     * کلِ export را می‌خواباند — و آن افزونه **پیش از** افزونه‌های config ثبت
     * می‌شود، پس اینجا نمی‌توان جلویش را گرفت. برای `trans()` افزونه‌ای ندارد؛
     * شکلِ واقعی از خودِ مقدار ساخته می‌شود و کلیدِ رشته‌ای `null` می‌گیرد.
     */
    private function arrayTranslation(FunctionCallEvent $event): ?Type
    {
        if (count($event->arguments) !== 1) {
            return null;
        }

        $key = $event->getArg('key', 0);

        if (! $key instanceof LiteralStringType) {
            return null;
        }

        $value = __($key->value);

        return is_array($value) ? TypeHelper::createTypeFromValue($value) : null;
    }

    private function envelope(Type $data, Type $status): Generic
    {
        $body = [
            new ArrayItemType_('status', new LiteralBooleanType(true)),
            new ArrayItemType_('message', new StringType),
            new ArrayItemType_('data', $data),
        ];

        // ‏`paginationEnvelope`: صفحه‌بند، `meta` و `links` را **کنارِ** `data` می‌گذارد
        // نه داخلش. بدونِ این، سند فهرست را بی‌صفحه نشان می‌داد.
        if ($this->isPaginatedCollection($data)) {
            $body[] = new ArrayItemType_('meta', self::meta());
            $body[] = new ArrayItemType_('links', self::links(['first', 'last', 'prev', 'next']));
        } elseif ($data instanceof ObjectType && $data->isInstanceOf(AbstractPaginator::class)) {
            // صفحه‌بندِ خام (`$paginator->through(...)`): Scramble شکلِ **تختِ**
            // `toArray()` را زیرِ `data` می‌نوشت، ولی ماکرو آن را به همان سه‌تایی
            // باز می‌کند — با کلیدهای `*_page_url` برای `links`.
            $body[2] = new ArrayItemType_('data', new ArrayType);
            // ‏`array_diff_key(..., ['links' => null])` در ماکرو: اینجا `meta` کلیدِ
            // `links` ندارد — برخلافِ شاخه‌ی کالکشن که لاراول خودش می‌سازد.
            $body[] = new ArrayItemType_('meta', self::meta(withLinks: false));
            $body[] = new ArrayItemType_('links', self::links(['first_page_url', 'last_page_url', 'prev_page_url', 'next_page_url']));
        }

        return new Generic(JsonResponse::class, [new KeyedArrayType($body), $status, new KeyedArrayType]);
    }

    private function isPaginatedCollection(Type $data): bool
    {
        return $data instanceof ObjectType
            && $data->isInstanceOf(ResourceCollection::class)
            && ResourceCollectionTypeManager::make($data)->getResponseType()->isInstanceOf(PaginatedResourceResponse::class);
    }

    /** ‏`PaginatedResourceResponse::meta` برای `paginate()` — تنها صفحه‌بندِ این پروژه. */
    private static function meta(bool $withLinks = true): KeyedArrayType
    {
        // ⚠️ آرایه، نه دو آرگومان: `Union::wrap(...$types)` آن‌ها را به `Arr::wrap`
        // می‌دهد که **یک** پارامتر دارد — آرگومانِ دوم بی‌صدا دور ریخته می‌شد و
        // `from`/`to` (که روی صفحه‌ی خالی null‌اند) در سند غیرِ nullable درمی‌آمدند.
        $nullableInt = Union::wrap([new IntegerType, new NullType]);

        return new KeyedArrayType(array_values(array_filter([
            new ArrayItemType_('current_page', new IntegerType),
            new ArrayItemType_('from', $nullableInt),
            new ArrayItemType_('last_page', new IntegerType),
            $withLinks ? new ArrayItemType_('links', new ArrayType) : null,
            new ArrayItemType_('path', new StringType),
            new ArrayItemType_('per_page', new IntegerType),
            new ArrayItemType_('to', $nullableInt),
            new ArrayItemType_('total', new IntegerType),
        ])));
    }

    /** @param  list<string>  $keys */
    private static function links(array $keys): KeyedArrayType
    {
        return new KeyedArrayType(array_map(
            fn (string $key) => new ArrayItemType_($key, Union::wrap([new StringType, new NullType])),
            $keys,
        ));
    }
}
