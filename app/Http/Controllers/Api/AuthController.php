<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\RequestOtpAction;
use App\Actions\Auth\VerifyIdentityWithPodiumAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreUserRequest;
use App\Http\Resources\Profile\UserResource;
use App\Jobs\Auth\SyncIdentityToProfileJob;
use App\Jobs\Notification\SendSmsJob;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserType;
use App\Support\OtpSecret;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** قفل تجمعیِ کدِ یک‌بارمصرف (ثبت‌نام، ورود با کد، بازیابی رمز). */
    private const SCOPE_OTP = 'otp';

    /** قفل تجمعیِ ورود با رمز عبور — فضای نامِ جدا از OTP. */
    private const SCOPE_PASSWORD = 'password';

    /** نام پیش‌فرض توکن، وقتی کلاینت `device_name` نمی‌فرستد (سازگاری عقب‌رو). */
    private const DEFAULT_DEVICE = 'auth';

    /**
     * توکن تازه می‌سازد و **فقط توکنِ قبلیِ همان کلاینت** را باطل می‌کند.
     *
     * ⚠️ قبلاً اینجا `$user->tokens()->delete()` بود — یعنی هر ورود، تمامِ
     * نشست‌های آن کاربر را می‌کشت. چون ادمین با یک حساب هم در بک‌آفیس
     * (پورت 5174) و هم در سایت اصلی (5173) وارد می‌شود، ورود به یکی
     * دیگری را با «نشست شما به پایان رسید» بیرون می‌انداخت — و برعکس؛
     * عملاً فقط یکی از دو برنامه هم‌زمان قابل استفاده بود.
     *
     * خاصیتِ امنیتیِ «یک نشستِ فعال به‌ازای هر کلاینت» حفظ می‌شود: ورودِ دوباره
     * در همان برنامه، نشستِ قبلیِ همان برنامه را باطل می‌کند. کلاینتی که
     * `device_name` نفرستد نامِ پیش‌فرض `auth` می‌گیرد، پس رفتار قدیمی نمی‌شکند.
     *
     * (تغییر رمز عبور جداست و عمداً **همه‌ی** توکن‌ها را باطل می‌کند —
     *  اعتبارنامه عوض شده، پس هر نشستی باید بیرون بیفتد.)
     */
    private function issueTokenForDevice(User $user, Request $request): string
    {
        $device = trim((string) $request->input('device_name')) ?: self::DEFAULT_DEVICE;
        $device = mb_substr($device, 0, 60);

        $user->tokens()->where('name', $device)->delete();

        return $user->createToken($device)->plainTextToken;
    }

    /**
     * قفل تجمعی — مکمل throttle دقیقه‌ای روت، چون هم کد ۶ رقمی و هم رمز عبور
     * با IPهای متعدد قابل brute-force اند.
     *
     * دو اسکوپ عمداً از هم جدا شده‌اند: تا پیش از این هر دو مسیر یک کلیدِ
     * `otp_lock:{mobile}` مشترک داشتند، یعنی کسی که فقط شمارهٔ موبایلِ قربانی را
     * می‌دانست می‌توانست با چند کدِ اشتباه، *ورود با رمزِ* او را هم قفل کند —
     * یک بردار DoS بدون نیاز به هیچ اطلاعات دیگری. حالا شکستِ OTP فقط OTP را
     * قفل می‌کند و شکستِ رمز فقط رمز را.
     */
    private function isLocked(string $mobile, string $scope): bool
    {
        return Cache::has($this->lockKey($mobile, $scope));
    }

    private function recordFailure(string $mobile, string $scope): void
    {
        $failKey = $this->failKey($mobile, $scope);
        $lockMinutes = $this->lockoutMinutes($scope);

        $fails = Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $fails, now()->addMinutes($lockMinutes));

        if ($fails >= $this->maxAttempts($scope)) {
            Cache::put($this->lockKey($mobile, $scope), true, now()->addMinutes($lockMinutes));
            Cache::forget($failKey);
        }
    }

    private function clearFailures(string $mobile, string $scope): void
    {
        Cache::forget($this->failKey($mobile, $scope));
        Cache::forget($this->lockKey($mobile, $scope));
    }

    private function lockKey(string $mobile, string $scope): string
    {
        return "auth_lock:{$scope}:{$mobile}";
    }

    private function failKey(string $mobile, string $scope): string
    {
        return "auth_fails:{$scope}:{$mobile}";
    }

    private function maxAttempts(string $scope): int
    {
        return $scope === self::SCOPE_PASSWORD
            ? (int) config('auth.password_max_attempts', 5)
            : (int) config('auth.otp_max_attempts', 5);
    }

    private function lockoutMinutes(string $scope): int
    {
        return $scope === self::SCOPE_PASSWORD
            ? (int) config('auth.password_lockout_minutes', 10)
            : (int) config('auth.otp_lockout_minutes', 10);
    }

    /** پیام ۴۲۹ متناسب با اسکوپ — «کد» و «رمز» دو تجربهٔ متفاوت‌اند. */
    private function tooManyAttempts(string $scope): JsonResponse
    {
        return error(
            $scope === self::SCOPE_PASSWORD
                ? __('auth.too_many_password_attempts')
                : __('auth.too_many_otp_attempts'),
            [],
            429
        );
    }

    /**
     * کاربر توسط ادمین غیرفعال شده؟ (is_active = false)
     * NULL (ردیف‌های بدون backfill) فعال تلقی می‌شود.
     */
    private function isDisabled(User $user): bool
    {
        return $user->{User::IS_ACTIVE} === false;
    }

    /**
     * نرمال‌سازی شماره موبایل قبل از validate — دیتابیس فرمت کانونیکال 09 را
     * نگه می‌دارد، پس رول exists باید روی همان فرمت اجرا شود نه ورودی خام.
     */
    private function normalizeUsername(Request $request): void
    {
        if (is_string($request->input('username'))) {
            $request->merge(['username' => PhoneNumber::to09($request->input('username'))]);
        }
    }

    public function me(Request $request)
    {
        // roles پشتِ whenLoaded است و permissions هم برای پنلِ ادمین لازم است —
        // بدون این eager load، /user نه نقش برمی‌گرداند نه مجوز، و پنل از وقتی
        // EnsureAdminHasPermission به fail-closed تغییر کرد راهی برای تصمیم‌گیری
        // دربارهٔ نمایشِ تب‌ها نداشت جز خوردنِ ۴۰۳.
        return success(new UserResource(
            $request->user()->load(['profile', 'type', 'roles', 'permissions'])
        ));
    }

    public function register(StoreUserRequest $request): JsonResponse
    {
        $mobile = PhoneNumber::to09($request->validated('username'));
        $nationalCode = (string) $request->validated('national_code');
        $birthDate = str_replace('-', '', (string) $request->validated('birth_date'));

        $user = User::where(User::MOBILE, $mobile)->first();

        if ($user && $this->isDisabled($user)) {
            return error(__('auth.account_disabled'), [], 403);
        }

        if ($user && ! empty($user->{User::NATIONAL_CODE})) {
            if ($user->{User::PASSWORD}) {
                return success(['action' => 'login_with_password'], __('auth.user_has_password'));
            }
            if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
                return success([], __('auth.code_sent_recently'), 202);
            }
            app(RequestOtpAction::class)->execute($user, $mobile);

            return success([], __('auth.verification_code_sent_successfully'), 202);
        }

        // Delegate Podium identity verification to a dedicated Action
        $verifyAction = app(VerifyIdentityWithPodiumAction::class);
        $errorKey = $verifyAction->execute($mobile, $nationalCode, $birthDate);

        if ($errorKey) {
            $httpStatus = str_contains($errorKey, 'check_failed') ? 502 : 422;

            return error(__($errorKey), [], $httpStatus);
        }

        // Create or update the user record
        if ($user) {
            $user->update([
                User::NATIONAL_CODE => $nationalCode,
                User::BIRTH_DATE => $birthDate,
            ]);
        } else {
            // ثبت‌نام نقش را انتخاب نمی‌کند، پس `type_id` تا مرحلهٔ انتخابِ نقش
            // NULL می‌ماند. اینجا قبلاً یک UserTypeِ ساختگی («مشتری») نشانده
            // می‌شد تا ستونِ NOT NULL پر شود؛ نتیجه‌اش این بود که «هنوز نقشی
            // انتخاب نکرده» و «نقشش مشتری است» از هم قابلِ تفکیک نبودند.
            $user = User::create([
                User::MOBILE => $mobile,
                User::NATIONAL_CODE => $nationalCode,
                User::BIRTH_DATE => $birthDate,
                User::TYPE_ID => null,
            ]);
        }

        // Generate OTP and send SMS via dedicated Action
        app(RequestOtpAction::class)->execute($user, $mobile);

        return success([], __('auth.verification_code_sent_successfully'), 202);
    }

    public function checkUserAndSendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ]);

        $mobile = PhoneNumber::to09($validated['username']);
        $user = User::where(User::MOBILE, $mobile)->first();

        if ($user) {
            if ($this->isDisabled($user)) {
                return error(__('auth.account_disabled'), [], 403);
            }

            if (empty($user->{User::NATIONAL_CODE}) || empty($user->{User::BIRTH_DATE})) {
                return error(
                    __('auth.profile_incomplete'),
                    ['action' => 'register', 'mobile' => $mobile],
                    404
                );
            }

            if ($user->{User::PASSWORD}) {
                return success(['action' => 'login_with_password'], __('auth.user_has_password'));
            }

            if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
                return success(['action' => 'login_with_code'], __('auth.code_sent_recently'), 202);
            }

            app(RequestOtpAction::class)->execute($user, $mobile);

            return success(['action' => 'login_with_code'], __('auth.verification_code_sent_successfully'));
        } else {
            return error(__('auth.no_user_found_register_required'), ['action' => 'register'], 404);
        }
    }

    public function types(): JsonResponse
    {
        return success(UserType::all(), __('global.information_received_successfully'));
    }

    public function verifyCode(StoreUserRequest $request): JsonResponse
    {
        $mobile = PhoneNumber::to09($request->validated('username'));

        if ($this->isLocked($mobile, self::SCOPE_OTP)) {
            return $this->tooManyAttempts(self::SCOPE_OTP);
        }

        // Load user by mobile only — comparing the OTP inside a SQL WHERE clause leaks
        // timing information via DB query latency. OtpSecret::matches (bcrypt) is
        // constant-time and the stored value is a hash, never the raw code.
        $user = User::where(User::MOBILE, $mobile)->first();

        $submittedCode = (string) ($request->validated('code') ?? '');

        if (
            ! $user
            || ! $user->{User::SECRET}
            || ! OtpSecret::matches($user->{User::SECRET}, $submittedCode)
            || Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()
        ) {
            $this->recordFailure($mobile, self::SCOPE_OTP);

            return error(__('auth.verification_code_not_match'), [], 404);
        }

        $this->clearFailures($mobile, self::SCOPE_OTP);

        if ($this->isDisabled($user)) {
            return error(__('auth.account_disabled'), [], 403);
        }

        $createdAt = $user->created_at instanceof Carbon ? $user->created_at : Carbon::parse($user->created_at);
        $isNewUser = $user->wasRecentlyCreated || $createdAt->diffInMinutes(now()) < 2;

        if ($request->has('forget_password') && $request->validated('forget_password')) {
            // کد ۶ رقمی همین‌جا مصرف می‌شود و یک توکن بازنشانی مجزا با پنجره‌ی
            // انقضای کوتاه جایگزین آن می‌شود — reset_token نباید نامحدود معتبر بماند.
            $resetToken = Str::random(64);
            $user->update([
                // فقط هش توکن ذخیره می‌شود؛ مقدار خام تنها در پاسخ همین درخواست است
                User::SECRET => OtpSecret::hash($resetToken),
                User::SECRET_EXPIRES_AT => now()->addMinutes(
                    (int) config('auth.password_reset_ttl_minutes', 15)
                ),
            ]);

            return success([
                'reset_token' => $resetToken,
            ], __('auth.code_verified_proceed_to_password'));
        }

        // کد یکبارمصرف است — بلافاصله پس از مصرف باطل می‌شود تا در پنجره‌ی انقضا قابل replay نباشد
        $user->update([
            User::SECRET => null,
            User::SECRET_EXPIRES_AT => null,
        ]);

        Auth::login($user);
        $roles = (array) $request->input('roles', []);
        $user = Auth::user();
        foreach ($roles as $role) {
            match ($role) {
                'engineer' => $user->engineer()->firstOrCreate([]),
                'contractor' => $user->contractor()->firstOrCreate([]),
                'employer' => $user->employer()->firstOrCreate([]),
                'supplier' => $user->supplier()->firstOrCreate([]),
                default => null,
            };
        }

        // صف، نه همزمان: این استعلام یک درخواست HTTP به پودیوم با timeout ۳۰
        // ثانیه است و برای موفقیتِ ورود لازم نیست. اجرای همزمانش یعنی وقتی
        // پودیوم کند باشد، ورودِ همهٔ کاربران هم کند می‌شود.
        SyncIdentityToProfileJob::dispatch($user->id);

        // ورود با کد یک‌بارمصرف — فقط نشستِ همین کلاینت جایگزین می‌شود
        $token = $this->issueTokenForDevice($user, $request);

        if ($isNewUser) {
            SendSmsJob::dispatch($mobile, __('sms.newRegisterUser'));

            UserNotification::create([
                UserNotification::USER_ID => Auth::id(),
                UserNotification::SUBJECT => __('global.newUserNotificationSubject'),
                UserNotification::BODY => __('global.newUserNotificationBody'),
            ]);
        }
        $userWithRelations = $user->fresh([
            User::RELATION_PROFILE,
            User::RELATION_TYPE,
            User::RELATION_ROLES,
            User::RELATION_BADGE,
        ]);

        return success([
            'user' => new UserResource($userWithRelations),
            'token' => $token,
        ], __('auth.register_success'));
    }

    public function checkPassword(Request $request)
    {
        // بدون این، `username`ِ نیامده به‌صورت null به `to09()` می‌رفت و
        // TypeError می‌داد — یعنی یک اندپوینتِ **عمومیِ** مسیرِ ورود روی بدنه‌ی
        // ناقص ۵۰۰ برمی‌گرداند به‌جای ۴۲۲. بدتر از خودِ کرش این بود که ۵۰۰ پیش
        // از `isLocked`/`recordFailure` می‌افتاد، پس تلاشِ ناموفق با بدنه‌ی ناقص
        // اصلاً شمرده نمی‌شد.
        //
        // عمداً `exists:users,mobile` نمی‌گذاریم — برخلافِ forgetPassword که آن
        // را دارد. این اندپوینت باید برای شماره‌ی ناموجود ۴۰۴ِ `no_user_found`
        // بدهد و `recordFailure` را هم بزند؛ `exists` آن مسیر را به ۴۲۲ تبدیل
        // می‌کرد و شمارنده‌ی قفل را دور می‌زد. شکلِ regex از خطِ ۲۲۶ همین کلاس.
        $validated = $request->validate([
            'username' => ['required', 'regex:/^(\+?98|0098|0)?9\d{9}$/'],
        ]);

        $mobile = PhoneNumber::to09($validated['username']);

        if ($this->isLocked($mobile, self::SCOPE_PASSWORD)) {
            return $this->tooManyAttempts(self::SCOPE_PASSWORD);
        }

        $user = User::where(User::MOBILE, $mobile)->first();

        if (! $user) {
            $this->recordFailure($mobile, self::SCOPE_PASSWORD);

            return error(__('auth.no_user_found'), [], 404);
        }
        if ($this->isDisabled($user)) {
            return error(__('auth.account_disabled'), [], 403);
        }
        if (empty($user->{User::NATIONAL_CODE}) || empty($user->{User::BIRTH_DATE})) {
            return error(
                __('auth.profile_incomplete'),
                ['action' => 'register', 'mobile' => $user->{User::MOBILE}],
                404
            );
        }
        // ‏`password` عمداً اجباری **نشده**: اگر اجباری بود، درخواستِ بدونِ رمز
        // ۴۲۲ می‌گرفت و از `recordFailure` رد می‌شد — یعنی می‌شد شمارنده‌ی قفل را
        // با فرستادنِ بدنه‌ی ناقص دور زد. این‌طوری تلاشِ ناقص هم یک تلاشِ ناموفق
        // حساب می‌شود.
        // کستِ `(string)` لازم است: `password_verify(null, …)` روی PHP 8.4
        // deprecation می‌دهد و روی PHP 9 همان TypeErrorِ بالا می‌شود.
        if (! Hash::check((string) $request->input('password'), $user->password)) {
            $this->recordFailure($mobile, self::SCOPE_PASSWORD);

            return error(__('auth.password_error'), [], 404);
        }

        $this->clearFailures($mobile, self::SCOPE_PASSWORD);
        Auth::login($user);
        // ورود با رمز عبور — فقط نشستِ همین کلاینت جایگزین می‌شود، نه همه‌ی برنامه‌ها
        $token = $this->issueTokenForDevice($user, $request);

        $userWithRelations = $user->fresh([
            User::RELATION_PROFILE,
            User::RELATION_TYPE,
            User::RELATION_ROLES,
            User::RELATION_BADGE,
        ]);

        return success(['user' => new UserResource($userWithRelations), 'token' => $token], __('auth.login_success'));
    }

    public function forgetPassword(Request $request)
    {
        $this->normalizeUsername($request);

        $request->validate([
            'username' => 'required|exists:users,mobile',
        ]);

        $mobile = $request->input('username');

        $user = User::where(User::MOBILE, $mobile)->where(User::PASSWORD, '!=', null)->first();
        if (! is_null($user)) {
            if ($this->isDisabled($user)) {
                return error(__('auth.account_disabled'), [], 403);
            }

            app(RequestOtpAction::class)->execute($user, $mobile, 3);

            return success([], 'auth.forget_password_code_sent_successfully');
        }

        return error(__('auth.no_user_found'), [], 404);
    }

    public function changePassword(Request $request)
    {
        // نرمال‌سازی پیش از validate — رول exists روی ورودی خام (+98/98/0098)
        // شکست می‌خورد در حالی که کاربر واقعاً وجود دارد.
        $this->normalizeUsername($request);

        $request->validate([
            'username' => 'required|exists:users,mobile',
            'code' => 'required',
            // min:8 هم‌تراز با StorePasswordRequest و StoreAdminRequest — مسیر
            // بازیابی رمز نباید سیاستی ضعیف‌تر از مسیر تغییر رمز داشته باشد.
            User::PASSWORD => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => __('auth.password_min'),
            'password.confirmed' => __('auth.password_confirmed'),
        ]);

        $mobile = $request->input('username');
        if ($this->isLocked($mobile, self::SCOPE_OTP)) {
            return $this->tooManyAttempts(self::SCOPE_OTP);
        }

        // Load user by mobile only — comparing the code inside a SQL WHERE clause leaks
        // timing information via DB query latency; hash_equals() runs in constant time.
        $user = User::where(User::MOBILE, $mobile)
            ->where(User::PASSWORD, '!=', null)
            ->first();

        if (! $user || ! OtpSecret::matches($user->{User::SECRET}, (string) $request->input('code'))) {
            $this->recordFailure($mobile, self::SCOPE_OTP);

            return error(__('auth.invalid_reset_token'), [], 400);
        }
        $this->clearFailures($mobile, self::SCOPE_OTP);

        // توکن بازنشانی پنجره‌ی انقضای خودش را دارد (verifyCode آن را ست می‌کند)؛
        // بعد از انقضا باید فرایند فراموشی رمز از ابتدا طی شود.
        if (
            $user->{User::SECRET_EXPIRES_AT} === null
            || Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()
        ) {
            return error(__('auth.reset_token_expired'), [], 400);
        }

        $user->update([
            User::PASSWORD => Hash::make($request->input('password')),
            User::SECRET => null,
            User::SECRET_EXPIRES_AT => null,
        ]);
        Auth::login($user);
        // ⚠️ اینجا عمداً **همه‌ی** توکن‌ها باطل می‌شوند و به issueTokenForDevice
        // سپرده نمی‌شود: رمز عبور عوض شده، پس هر نشستی روی هر دستگاهی — از جمله
        // نشستِ کسی که رمز قدیمی را داشته — باید بیرون بیفتد.
        $user->tokens()->delete();
        $token = $user->createToken(self::DEFAULT_DEVICE)->plainTextToken;

        return success(['user' => new UserResource($user), 'token' => $token], __('auth.password_changed_successfully'));
    }

    public function sendVerificationCode(Request $request)
    {
        $this->normalizeUsername($request);

        $validated = $request->validate([
            'username' => 'required|exists:users,mobile',
        ], [
            'username.required' => __('auth.username_required'),
            'username.exists' => __('auth.no_user_found'),
        ]);

        $user = User::where([User::MOBILE => $validated['username']])->first();
        if ($user && $this->isDisabled($user)) {
            return error(__('auth.account_disabled'), [], 403);
        }
        if (isset($user->{User::SECRET_EXPIRES_AT}) && ! Carbon::parse($user->{User::SECRET_EXPIRES_AT})->isPast()) {
            return success([], __('auth.code_sent_recently'), 202);
        }

        app(RequestOtpAction::class)->execute($user, $validated['username']);

        return success([], __('auth.verification_code_sent_successfully'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return error(__('auth.unauthenticated'), [], 401);
        }
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            // شاخهٔ stateful (کوکی SPA) — session را استک Sanctum ست کرده است؛ گارد
            // hasSession برای وقتی است که این شاخه بدون session فراخوانی شود (دفاعی).
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return success([], __('auth.logout_success'));
    }
}
