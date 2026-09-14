<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionNamedType;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;
use Throwable;

/**
 * هیچ بدنه‌ی نامعتبری نباید ۵۰۰ بدهد.
 *
 * الگوی تکرارشونده (`POST /admin/page-blocks` در ۲۰۲۶-۰۹-۱۳، پاسخِ تیکت در بازبینیِ
 * تیمِ بک‌اند ۲۰۲۶-۰۹-۱۴): FormRequest قاعده‌ی تودرتو (`data.*`) دارد ولی برای خودِ
 * کلیدِ والد قاعده‌ای نیست → `validated()` کلید را برنمی‌گرداند → ستونِ NOT NULL
 * مقدارِ null می‌گیرد → QueryException → ۵۰۰ به‌جای ۴۲۲ با پیامِ فارسی. نوعِ دوم:
 * `catch (\Throwable)` که خطای اعتبارسنجی را ۵۰۰ می‌کند یا متنِ SQL را نشت می‌دهد.
 *
 * این تست هر مسیرِ POST/PUT/PATCH را از جدولِ روت‌ها برمی‌دارد و با دو بدنه می‌زند:
 * خالی، و آشغال (`{"data":{"nope":1}}` + نوع‌های غلط). هر پاسخِ ۵xx یک باگِ
 * اعتبارسنجی است و مسیرش در پیامِ شکست چاپ می‌شود. پارامترهای مسیر با factoryِ
 * مدلِ bind‌شده پر می‌شوند تا اعتبارسنجی واقعاً اجرا شود، نه این‌که در ۴۰۴ِ
 * route-model-binding بایستد؛ مدلِ بدونِ factory به `1` می‌افتد (۴۰۴ مجاز است).
 */
class ValidationNeverFiveHundredTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    /** بدنه‌ی آشغال — کلیدهای رایج با نوعِ غلط. */
    private const GARBAGE = [
        'data' => ['nope' => 1],
        'items' => 'not-an-array',
        'blocks' => 'not-an-array',
        'ids' => 'not-an-array',
        'permissions' => 'not-an-array',
        'name' => ['x'],
        'title' => ['x'],
        'slug' => ['x'],
        'status' => ['x'],
        'type' => ['x'],
        'code' => ['x'],
        'username' => ['x'],
        'mobile' => ['x'],
        'email' => ['x'],
        'password' => ['x'],
        'value' => ['x'],
        'text' => ['x'],
        'body' => ['x'],
        'message' => ['x'],
        'description' => ['x'],
        'amount' => 'abc',
        'price' => 'abc',
        'quantity' => 'abc',
        'weight' => 'abc',
        'id' => 'abc',
        'user_id' => 'abc',
        'order_id' => 'abc',
        'page' => 'abc',
        'per_page' => 'abc',
        'date' => 'not-a-date',
        'file' => 'not-a-file',
        'image' => 'not-a-file',
        // پودیوم / مالی — `(string)` روی آرایه در prepareForValidation ۵۰۰ می‌داد.
        'toolCode' => ['x'],
        'list' => ['x', ['amount' => ['x'], 'firstName' => ['x']]],
        'toolId' => ['x'],
        'guildCode' => ['x'],
        'wallet' => ['x'],
        'batchId' => ['x'],
        'uniqueId' => ['x'],
        'paymentId' => ['x'],
        'currencyCode' => ['x'],
        'statusCode' => ['x'],
        'offset' => ['x'],
        'size' => ['x'],
        'fromDate' => ['x'],
        'toDate' => ['x'],
        'shaba' => ['x'],
        'card_number' => ['x'],
        'device_name' => ['x'],
        'national_code' => ['x'],
        'birth_date' => ['x'],
        'reason' => ['x'],
        'note' => ['x'],
        'subject' => ['x'],
        'question' => ['x'],
        'answer' => ['x'],
        'content' => ['x'],
        'key' => ['x'],
        'sort_order' => ['x'],
        'is_active' => ['x'],
    ];

    /**
     * ۵xxهایی که قراردادِ درستِ مسیرند، نه باگ — با دلیل.
     *
     * @var array<string, string>
     */
    private const EXPECTED_UPSTREAM_FAILURES = [
        'POST api/admin/financial/podium-deposits/poll' => 'پراکسیِ پادیوم؛ پاسخِ ۵۰۳ یعنی «سرویس در دسترس نیست» (Http::fake این تست ۵۰۳ می‌دهد).',
    ];

    /** @var array<class-string<Model>, Model|null> */
    private array $models = [];

    protected function setUp(): void
    {
        parent::setUp();

        // هیچ درخواستِ خارجی (پودیوم، پیامک) از تست بیرون نرود؛ پاسخِ ۵۰۳ یعنی
        // «سرویس در دسترس نیست» — مسیری که این را درست هندل کند ۵۰۲/۵۰۳ برمی‌گرداند
        // نه ۵۰۰.
        Http::fake(fn () => Http::response(['status' => false], 503));

        // دو درخواست × چند صد مسیر با یک کاربر — سقفِ `throttle:api` (۶۰ در دقیقه)
        // نتیجه را ۴۲۹ می‌کرد و باگِ واقعی را پنهان.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    #[Test]
    public function admin_write_routes_never_answer_a_bad_body_with_a_server_error(): void
    {
        $admin = User::factory()->create();
        $admin->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));
        $this->grantAllAdminPermissions($admin);
        Sanctum::actingAs($admin);

        [$exercised, $offenders] = $this->sweep(
            fn (string $uri) => str_starts_with($uri, 'api/admin')
                || str_starts_with($uri, 'api/v1/podium')
                || str_starts_with($uri, 'api/data')
        );

        $this->assertGreaterThan(50, $exercised, 'جدولِ روت‌ها خالی به نظر می‌رسد.');
        $this->assertSame([], $offenders, $this->report($offenders, $exercised));
    }

    #[Test]
    public function user_write_routes_never_answer_a_bad_body_with_a_server_error(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        [$exercised, $offenders] = $this->sweep(
            fn (string $uri) => ! str_starts_with($uri, 'api/admin')
                && ! str_starts_with($uri, 'api/v1/podium')
                && ! str_starts_with($uri, 'api/data')
        );

        $this->assertGreaterThan(50, $exercised, 'جدولِ روت‌ها خالی به نظر می‌رسد.');
        $this->assertSame([], $offenders, $this->report($offenders, $exercised));
    }

    /**
     * @param  callable(string): bool  $uriFilter
     * @return array{0: int, 1: list<string>}
     */
    private function sweep(callable $uriFilter): array
    {
        $exercised = 0;
        $offenders = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/') || ! $uriFilter($uri)) {
                continue;
            }

            $methods = array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH']));
            if ($methods === []) {
                continue;
            }

            $path = '/'.$this->fillParameters($route);
            $exercised++;

            foreach ($methods as $method) {
                foreach (['empty' => [], 'garbage' => self::GARBAGE] as $label => $body) {
                    $response = $this->json($method, $path, $body);
                    $status = $response->getStatusCode();

                    if ($status >= 500 && ! array_key_exists("$method $uri", self::EXPECTED_UPSTREAM_FAILURES)) {
                        $offenders[] = sprintf(
                            '%s %s [%s body] → %d: %s',
                            $method,
                            $uri,
                            $label,
                            $status,
                            mb_substr((string) $response->json('message'), 0, 160)
                        );
                    }
                }
            }
        }

        return [$exercised, $offenders];
    }

    /** @param  list<string>  $offenders */
    private function report(array $offenders, int $exercised): string
    {
        return sprintf(
            "%d مسیر بررسی شد؛ این‌ها با بدنه‌ی نامعتبر ۵xx دادند (باید ۴۲۲ با پیامِ فارسی باشد):\n - %s",
            $exercised,
            implode("\n - ", $offenders)
        );
    }

    /**
     * `{order}` → شناسه‌ی یک Orderِ factory‌ساخته؛ `{product:cuid}` → همان ستون؛
     * پارامترِ بدونِ مدل یا بدونِ factory → `1`.
     */
    private function fillParameters(RouteInstance $route): string
    {
        $uri = $route->uri();
        if ($route->parameterNames() === []) {
            return $uri;
        }

        $types = [];
        try {
            foreach ($route->signatureParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
                    && is_subclass_of($type->getName(), Model::class)) {
                    $types[$parameter->getName()] = $type->getName();
                }
            }
        } catch (Throwable) {
            // کنترلرِ بدونِ امضای قابلِ خواندن — همه به `1` می‌افتند.
        }

        foreach ($route->parameterNames() as $name) {
            $value = '1';
            $field = $route->bindingFieldFor($name);
            if (isset($types[$name]) && ($model = $this->modelFor($types[$name], $field)) !== null) {
                $value = (string) ($field ? $model->{$field} : $model->getRouteKey());
            }
            $uri = preg_replace('/\{'.preg_quote($name, '/').'(:[^}]+)?\??\}/', $value, $uri) ?? $uri;
        }

        return $uri;
    }

    /**
     * یک ردیف از مدل — با factory اگر دارد، وگرنه ساخته‌شده از روی اسکیما.
     *
     * فقط ۶ مدل factory دارند و ~۹۰ مسیرِ پارامتردار به مدل‌های دیگر bind می‌شوند؛
     * بدونِ این، همه‌ی آن‌ها در ۴۰۴ می‌ایستادند و اعتبارسنجی‌شان هرگز اجرا نمی‌شد
     * (اولین اجرای این تست: ۲۴۷ از ۵۴۲ پاسخ ۴۰۴ بود). ردیفِ ساختگی فقط ستون‌های
     * NOT NULLِ بدونِ پیش‌فرض را پر می‌کند و FK را موقتاً خاموش می‌کند — این ردیف
     * قرار نیست معنادار باشد، فقط باید از route-model-binding رد شود.
     *
     * @param  class-string<Model>  $class
     */
    private function modelFor(string $class, ?string $field = null): ?Model
    {
        if (! array_key_exists($class, $this->models)) {
            try {
                // `method_exists($class, 'factory')` کافی نیست: HasFactory روی همه هست و
                // `FactoryLocation::factory()` اصلاً رابطه‌ی Eloquent به مدلِ Factory است.
                $this->models[$class] = class_exists(Factory::resolveFactoryName($class))
                    ? call_user_func([$class, 'factory'])->create()
                    : $this->fabricate($class, $field);
            } catch (Throwable) {
                $this->models[$class] = null;
            }
        }

        return $this->models[$class];
    }

    /** @param  class-string<Model>  $class */
    private function fabricate(string $class, ?string $field): ?Model
    {
        $instance = new $class;
        $key = $instance->getKeyName();
        $id = $this->fabricateRow($instance->getTable(), $key, $field ? [$field => 'x'] : []);

        return $class::query()->withoutGlobalScopes()->where($key, $id)->first();
    }

    /** @var array<string, int|string> */
    private array $rows = [];

    /**
     * یک ردیف در جدول با همه‌ی ستون‌های NOT NULLِ بدونِ پیش‌فرض، و والدِ هر FKِ
     * اجباری هم به همین شکل ساخته می‌شود (بازگشتی، هر جدول یک بار) — وگرنه مسیری
     * که بدنه نمی‌خواهد (approve/toggle) روی ردیفی با FKِ آویزان ۵۰۰ می‌داد و
     * جای باگِ واقعی را می‌گرفت. `users` همیشه کاربرِ جاری است.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fabricateRow(string $table, string $key, array $overrides = [], int $depth = 0): int|string
    {
        if ($table === 'users') {
            return $this->app['auth']->id();
        }
        if ($overrides === [] && isset($this->rows[$table])) {
            return $this->rows[$table];
        }

        $row = [];
        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];
            if (($column['nullable'] || $column['default'] !== null) && ! in_array($name, ['created_at', 'updated_at'], true)) {
                continue;
            }
            if (! $column['auto_increment']) {
                $row[$name] = $this->dummyFor($column);
            }
        }

        if ($depth < 4) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                foreach ($fk['columns'] as $i => $col) {
                    if (array_key_exists($col, $row) && count($fk['columns']) === 1) {
                        $row[$col] = $this->fabricateRow($fk['foreign_table'], $fk['foreign_columns'][$i] ?? 'id', [], $depth + 1);
                    }
                }
            }
        }

        // ردیف مالِ کاربرِ جاری باشد تا policyِ مالکیت به‌جای ۴۰۳، اعتبارسنجی را اجرا کند.
        if (array_key_exists('user_id', array_column(Schema::getColumns($table), 'name', 'name'))) {
            $row['user_id'] = $this->app['auth']->id();
        }

        $row = array_merge($row, $overrides);

        Schema::disableForeignKeyConstraints();
        try {
            $id = DB::table($table)->insertGetId($row);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $id = $row[$key] ?? $id;

        return $overrides === [] ? ($this->rows[$table] = $id) : $id;
    }

    /** @param  array<string, mixed>  $column */
    private function dummyFor(array $column): string|int
    {
        $type = strtolower((string) $column['type_name']);

        if ($type === 'enum' && preg_match("/^enum\('([^']*)'/i", (string) $column['type'], $m)) {
            return $m[1];
        }

        return match (true) {
            in_array($type, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'double', 'float', 'year'], true) => 1,
            in_array($type, ['date'], true) => '2026-01-01',
            in_array($type, ['datetime', 'timestamp'], true) => '2026-01-01 00:00:00',
            $type === 'time' => '00:00:00',
            $type === 'json' => '[]',
            default => 'x',
        };
    }
}
