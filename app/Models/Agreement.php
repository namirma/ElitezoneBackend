<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $version
 * @property string|null $content
 * @property bool $is_active
 * @property bool $is_mandatory
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
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
 *
 * @mixin \Eloquent
 */
class Agreement extends Model
{
    use HasFactory;

    // =========================================================================
    //  Constants (Database Columns)
    // =========================================================================
    public const ID = 'id';

    public const SLUG = 'slug';

    public const TITLE = 'title';

    public const VERSION = 'version';

    public const CONTENT = 'content';

    /** چک‌باکس‌های الزامی نسخه — JSON: [{code: CHK-01, text: "..."}] */
    public const CHECKBOXES = 'checkboxes';

    /** هش SHA-256 متن نسخه — پذیرش کاربر به متن دقیق گره می‌خورد. */
    public const CONTENT_SHA256 = 'content_sha256';

    public const EFFECTIVE_AT = 'effective_at';

    public const IS_ACTIVE = 'is_active';

    public const IS_MANDATORY = 'is_mandatory';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    // ── اسلاگ‌های کانونیکال (۵ توافق‌نامه طبق سند طراحی) ─────────────────────
    public const SLUG_ELITEZONE_TERMS = 'elitezone_terms';

    public const SLUG_PRIVACY_POLICY = 'privacy_policy';

    public const SLUG_ELITE_YAR_TERMS = 'elite_yar_terms';

    public const SLUG_EMPLOYER_TERMS = 'employer_terms';

    public const SLUG_SUPPLIER_TERMS = 'supplier_terms';

    // =========================================================================
    //  Configurations
    // =========================================================================
    protected $fillable = [
        self::SLUG,
        self::TITLE,
        self::VERSION,
        self::CONTENT,
        self::CHECKBOXES,
        self::CONTENT_SHA256,
        self::EFFECTIVE_AT,
        self::IS_ACTIVE,
        self::IS_MANDATORY,
    ];

    protected $casts = [
        self::CHECKBOXES => 'array',
        self::EFFECTIVE_AT => 'datetime',
        self::IS_ACTIVE => 'boolean',
        self::IS_MANDATORY => 'boolean',
    ];

    // =========================================================================
    //  Scopes / helpers
    // =========================================================================
    public function scopeActive($query)
    {
        return $query->where(self::IS_ACTIVE, true);
    }

    /**
     * اسلاگ‌های مرتبط با کاربر: دو سند عمومی برای همه + سند اختصاصی هر نقشی
     * که دارد (الیت یار برای `User::isEliteYAR()` — نقشِ `elite_yar` یا نقش‌های قدیمیِ
     * engineer/contractor). منبعِ واحد برای `AgreementController` و
     * `VirtualEmployerAccount` (پایانِ ثبت‌نامِ حسابِ مجازی = پذیرشِ همین‌ها).
     *
     * @return string[]
     */
    public static function relevantSlugsFor(User $user): array
    {
        $slugs = [Agreement::SLUG_ELITEZONE_TERMS, Agreement::SLUG_PRIVACY_POLICY];

        if ($user->isEliteYAR()) {
            $slugs[] = Agreement::SLUG_ELITE_YAR_TERMS;
        }
        if ($user->hasRole('employer')) {
            $slugs[] = Agreement::SLUG_EMPLOYER_TERMS;
        }
        if ($user->hasRole('supplier')) {
            $slugs[] = Agreement::SLUG_SUPPLIER_TERMS;
        }

        return $slugs;
    }

    /** آخرین نسخه‌ی فعالِ یک اسلاگ — نسخه‌ی قابل پذیرش فعلی. */
    public static function latestActiveForSlug(string $slug): ?self
    {
        return static::query()
            ->where(self::SLUG, $slug)
            ->active()
            ->orderByDesc(self::CREATED_AT)
            ->orderByDesc(self::ID)
            ->first();
    }

    /** کدهای چک‌باکس الزامی این نسخه. */
    public function checkboxCodes(): array
    {
        return collect($this->{self::CHECKBOXES} ?? [])->pluck('code')->filter()->values()->all();
    }

    // =========================================================================
    //  Relationships
    // =========================================================================
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_agreements')
            ->withPivot([
                'accepted_at', 'version', 'content_sha256', 'ip_address',
                'user_agent', 'checked_items', 'acceptance_method', 'snapshot',
            ])
            ->withTimestamps();
    }
}
