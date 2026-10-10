<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option controls the default authentication "guard" and password
    | reset options for your application. You may change these defaults
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | here which uses session storage and the Eloquent user provider.
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UserResource Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | If you have multiple user tables or models you may configure multiple
    | sources which represent each model / table. These sources may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | You may specify multiple password reset configurations if you have more
    | than one user table or model in the application and you want to have
    | separate password reset settings based on the specific user types.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | times out and the user is prompted to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => 10800,

    /*
    |--------------------------------------------------------------------------
    | OTP Brute-Force Protection
    |--------------------------------------------------------------------------
    |
    | Cumulative lockout for OTP/reset-code verification. After
    | `otp_max_attempts` consecutive failures for a mobile number, further
    | verification attempts are rejected for `otp_lockout_minutes`.
    | The per-minute route throttle alone is not enough against a
    | distributed brute-force of a 6-digit code.
    |
    */

    'otp_max_attempts' => env('OTP_MAX_ATTEMPTS', 5),

    'otp_lockout_minutes' => env('OTP_LOCKOUT_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Password-Login Lockout
    |--------------------------------------------------------------------------
    |
    | Same mechanism as the OTP lockout but in its own namespace. Both paths
    | used to share one `otp_lock:{mobile}` key, so anyone who merely knew a
    | mobile number could spam wrong OTPs and lock that user out of password
    | login too — a denial-of-service needing no other information.
    |
    | Defaults intentionally match the OTP values: this change is about
    | isolating the two counters, not about retuning the thresholds. Tune them
    | separately here if password attempts should be more (or less) forgiving.
    |
    */

    'password_max_attempts' => env('PASSWORD_MAX_ATTEMPTS', 5),

    'password_lockout_minutes' => env('PASSWORD_LOCKOUT_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Password Reset Token Lifetime
    |--------------------------------------------------------------------------
    |
    | After the forget-password OTP is verified, a one-time reset token is
    | issued in its place. The token is only valid for this many minutes —
    | an unlimited-lifetime token could be replayed long after a leak.
    |
    */

    'password_reset_ttl_minutes' => env('PASSWORD_RESET_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Role-Selection Gate (EnsureRoleIsSelected)
    |--------------------------------------------------------------------------
    |
    | The spec requires checkout to be blocked until the user has picked a role
    | (کارفرما / الیت یار / سوپلایر), with a grace window measured from
    | registration. The middleware existed but was dead code: its guard tested
    | `type_id !== null`, and register() always stamps the placeholder `customer`
    | type, so the gate never fired.
    |
    | The guard is fixed, but the gate stays OPT-IN: switching it on turns
    | checkout into a 403 for role-less users, which the frontend must handle
    | (codes ROLE_NOT_SELECTED / REGISTRATION_WINDOW_EXPIRED). Enable it only
    | once the client implements that flow.
    |
    */

    'role_selection_gate_enabled' => (bool) env('ROLE_SELECTION_GATE_ENABLED', false),

    'role_selection_window_hours' => (int) env('ROLE_SELECTION_WINDOW_HOURS', 2),

    /*
    | خروجِ خودکار پس از بی‌کاری (تایمرِ سمتِ سایت) — علی ۱۴۰۵/۰۷/۱۷: یک هفته.
    | فقط پیش‌فرض است؛ ادمین با کلیدِ `session_idle_logout_minutes` در «تنظیمات سیستم»
    | عوضش می‌کند و سایت مقدارِ حل‌شده را از `GET /settings/public` می‌خواند.
    */
    'session_idle_logout_minutes' => (int) env('SESSION_IDLE_LOGOUT_MINUTES', 10080),

    /*
    |--------------------------------------------------------------------------
    | Non-Production OTP Shortcut
    |--------------------------------------------------------------------------
    |
    | OPT-IN. When OTP_DEBUG_CODE is set AND the environment is not production,
    | generateVerifyCode() returns this fixed code instead of a random one, so a
    | local clone can log in without a real SMS. Unset (the default) → random.
    | Production ALWAYS uses a fresh CSPRNG code, even if this is set.
    |
    | ⚠️ There is deliberately NO default. It used to default to '111111', which
    | applied to every non-production env — and staging runs APP_ENV=local
    | against real users, so anyone who knew a mobile number could log in as
    | it. Staging logs in with real SMS to TSMS_DEV_ALLOWLIST numbers instead;
    | never set OTP_DEBUG_CODE on a server that holds real accounts.
    |
    */

    'otp_debug_code' => env('OTP_DEBUG_CODE'),

    /*
    | «حسابِ مجازیِ کارفرما» — مدیریت، دورِ هفتم، پرسشِ ۷-۵. پس از پرداخت، کارفرمای مجازی پیامکی با «لینک تایید حساب
    | و لاگین» می‌گیرد. `claim_url` صفحه‌ی سایت است که امضا را به `POST /api/authenticate/claim/{user}` می‌فرستد؛
    | **خالی = پیامک فرستاده نمی‌شود**؛ پیش‌فرض صفحه‌ی `/claim`ِ سایت است (S4 ساختش). ورود با رمزِ پیامکی
    | (check-user) برای این حساب از قبل کار می‌کند؛ لینک فقط راهِ کوتاه است.
    */
    'virtual_account' => [
        // پیش‌فرض (BE3): صفحه‌ی `/claim`ِ سایت روی `FRONT_APP_URL` (همان پیش‌فرضِ `app.front_url`). فقط مقدارِ
        // صریحاً خالیِ `VIRTUAL_ACCOUNT_CLAIM_URL=` پیامک را خاموش می‌کند.
        'claim_url' => (string) env('VIRTUAL_ACCOUNT_CLAIM_URL', rtrim((string) env('FRONT_APP_URL', 'http://localhost:3000'), '/').'/claim'),
        'claim_link_days' => (int) env('VIRTUAL_ACCOUNT_CLAIM_LINK_DAYS', 7),
    ],

];
