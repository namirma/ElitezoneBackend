<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Validation\Rule;

/**
 * تنها منبعِ حقیقت برای «هر نوعِ بلوک چه فیلدهایی دارد».
 *
 * سه مصرف‌کننده از همین یک آرایه تغذیه می‌شوند:
 *   ۱. `GET /admin/page-blocks/schema` → بک‌آفیس فرم را خودکار می‌سازد
 *   ۲. `rulesFor()` → اعتبارسنجیِ Store/Update
 *   ۳. خودِ همین فایل، به‌عنوان مستندِ قرارداد
 *
 * بدونِ این تمرکز، هر نوعِ بلوک یک فرمِ دستی در بک‌آفیس می‌خواست و قواعدِ
 * اعتبارسنجی جدا نوشته می‌شدند — دو کپی از یک قرارداد که بی‌صدا واگرا می‌شوند.
 *
 * افزودنِ نوعِ جدید: یک ورودی این‌جا + یک کامپوننت در `blockRegistry.jsx` سایت.
 * بک‌آفیس تغییری نمی‌کند.
 */
final class PageBlockSchema
{
    /** ویجت‌هایی که بک‌آفیس می‌شناسد. نوعِ بیرونِ این فهرست = فرمِ خالی. */
    public const WIDGETS = [
        'text', 'textarea', 'image', 'link', 'list',
        'number', 'color', 'select', 'boolean', 'glass',
    ];

    /**
     * شکلِ عضوِ فهرست‌های آبجکتی — `['of' => 'card']` یعنی هر عضو این کلیدها را دارد.
     *
     * هر مقدار یا نامِ یک ویجت است (رشته‌ی ساده، حالتِ رایج) یا — وقتی ویجت به
     * گزینه نیاز دارد (مثلِ `select`) — همان شکلی که فیلدهای سطحِ بالا دارند:
     * `['type' => ..., 'options' => [...]]`.
     *
     * @var array<string, array<string, string|array{type: string, options?: list<string>}>>
     */
    public const SHAPES = [
        'card' => ['id' => 'text', 'image' => 'image', 'heading' => 'text', 'note' => 'text'],
        'audience' => ['title' => 'text', 'sub' => 'text', 'body' => 'textarea', 'cta' => 'text', 'link' => 'link'],
        'action' => [
            'label' => 'text',
            'href' => 'text',
            'tone' => ['type' => 'select', 'options' => ['solid', 'stroke']],
        ],
        'callout' => ['lead' => 'text', 'rest' => 'text'],
        'stat' => ['value' => 'text', 'label' => 'text', 'note' => 'text'],
        'feature' => ['image' => 'image', 'title' => 'text', 'body' => 'textarea', 'bg' => 'color', 'ink' => 'color'],
    ];

