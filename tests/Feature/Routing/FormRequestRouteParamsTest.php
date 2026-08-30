<?php

namespace Tests\Feature\Routing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * هر `$this->route('x')` داخلِ یک FormRequest باید نامِ پارامترِ واقعیِ همان
 * روت باشد.
 *
 * ‏**چرا این تست وجود دارد:** وقتی نام غلط باشد، Laravel خطا نمی‌دهد —
 * `route()` مقدارِ `null` برمی‌گرداند و گارد بی‌صدا بی‌اثر می‌شود. دو بار در
 * همین مخزن اتفاق افتاد:
 *
 *  ۱. `UpdateMenuItemRequest` پارامتر را `menu_item` می‌خواند در حالی که روت
 *     `{menuItem}` بود، پس گاردِ حلقه (یک آیتم نمی‌تواند زیرمجموعه‌ی خودش شود)
 *     **هرگز اجرا نشد**.
 *  ۲. `UpdateFaqCategoryRequest` همان اشتباه را با `faq_category` داشت، پس
 *     `Rule::unique()->ignore(null)` می‌شد و ویرایشِ هر دسته بدونِ تغییرِ slug
 *     با ۴۲۲ «تکراری است» رد می‌شد.
 *
 * تطبیق **per-route** است، نه سراسری: پارامتر از روی همان روتی خوانده می‌شود
 * که متدِ کنترلرش این FormRequest را type-hint کرده. تطبیقِ سراسری («این نام
 * جایی در جدولِ روت‌ها هست») مورد ۱ را نمی‌گرفت، چون `menu_item` اسمِ معتبرِ
 * پارامتر در روتِ دیگری بود.
 */
class FormRequestRouteParamsTest extends TestCase
{
    /**
     * پارامترهایی که Laravel خودش تزریق می‌کند و روی روت اعلام نمی‌شوند.
     *
     * @var list<string>
     */
    private const IMPLICIT = ['request'];

    /** @return list<string> نام‌های پارامتری که این کلاس از روت می‌خواند */
    private function paramsReadBy(string $requestClass): array
    {
        $file = (new \ReflectionClass($requestClass))->getFileName();

        if ($file === false || ! is_file($file)) {
            return [];
        }

        preg_match_all(
            '/\$this->route\(\s*[\'"]([^\'"]+)[\'"]/',
            (string) file_get_contents($file),
            $matches
        );

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @return list<class-string<FormRequest>> */
    private function formRequestsOf(ReflectionMethod $method): array
    {
        $classes = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if (class_exists($name) && is_subclass_of($name, FormRequest::class)) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    #[Test]
    public function every_form_request_reads_a_parameter_its_route_actually_declares(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue; // closure route
            }

            [$controller, $method] = explode('@', $action, 2);

            if (! class_exists($controller) || ! method_exists($controller, $method)) {
                continue;
            }

            $declared = $route->parameterNames();

            foreach ($this->formRequestsOf(new ReflectionMethod($controller, $method)) as $requestClass) {
                foreach ($this->paramsReadBy($requestClass) as $read) {
                    if (in_array($read, self::IMPLICIT, true) || in_array($read, $declared, true)) {
                        continue;
                    }

                    $offenders[] = sprintf(
                        '%s reads route(%s) but %s %s declares [%s]',
                        class_basename($requestClass),
                        $read,
                        $route->methods()[0],
                        $route->uri(),
                        implode(', ', $declared) ?: '—',
                    );
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "این پارامترها همیشه null برمی‌گردند و گاردشان بی‌صدا بی‌اثر است:\n  ".
            implode("\n  ", array_unique($offenders))
        );
    }
}
