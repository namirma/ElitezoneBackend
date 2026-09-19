<?php

/*
|--------------------------------------------------------------------------
| پیام‌های اعتبارسنجی (فارسی)
|--------------------------------------------------------------------------
|
| این فایل قبلاً وجود نداشت. app.locale = fa و app.fallback_locale = en بود،
| پس هر قاعده‌ی اعتبارسنجی که messages() اختصاصی نداشت به lang/en/validation.php
| برمی‌گشت و پیام انگلیسی به کاربر می‌داد — با نام فیلدِ فارسی قاطیِ متن انگلیسی
| («The selected آدرس گیرنده is invalid.»).
|
| بیش از ۹۰ فراخوانیِ inline `$request->validate()` در کنترلرها هیچ messages()
| ندارند (کلِ پنل خودسرویس تأمین‌کننده و بیشترِ پنل ادمین)، پس این فایل تنها
| چیزی است که آن مسیرها را فارسی می‌کند.
|
| FormRequestها همچنان می‌توانند messages() اختصاصی داشته باشند؛ آن‌ها بر این
| فایل اولویت دارند. این‌جا فقط پیش‌فرضِ سالم است.
|
*/

return [

    'accepted' => 'فیلد :attribute باید پذیرفته شود.',
    'accepted_if' => 'فیلد :attribute وقتی :other برابر :value است باید پذیرفته شود.',
    'active_url' => 'فیلد :attribute یک نشانی اینترنتی معتبر نیست.',
    'after' => 'فیلد :attribute باید تاریخی بعد از :date باشد.',
    'after_or_equal' => 'فیلد :attribute باید تاریخی بعد از :date یا برابر با آن باشد.',
    'alpha' => 'فیلد :attribute باید فقط شامل حروف باشد.',
    'alpha_dash' => 'فیلد :attribute باید فقط شامل حروف، اعداد، خط تیره و زیرخط باشد.',
    'alpha_num' => 'فیلد :attribute باید فقط شامل حروف و اعداد باشد.',
    'array' => 'فیلد :attribute باید یک آرایه باشد.',
    'ascii' => 'فیلد :attribute باید فقط شامل حروف و نمادهای تک‌بایتی باشد.',
    'before' => 'فیلد :attribute باید تاریخی قبل از :date باشد.',
    'before_or_equal' => 'فیلد :attribute باید تاریخی قبل از :date یا برابر با آن باشد.',

    'between' => [
        'array' => 'فیلد :attribute باید بین :min تا :max آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید بین :min تا :max کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید بین :min تا :max باشد.',
        'string' => 'طول :attribute باید بین :min تا :max کاراکتر باشد.',
    ],

    'boolean' => 'فیلد :attribute باید درست یا نادرست باشد.',
    'can' => 'فیلد :attribute شامل مقدار غیرمجاز است.',
    'confirmed' => 'تکرار :attribute با مقدار واردشده مطابقت ندارد.',
    'current_password' => 'رمز عبور واردشده صحیح نیست.',
    'date' => 'فیلد :attribute یک تاریخ معتبر نیست.',
    'date_equals' => 'فیلد :attribute باید تاریخی برابر با :date باشد.',
    'date_format' => 'فیلد :attribute با قالب :format مطابقت ندارد.',
    'decimal' => 'فیلد :attribute باید :decimal رقم اعشار داشته باشد.',
    'declined' => 'فیلد :attribute باید رد شود.',
    'declined_if' => 'فیلد :attribute وقتی :other برابر :value است باید رد شود.',
    'different' => 'فیلد :attribute و :other باید متفاوت باشند.',
    'digits' => 'فیلد :attribute باید :digits رقم باشد.',
    'digits_between' => 'فیلد :attribute باید بین :min تا :max رقم باشد.',
    'dimensions' => 'ابعاد تصویر :attribute مجاز نیست.',
    'distinct' => 'فیلد :attribute مقدار تکراری دارد.',
    'doesnt_end_with' => 'فیلد :attribute نباید با یکی از این مقادیر تمام شود: :values.',
    'doesnt_start_with' => 'فیلد :attribute نباید با یکی از این مقادیر شروع شود: :values.',
    'email' => 'فیلد :attribute باید یک نشانی ایمیل معتبر باشد.',
    'ends_with' => 'فیلد :attribute باید با یکی از این مقادیر تمام شود: :values.',
    'enum' => 'مقدار انتخاب‌شده برای :attribute معتبر نیست.',
    'exists' => 'مقدار انتخاب‌شده برای :attribute معتبر نیست.',
    'extensions' => 'فیلد :attribute باید یکی از این پسوندها را داشته باشد: :values.',
    'file' => 'فیلد :attribute باید یک فایل باشد.',
    'filled' => 'فیلد :attribute نمی‌تواند خالی باشد.',

    'gt' => [
        'array' => 'فیلد :attribute باید بیشتر از :value آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید بیشتر از :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید بیشتر از :value باشد.',
        'string' => 'طول :attribute باید بیشتر از :value کاراکتر باشد.',
    ],

    'gte' => [
        'array' => 'فیلد :attribute باید :value آیتم یا بیشتر داشته باشد.',
        'file' => 'حجم فایل :attribute باید حداقل :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید حداقل :value باشد.',
        'string' => 'طول :attribute باید حداقل :value کاراکتر باشد.',
    ],

    'image' => 'فیلد :attribute باید یک تصویر باشد.',
    'in' => 'مقدار انتخاب‌شده برای :attribute معتبر نیست.',
    'in_array' => 'فیلد :attribute در :other وجود ندارد.',
    'integer' => 'فیلد :attribute باید یک عدد صحیح باشد.',
    'ip' => 'فیلد :attribute باید یک نشانی IP معتبر باشد.',
    'ipv4' => 'فیلد :attribute باید یک نشانی IPv4 معتبر باشد.',
    'ipv6' => 'فیلد :attribute باید یک نشانی IPv6 معتبر باشد.',
    'json' => 'فیلد :attribute باید یک رشته‌ی JSON معتبر باشد.',
    'lowercase' => 'فیلد :attribute باید با حروف کوچک باشد.',

    'lt' => [
        'array' => 'فیلد :attribute باید کمتر از :value آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید کمتر از :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید کمتر از :value باشد.',
        'string' => 'طول :attribute باید کمتر از :value کاراکتر باشد.',
    ],

    'lte' => [
        'array' => 'فیلد :attribute نباید بیشتر از :value آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute نباید بیشتر از :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute نباید بیشتر از :value باشد.',
        'string' => 'طول :attribute نباید بیشتر از :value کاراکتر باشد.',
    ],

    'mac_address' => 'فیلد :attribute باید یک آدرس MAC معتبر باشد.',

    'max' => [
        'array' => 'فیلد :attribute نباید بیشتر از :max آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute نباید بیشتر از :max کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute نباید بیشتر از :max باشد.',
        'string' => 'طول :attribute نباید بیشتر از :max کاراکتر باشد.',
    ],

    'max_digits' => 'فیلد :attribute نباید بیشتر از :max رقم داشته باشد.',
    'mimes' => 'فیلد :attribute باید فایلی از نوع :values باشد.',
    'mimetypes' => 'فیلد :attribute باید فایلی از نوع :values باشد.',

    'min' => [
        'array' => 'فیلد :attribute باید حداقل :min آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید حداقل :min کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید حداقل :min باشد.',
        'string' => 'طول :attribute باید حداقل :min کاراکتر باشد.',
    ],

    'min_digits' => 'فیلد :attribute باید حداقل :min رقم داشته باشد.',
    'missing' => 'فیلد :attribute نباید ارسال شود.',
    'missing_if' => 'فیلد :attribute وقتی :other برابر :value است نباید ارسال شود.',
    'missing_unless' => 'فیلد :attribute نباید ارسال شود مگر آنکه :other برابر :value باشد.',
    'missing_with' => 'فیلد :attribute وقتی :values ارسال شده باشد نباید ارسال شود.',
    'missing_with_all' => 'فیلد :attribute وقتی :values ارسال شده باشند نباید ارسال شود.',
    'multiple_of' => 'مقدار :attribute باید مضربی از :value باشد.',
    'not_in' => 'مقدار انتخاب‌شده برای :attribute معتبر نیست.',
    'not_regex' => 'قالب فیلد :attribute معتبر نیست.',
    'numeric' => 'فیلد :attribute باید یک عدد باشد.',

    'password' => [
        'letters' => 'فیلد :attribute باید حداقل یک حرف داشته باشد.',
        'mixed' => 'فیلد :attribute باید حداقل یک حرف بزرگ و یک حرف کوچک داشته باشد.',
        'numbers' => 'فیلد :attribute باید حداقل یک عدد داشته باشد.',
        'symbols' => 'فیلد :attribute باید حداقل یک نماد داشته باشد.',
        'uncompromised' => 'مقدار واردشده برای :attribute در نشت اطلاعات دیده شده است. لطفاً مقدار دیگری انتخاب کنید.',
    ],

    'present' => 'فیلد :attribute باید ارسال شود.',
    'present_if' => 'فیلد :attribute وقتی :other برابر :value است باید ارسال شود.',
    'present_unless' => 'فیلد :attribute باید ارسال شود مگر آنکه :other برابر :value باشد.',
    'present_with' => 'فیلد :attribute وقتی :values ارسال شده باشد باید ارسال شود.',
    'present_with_all' => 'فیلد :attribute وقتی :values ارسال شده باشند باید ارسال شود.',
    'prohibited' => 'ارسال فیلد :attribute مجاز نیست.',
    'prohibited_if' => 'ارسال فیلد :attribute وقتی :other برابر :value است مجاز نیست.',
    'prohibited_unless' => 'ارسال فیلد :attribute مجاز نیست مگر آنکه :other یکی از مقادیر :values باشد.',
    'prohibits' => 'فیلد :attribute باعث می‌شود ارسال :other مجاز نباشد.',
    'regex' => 'قالب فیلد :attribute معتبر نیست.',
    'required' => 'فیلد :attribute الزامی است.',
    'required_array_keys' => 'فیلد :attribute باید شامل کلیدهای :values باشد.',
    'required_if' => 'فیلد :attribute وقتی :other برابر :value است الزامی است.',
    'required_if_accepted' => 'فیلد :attribute وقتی :other پذیرفته شده باشد الزامی است.',
    'required_unless' => 'فیلد :attribute الزامی است مگر آنکه :other یکی از مقادیر :values باشد.',
    'required_with' => 'فیلد :attribute وقتی :values ارسال شده باشد الزامی است.',
    'required_with_all' => 'فیلد :attribute وقتی :values ارسال شده باشند الزامی است.',
    'required_without' => 'فیلد :attribute وقتی :values ارسال نشده باشد الزامی است.',
    'required_without_all' => 'فیلد :attribute وقتی هیچ‌کدام از :values ارسال نشده باشند الزامی است.',
    'same' => 'مقدار :attribute و :other باید یکسان باشند.',

    'size' => [
        'array' => 'فیلد :attribute باید دقیقاً :size آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید :size کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید :size باشد.',
        'string' => 'طول :attribute باید :size کاراکتر باشد.',
    ],

    'starts_with' => 'فیلد :attribute باید با یکی از این مقادیر شروع شود: :values.',
    'string' => 'فیلد :attribute باید یک رشته‌ی متنی باشد.',
    'timezone' => 'فیلد :attribute باید یک منطقه‌ی زمانی معتبر باشد.',
    'unique' => 'مقدار :attribute قبلاً ثبت شده است.',
    'uploaded' => 'آپلود فایل :attribute ناموفق بود.',
    'uppercase' => 'فیلد :attribute باید با حروف بزرگ باشد.',
    'url' => 'فیلد :attribute باید یک نشانی اینترنتی معتبر باشد.',
    'ulid' => 'فیلد :attribute باید یک ULID معتبر باشد.',
    'uuid' => 'فیلد :attribute باید یک UUID معتبر باشد.',

    /*
    |--------------------------------------------------------------------------
    | پیام‌های اختصاصی هر فیلد
    |--------------------------------------------------------------------------
    | ساختار: 'attribute.rule' => 'پیام'
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | نام نمایشی فیلدها
    |--------------------------------------------------------------------------
    | جای :attribute در پیام‌های بالا می‌نشیند. FormRequestهایی که attributes()
    | خودشان را دارند بر این فهرست اولویت دارند؛ این‌جا پوششِ مسیرهای
    | inline validate() است که هیچ attributes() ندارند.
    */

    'attributes' => [
        'amount' => 'مبلغ',
        'code' => 'کد',
        'coupon_code' => 'کد تخفیف',
        'description' => 'توضیحات',
        'email' => 'ایمیل',
        'file' => 'فایل',
        'image' => 'تصویر',
        'is_active' => 'وضعیت فعال بودن',
        'logo' => 'لوگو',
        'mobile' => 'شماره موبایل',
        'name' => 'نام',
        'national_code' => 'کد ملی',
        'password' => 'رمز عبور',
        'per_page' => 'تعداد در صفحه',
        'phone' => 'شماره تماس',
        'price' => 'قیمت',
        'quantity' => 'تعداد',
        'reason' => 'دلیل',
        'slug' => 'شناسه‌ی یکتا',
        'status' => 'وضعیت',
        'title' => 'عنوان',
        'username' => 'نام کاربری',
        'weight' => 'وزن',

        // catalog / pricing
        'factory_id' => 'کارخانه',
        'factory_location_id' => 'انبار/مبدأ',
        'product_variant_id' => 'واریانت محصول',
        'general_price' => 'قیمت عمومی',
        'special_price' => 'قیمت ویژه',
        'golden_figure' => 'رقم طلایی',
        'cost_basis' => 'مبنای محاسبه‌ی هزینه',
        'flat_cost' => 'هزینه‌ی ثابت',
        'per_kg_cost' => 'هزینه‌ی هر کیلوگرم',
        'per_count_cost' => 'هزینه‌ی هر واحد',
        'destination_province_id' => 'استان مقصد',
        'destination_city_id' => 'شهر مقصد',

        // orders
        'address_id' => 'آدرس گیرنده',
        'shipping_method_id' => 'روش ارسال',
        'order_id' => 'سفارش',
        'weight_after_scale' => 'وزن باسکول',
        'receipt' => 'فیش پرداخت',
        'receipt_image' => 'تصویر فیش',

        // dates
        'birth_date' => 'تاریخ تولد',
        'start_date' => 'تاریخ شروع',
        'end_date' => 'تاریخ پایان',
        'from' => 'از تاریخ',
        'to' => 'تا تاریخ',
    ],

];