    /** @return array<string, array{label: string, hint: string, fields: array}> */
    public function all(): array
    {
        return [
            'hero_media' => [
                'label' => 'هیروِ صفحه‌ی اول',
                'hint' => 'عکسِ تمام‌عرضِ بالای صفحه، با تیتر و زیرتیترِ روی آن. اولین چیزی که بازدیدکننده می‌بیند.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'subtitle' => ['type' => 'textarea'],
                ],
            ],

            'video' => [
                'label' => 'ویدیو',
                'hint' => 'ویدیوی تمام‌عرض؛ تا پیش از پخش، عکسِ پوستر دیده می‌شود.',
                'fields' => [
                    'src' => ['type' => 'text', 'required' => true],
                    'poster' => ['type' => 'image'],
                    'title' => ['type' => 'text'],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'story_card' => [
                'label' => 'کارت داستانی',
                'hint' => 'کارت عکس که با کلیک به پنلِ متنی برمی‌گردد. پنجره‌ی هوم.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'captionFa' => ['type' => 'text', 'max' => 120],
                    'captionEn' => ['type' => 'text', 'max' => 120],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'lede' => ['type' => 'textarea', 'max' => 600],
                    'paragraphs' => ['type' => 'list', 'of' => 'textarea'],
                    'items' => ['type' => 'list', 'of' => 'text'],
                    'link' => ['type' => 'link'],
                ],
            ],

            'image_glass_band' => [
                'label' => 'عکس با نوارِ شیشه‌ای',
                'hint' => 'عکسِ تمام‌عرض با یک نوارِ شیشه‌ای روی آن؛ هر جمله یک خطِ نوار است.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'eyebrow' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'lines' => ['type' => 'list', 'of' => 'textarea'],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'callout_list' => [
                'label' => 'فهرستِ جملاتِ کوتاه',
                'hint' => 'جمله‌ها یکی‌یکی با اسکرول ظاهر می‌شوند. کاراکترِ «ـ» داخلِ متن، کشیدگیِ طراحی است — پاکش نکنید.',
                'fields' => [
                    'lines' => ['type' => 'list', 'of' => 'callout'],
                ],
            ],

            'glass_cards' => [
                'label' => 'کارت‌های شیشه‌ای',
                'hint' => 'چند کارتِ شیشه‌ای کنارِ هم، هرکدام برای یک مخاطب: عنوان، توضیح و یک لینک.',
                'fields' => [
                    'cards' => ['type' => 'list', 'of' => 'audience', 'required' => true],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'faq' => [
                'label' => 'سؤالات متداول',
                'hint' => 'آکاردئونِ سؤال‌ها. خودِ سؤال‌ها این‌جا نوشته نمی‌شوند — از دسته‌ی FAQ با همین اسلاگ خوانده می‌شوند.',
                'fields' => [
                    'title' => ['type' => 'text'],
                    'categorySlug' => ['type' => 'text', 'required' => true],
                ],
            ],

            'hero_actions' => [
                'label' => 'هیرو با دکمه',
                'hint' => 'هیروِ عکس‌دار با چند جمله و یک یا دو دکمه‌ی فراخوان روی آن.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'subtitle' => ['type' => 'text'],
                    'lines' => ['type' => 'list', 'of' => 'textarea'],
                    'actions' => ['type' => 'list', 'of' => 'action', 'max' => 2],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'card_grid' => [
                'label' => 'شبکه‌ی کارت',
                'hint' => 'چند کارتِ عکس + عنوان + زیرنویس، با تونِ روشن/تیره و جهتِ چیدمان.',
                'fields' => [
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'tone' => ['type' => 'select', 'options' => ['light', 'dark'], 'required' => true],
                    'flow' => ['type' => 'select', 'options' => ['rtl', 'ltr'], 'required' => true],
                    'cards' => ['type' => 'list', 'of' => 'card'],
                ],
            ],

            'media_text' => [
                'label' => 'عکس و متن',
                'hint' => 'عکس در یک سو و عنوان با چند پاراگراف در سوی دیگر.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'body' => ['type' => 'list', 'of' => 'textarea', 'required' => true],
                ],
            ],

            'flip_card' => [
                'label' => 'کارتِ برگردان',
                'hint' => 'یک کارتِ عکس که با کلیک برمی‌گردد و پاراگراف‌های پشتش را نشان می‌دهد.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'paragraphs' => ['type' => 'list', 'of' => 'textarea', 'required' => true],
                ],
            ],

            'cta_band' => [
                'label' => 'نوارِ پایانی',
                'hint' => 'عکسِ تمام‌عرض با متن و یک دکمه روی آن.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text', 'max' => 160],
                    'title' => ['type' => 'text', 'required' => true, 'max' => 160],
                    'lead' => ['type' => 'textarea', 'max' => 400],
                    'action' => ['type' => 'link'],
                    'fineprint' => ['type' => 'textarea', 'max' => 400],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'sk_hero' => [
                'label' => 'هیروِ فولاد کویر',
                'hint' => 'هیروِ عکس‌دارِ صفحه‌ی فولاد کویر، با یک تیترِ لاتینِ کوچک بالای تیترِ اصلی.',
                'fields' => [
                    'image' => ['type' => 'image', 'required' => true],
                    'alt' => ['type' => 'text'],
                    'title' => ['type' => 'text', 'required' => true],
                    'eyebrowEn' => ['type' => 'text'],
                    'lines' => ['type' => 'list', 'of' => 'textarea'],
                    'glass' => ['type' => 'glass'],
                ],
            ],

            'stat_carousel' => [
                'label' => 'چرخ‌وفلکِ کارت',
                'hint' => 'کارت‌های عددی که افقی می‌چرخند: هر کارت یک عدد، یک برچسب و یک توضیحِ کوتاه.',
                'fields' => [
                    'title' => ['type' => 'text', 'required' => true],
                    'cards' => ['type' => 'list', 'of' => 'stat', 'required' => true],
                ],
            ],

            'feature_cards' => [
                'label' => 'دو کارتِ محصول',
                'hint' => 'کارت‌های بزرگِ محصول، هرکدام با رنگِ پس‌زمینه و رنگِ متنِ خودش.',
                'fields' => [
                    'cards' => ['type' => 'list', 'of' => 'feature', 'required' => true],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->all());
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->all());
    }

    /** @return array{label: string, hint: string, fields: array} */
    public function for(string $type): array
    {
        return $this->all()[$type] ?? ['label' => '', 'hint' => '', 'fields' => []];
    }

    /**
     * قواعدِ لاراول، ساخته‌شده از همان اسکیمایی که فرمِ پنل را می‌سازد.
     *
     * نوعِ ناشناخته آرایه‌ی خالی می‌دهد و **نه** «هر چیزی مجاز است» — کنترلر
     * پیش از این متد خودِ `type` را با `Rule::in()` رد می‌کند.
     */
    public function rulesFor(string $type): array
    {
        if (! $this->has($type)) {
            return [];
        }

        $rules = [];

        foreach ($this->for($type)['fields'] as $name => $field) {
            $key = 'data.'.$name;
            $isRequired = (bool) ($field['required'] ?? false);
            $required = $isRequired ? 'required' : 'nullable';

            match ($field['type']) {
                'list' => $rules += $this->listRules($key, $field, $isRequired),
                'link' => $rules += [
                    $key => [$required, 'array'],
                    $key.'.label' => ['nullable', 'string', 'max:120'],
                    $key.'.to' => ['nullable', 'string', 'max:2048'],
                ],
                'glass' => $rules[$key] = [$required, 'array'],
                'number' => $rules[$key] = [$required, 'numeric'],
                'boolean' => $rules[$key] = [$required, 'boolean'],
                'select' => $rules[$key] = [$required, 'string', Rule::in($this->selectOptions($field, "{$type}.{$name}"))],
                'image' => $rules[$key] = [$required, 'string', 'max:2048'],
                default => $rules[$key] = [$required, 'string', 'max:'.($field['max'] ?? 5000)],
            };
        }

        return $rules;
    }

    /**
     * فهرستِ رشته‌ای یک قاعده می‌گیرد؛ فهرستِ آبجکتی، یکی به ازای هر کلیدِ شکل.
     *
     * `$required` همان پرچمِ خودِ فیلد است — قاعده‌ی سطحِ آرایه باید دقیقاً همان
     * چیزی را بگوید که اسکیما توصیف کرده، نه همیشه «nullable».
     */
    private function listRules(string $key, array $field, bool $required): array
    {
        $outerRules = [$required ? 'required' : 'nullable', 'array'];

        if (isset($field['max'])) {
            $outerRules[] = 'max:'.$field['max'];
        }

        $shape = self::SHAPES[$field['of']] ?? null;

        if ($shape === null) {
            return [$key => $outerRules, $key.'.*' => ['required', 'string', 'max:5000']];
        }

        $rules = [$key => $outerRules];

        foreach ($shape as $name => $member) {
            $memberKey = $key.'.*.'.$name;

            if (is_array($member)) {
                // تنها شکلِ آرایه‌ایِ فعلی، select با options است — همان‌طور که
                // بالای SHAPES مستند شده. ویجتِ آرایه‌ایِ دیگری اضافه شد، این
                // شاخه هم باید عمومی شود.
                $rules[$memberKey] = ['nullable', 'string', Rule::in($this->selectOptions($member, "{$field['of']}.{$name}"))];

                continue;
            }

            $rules[$memberKey] = $member === 'link'
                ? ['nullable', 'array']
                : ['nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    /**
     * گزینه‌های یک فیلدِ select را برمی‌گرداند؛ نبودنشان خطای برنامه‌نویس است،
     * نه ورودیِ کاربر — پس با استثنا متوقف می‌شود، نه با قاعده‌ای که خاموش
     * همه‌چیز را رد می‌کند (`Rule::in(null)`).
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function selectOptions(array $field, string $context): array
    {
        $options = $field['options'] ?? null;

        if (empty($options)) {
            throw new \InvalidArgumentException(
                "فیلدِ select با نامِ «{$context}» گزینه‌ای (options) تعریف نکرده — این خطای برنامه‌نویس است."
            );
        }

        return $options;
    }
}
