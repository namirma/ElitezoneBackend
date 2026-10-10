<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreUserBankAccountRequest;
use App\Http\Resources\Financial\UserBankAccountResource;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Services\PodiumServices\CardInquiryService;
use App\Services\PodiumServices\CardToIbanService;
use App\Services\PodiumServices\ShebaInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserBankAccountController extends Controller
{
    /**
     * دریافت لیست حساب‌های بانکی کاربر
     */
    public function index(): JsonResponse
    {
        $accounts = Auth::user()->bankAccounts()->latest()->get();

        return success(UserBankAccountResource::collection($accounts), __('bank.list_received'));
    }

    /**
     * افزودن حساب بانکی جدید (کارت یا شبا)
     */
    public function store(
        StoreUserBankAccountRequest $request,
        ShebaInquiryService $shebaService,
        CardInquiryService $cardService,
        CardToIbanService $cardToIbanService
    ): JsonResponse {
        $user = Auth::user();
        $user->load('profile');

        // ۱. بررسی تکمیل بودن اطلاعات هویتی
        if (empty($user->{User::NATIONAL_CODE}) || empty($user->{User::BIRTH_DATE})) {
            return error(__('bank.identity_incomplete'), [], 422);
        }

        $data = $request->validated();
        $shaba = $data['shaba'] ?? null;
        $cardNumber = $data['card_number'] ?? null;
        $bankName = __('bank.unknown_bank');

        // ۲. استعلام و احراز هویت (شبا یا کارت)
        try {
            if ($cardNumber) {
                // الف) استعلام کارت
                $isMatched = $cardService->verify($cardNumber, $user->{User::NATIONAL_CODE}, $user->{User::BIRTH_DATE});

                if (! $isMatched) {
                    throw ValidationException::withMessages([
                        'card_number' => [__('bank.card_mismatch')],
                    ]);
                }

                $bankName = $this->getBankNameFromCard($cardNumber);

                // تکمیل خودکار شبا از روی کارت (رفتار استاندارد بانکی) — شکست این
                // استعلام ثبت حساب را متوقف نمی‌کند، فقط شبا خالی می‌ماند.
                // عکس این عملیات (شبا → کارت) در شبکه بانکی سرویس استعلام ندارد.
                if (! $shaba) {
                    $shaba = $cardToIbanService->convert($cardNumber);
                }

            } elseif ($shaba) {
                // ب) استعلام شبا
                $isMatched = $shebaService->verify($shaba, $user->{User::NATIONAL_CODE}, $user->{User::BIRTH_DATE});

                if (! $isMatched) {
                    throw ValidationException::withMessages([
                        'shaba' => [__('bank.shaba_mismatch')],
                    ]);
                }

                $bankName = $this->getBankNameFromIban($shaba);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Bank inquiry service error', [
                'user_id' => Auth::id(),
                'message' => $e->getMessage(),
            ]);

            return error(__('bank.service_unavailable'), [], 503);
        }

        // ۳. دریافت نام صاحب حساب
        $holderName = $this->resolveHolderName($user);

        // ۴. ذخیره در دیتابیس
        $bankAccount = DB::transaction(function () use ($user, $data, $bankName, $holderName, $shaba, $cardNumber) {
            // اگر حساب پیش‌فرض است، بقیه را از حالت پیش‌فرض خارج کن
            if (! empty($data['is_default'])) {
                $user->bankAccounts()->update(['is_default' => false]);
            }

            $account = $user->bankAccounts()->create([
                UserBankAccount::BANK_NAME => $bankName,
                UserBankAccount::ACCOUNT_HOLDER_NAME => $holderName,
                UserBankAccount::SHABA => $shaba,
                UserBankAccount::CARD_NUMBER => $cardNumber,
                UserBankAccount::IS_DEFAULT => $data['is_default'] ?? false,
            ]);
            $account->{UserBankAccount::STATUS} = UserBankAccount::STATUS_APPROVED;
            $account->save();

            return $account;
        });

        return success(new UserBankAccountResource($bankAccount), __('bank.store_success'));
    }

    /**
     * انتخابِ حسابِ پیش‌فرض — دقیقاً یک پیش‌فرض برای هر کاربر.
     */
    public function setDefault(UserBankAccount $bankAccount): JsonResponse
    {
        // فقط مالک — عمداً نه `authorize()`، چون `before()`ِ policy ادمین را هم راه می‌دهد
        // و پیش‌فرض ترجیحِ شخصیِ خودِ مالک است، نه عملیاتِ پشتیبانی.
        if ($bankAccount->{UserBankAccount::USER_ID} !== Auth::id()) {
            return error(__('bank.default_forbidden'), [], 403);
        }

        DB::transaction(function () use ($bankAccount) {
            UserBankAccount::query()
                ->where(UserBankAccount::USER_ID, $bankAccount->{UserBankAccount::USER_ID})
                ->whereKeyNot($bankAccount->getKey())
                ->update([UserBankAccount::IS_DEFAULT => false]);
            $bankAccount->update([UserBankAccount::IS_DEFAULT => true]);
        });

        return success(new UserBankAccountResource($bankAccount), __('bank.set_default_success'));
    }

    /**
     * حذفِ حسابِ بانکی از سمتِ کاربر ممکن نیست؛ فقط پشتیبانی (بک‌آفیس) حذف می‌کند.
     *
     * همیشه ۴۰۳، برای مالک و ادمین هم. حذف: `DELETE /admin/users/{user}/bank-accounts/{bankAccount}`.
     */
    public function refuseDestroy(): JsonResponse
    {
        return error(__('bank.delete_support_only'), [], 403);
    }

    /**
     * حذف حساب بانکی
     */
    public function destroy(UserBankAccount $bankAccount): JsonResponse
    {
        $this->authorize('delete', $bankAccount);

        $bankAccount->delete();

        return success([], __('bank.delete_success'));
    }

    // =========================================================================
    //  متدهای کمکی (Helpers)
    // =========================================================================

    /**
     * استخراج نام صاحب حساب از پروفایل کاربر
     */
    private function resolveHolderName(User $user): string
    {
        $fullName = trim(($user->profile->name ?? '').' '.($user->profile->family ?? ''));

        return empty($fullName) ? __('bank.unknown_user') : $fullName;
    }

    /**
     * تشخیص نام بانک از روی کد شبا
     */
    private function getBankNameFromIban(string $iban): string
    {
        $cleanIban = strtoupper(preg_replace('/[^A-Z0-9]/', '', $iban));
        // ارقام 5 تا 7 کد بانک هستند
        $bankCode = substr($cleanIban, 4, 3);

        return $this->getBankNameByCode($bankCode);
    }

    /**
     * تشخیص نام بانک از روی ۶ رقم اول کارت (BIN)
     */
    private function getBankNameFromCard(string $card): string
    {
        $bin = substr($card, 0, 6);

        $bankCode = match ($bin) {
            '603799' => '017', // ملی
            '589210' => '015', // سپه
            '627648' => '020', // توسعه صادرات
            '627961' => '011', // صنعت و معدن
            '603770' => '016', // کشاورزی
            '628023' => '014', // مسکن
            '627760' => '021', // پست بانک
            '502908' => '022', // توسعه تعاون
            '627412' => '055', // اقتصاد نوین
            '622106', '639194', '627884' => '054', // پارسیان (اصلاح شد)
            '502229', '639347' => '057', // پاسارگاد
            '627488' => '053', // کارآفرین
            '621986' => '056', // سامان
            '639346' => '059', // سینا
            '639607' => '058', // سرمایه
            '636214' => '062', // آینده
            '502806' => '061', // شهر
            '502938' => '066', // دی (اصلاح شد: کد دی 066 است)
            '603769' => '019', // صادرات
            '610433', '991975' => '012', // ملت
            '627353', '585983' => '018', // تجارت
            '589463' => '013', // رفاه
            '627381' => '063', // انصار
            '639370' => '090', // مهر اقتصاد
            '639599' => '052', // قوامین
            '504172' => '070', // رسالت
            '606373' => '060', // مهر ایران
            '628157' => '051', // توسعه
            '505785' => '069', // ایران زمین
            '505416' => '064', // گردشگری
            '636949' => '065', // حکمت
            '505801' => '073', // کوثر
            default => null
        };

        return $bankCode ? $this->getBankNameByCode($bankCode) : __('bank.unknown_bank');
    }

    /**
     * دریافت نام فارسی بانک از فایل ترجمه بر اساس کد
     */
    private function getBankNameByCode(string $code): string
    {
        $key = 'bank.banks.'.$code;
        $name = __($key);

        // اگر ترجمه پیدا نشد، مقدار پیش‌فرض برگردان
        return $name === $key ? __('bank.unknown_bank') : $name;
    }
}
