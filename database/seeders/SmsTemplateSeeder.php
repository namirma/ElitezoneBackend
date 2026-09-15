<?php

namespace Database\Seeders;

use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

/**
 * یک ردیفِ `sms_templates` به‌ازای هر کلیدِ `lang/fa/sms.php`.
 *
 * ایدمپوتنت، و **فقط فراداده را به‌روز می‌کند**: عنوان، شرحِ محرک، مخاطب،
 * جانگهدارها و حفاظت‌شدگی از این‌جا می‌آیند؛ متن، فعال‌بودن و شرط‌ها مالِ
 * ادمین‌اند و روی ردیفِ موجود دست نمی‌خورند. ردیفِ تازه متنِ lang را می‌گیرد.
 *
 * شرحِ هر محرک از خواندنِ محلِ dispatch نوشته شده، نه از روی نامِ کلید — و
 * جایی که کلیدی هیچ فرستنده‌ای ندارد، همین را می‌گوید (`delivery_expiring`).
 *
 * کلیدی که در lang هست ولی این‌جا نه (کلیدِ تازه‌ی یک نشستِ دیگر) هم ردیف
 * می‌گیرد — با عنوانِ خودِ کلید و بدونِ شرح — تا از پنل غایب نباشد؛
 * `SmsTemplate::syncFromLang()` همین کار را در هر بازِ صفحه هم می‌کند.
 */
class SmsTemplateSeeder extends Seeder
{
    /**
     * key => [title, trigger, audience, placeholders]
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function catalog(): array
    {
        $b = SmsTemplate::AUDIENCE_BUYER;
        $s = SmsTemplate::AUDIENCE_SUPPLIER;
        $e = SmsTemplate::AUDIENCE_ELITE_YAR;
        $em = SmsTemplate::AUDIENCE_EMPLOYER;
        $d = SmsTemplate::AUDIENCE_DRIVER;
        $any = SmsTemplate::AUDIENCE_ANY;

        return [
            'loginMessage' => ['کد ورود (OTP)', 'درخواست کد ورود: هنگام ورود/ثبت‌نام با شماره‌ی موبایل، و هنگام تغییر شماره‌ی موبایل (کد به شماره‌ی جدید می‌رود). ارسال‌کننده: RequestOtpAction و AccountSecurityController.', $any, ['code']],
            'newRegisterUser' => ['خوش‌آمد ثبت‌نام', 'اولین ورودِ یک شماره‌ی تازه (ثبت‌نام) — بلافاصله پس از تأیید کد ورود، فقط برای کاربرِ جدید. ارسال‌کننده: AuthController::verify.', $any, []],
            'beneficiary_verification' => ['کد تأیید ذی‌نفع (خرید به نام دیگری)', 'الیت‌یار برای «خرید به نام دیگری» شماره‌ی کارفرما را وارد می‌کند؛ کد تأیید به شماره‌ی کارفرما می‌رود. ارسال‌کننده: BeneficiaryController::requestVerification.', $em, ['name', 'code']],
            'elite_yar_invitation' => ['دعوت الیت‌یارِ ثبت‌نام‌شده', 'کارفرما یک الیت‌یارِ ثبت‌نام‌شده را به همکاری دعوت می‌کند (ثبتِ رابطه‌ی کارفرما–الیت‌یار در وضعیت «در انتظار»). همین متن به‌عنوان اعلانِ درون‌برنامه‌ای هم ثبت می‌شود. ارسال‌کننده: EliteYARRelationController::invite.', $e, ['employer_name']],
            'transaction_confirmed' => ['تأیید تراکنش کیف پول', 'ادمین درخواست واریز/برداشت کیف پول را تأیید می‌کند (رویداد TransactionProcessed). :type یکی از «واریز/برداشت/تراکنش» و :amount به ریال.', $any, ['type', 'amount']],
            'transaction_rejected' => ['رد تراکنش کیف پول', 'ادمین درخواست واریز/برداشت کیف پول را رد می‌کند (رویداد TransactionProcessed).', $any, ['type', 'amount']],
            'order_placed' => ['رزرو سفارش', 'ثبت سفارشِ رزرو (پرداخت‌نشده) — به ثبت‌کننده‌ی سفارش. سفارشی که همان لحظه پرداخت می‌شود این پیامک را نمی‌گیرد (فقط «پرداخت سفارش»). ارسال‌کننده: SendOrderPlacedNotification.', $b, ['order_number']],
            'order_paid_buyer' => ['پرداخت سفارش — خریدار', 'پرداخت کامل سفارش — به ثبت‌کننده، و در «خرید برای دیگری» به پرداخت‌کننده (کارفرما) هم. ⚠️ زیر ۷۰ کاراکتر نگه دارید تا یک سگمنت بماند. ارسال‌کننده: SendOrderPaymentNotification.', $b, ['order_number']],
            'order_paid_supplier' => ['پرداخت سفارش — تأمین‌کننده', 'پرداخت کامل سفارش — به تأمین‌کننده‌ی سفارش، یک بار به‌ازای هر سفارش. ⚠️ زیر ۷۰ کاراکتر نگه دارید. ارسال‌کننده: SendSupplierOrderNotification.', $s, ['order_number']],
            'supplier_debt_notification' => ['بدهی کسری باسکول — تأمین‌کننده', 'پس از باسکول با کسری وزن، وقتی موجودی کیف پول تأمین‌کننده برای برداشتِ کسری کافی نیست و بدهی ثبت می‌شود. ارسال‌کننده: SupplierDebtHandler.', $s, ['order_number', 'amount']],
            'buyer_refund_released' => ['آزادسازی استرداد کسری باسکول — خریدار', 'وقتی تأمین‌کننده‌ی بدهکار کیف پولش را شارژ می‌کند و استردادِ کسری باسکول به خریدار آزاد می‌شود (کرون بررسی موجودی تأمین‌کنندگان). ارسال‌کننده: CheckSupplierBalancesJob.', $b, ['amount', 'order_number']],
            'ticket_admin_replied' => ['پاسخ ادمین به تیکت', 'ادمین به تیکت پاسخ می‌دهد و وضعیت «پاسخ داده شده» می‌شود. فقط وضعیت‌های فهرستِ ticketing.sms_on_status پیامک دارند. ارسال‌کننده: AdminTicketController::reply و TicketService::changeStatus.', $any, ['code']],
            'ticket_resolved' => ['حل شدن تیکت', 'وضعیت تیکت به «حل شده» تغییر می‌کند (فهرست ticketing.sms_on_status). ارسال‌کننده: TicketService::changeStatus.', $any, ['code']],
            'ticket_closed' => ['بسته شدن تیکت', 'تیکت توسط پشتیبانی بسته می‌شود (فهرست ticketing.sms_on_status). ارسال‌کننده: TicketService::changeStatus.', $any, ['code']],
            'wallet_deposited_podium' => ['واریز مستقیم بانکی (پادیوم)', 'واریز مستقیم بانکی با شناسه‌ی واریز پادیوم که به کاربر مچ و به کیف پول اضافه شده. ⚠️ :amount این‌جا به **تومان** است. ارسال‌کننده: ProcessPodiumDepositAction.', $any, ['amount']],
            'cart_expiring' => ['هشدار انقضای سبد مهلت‌دار', 'کرونِ هشدار سبد مهلت‌دار: :minutes دقیقه پیش از انقضای سبدِ پارک‌شده، یک بار برای هر سبد. ارسال‌کننده: carts:warn-expiring.', $b, ['minutes']],
            'cart_prices_updated' => ['به‌روزرسانی قیمت سبدهای منتظر', 'تأمین‌کننده پروفایلش را به‌روز می‌کند و سبدهای منتظرِ او دوباره مهلت می‌گیرند — به صاحبِ هر سبد. ارسال‌کننده: SupplierController::update.', $b, []],
            'elite_yar_sms_invitation' => ['دعوت الیت‌یارِ ثبت‌نام‌نکرده', 'کارفرما شماره‌ای که هنوز ثبت‌نام نکرده را به‌عنوان الیت‌یار دعوت می‌کند (حداکثر ۱۰ دعوت). اگر ارسال نشود رکوردِ دعوت حذف می‌شود تا سهمیه نسوزد. ارسال‌کننده: EliteYARInviteController.', $e, ['employer_name', 'link']],
            'employer_invitation' => ['دعوت کارفرمای ثبت‌نام‌نکرده', 'الیت‌یار شماره‌ای ثبت‌نام‌نکرده را به‌عنوان کارفرما دعوت می‌کند (حداکثر ۱۰ دعوت). ارسال‌کننده: EmployerInviteController.', $em, ['advisor_name', 'link']],
            'cart_payment_link' => ['لینک پرداخت سبد (خرید برای دیگری)', 'الیت‌یار سبدی را برای کارفرما می‌بندد و لینک پرداخت می‌فرستد. ارسال‌کننده: CartPaymentLinkService::issue.', $em, ['elite_yar_name', 'link']],
            'driver_assigned' => ['تخصیص بار به راننده', 'تأمین‌کننده محموله را ثبت و راننده را تعیین می‌کند؛ لینکِ امضاشده‌ی «رسیدم» برای راننده (بدون حساب کاربری). ارسال‌کننده: RegisterShipmentAction.', $d, ['order_number', 'link']],
            'driver_arrived' => ['اعلام رسیدن راننده — خریدار', 'راننده از لینک پیامکی اعلام رسیدن می‌کند؛ لینکِ تأیید تحویل برای پرداخت‌کننده‌ی سفارش. ارسال‌کننده: RecordShipmentArrivalAction.', $b, ['order_number', 'link']],
            'delivery_confirmed' => ['تأیید تحویل — راننده', 'خریدار تحویل محموله را تأیید می‌کند — به راننده. ارسال‌کننده: ConfirmShipmentDeliveryAction.', $d, ['order_number']],
            'delivery_expiring' => ['یادآوری مهلت تأیید تحویل', '⚠️ در حال حاضر هیچ‌جا ارسال نمی‌شود: متن آماده است ولی کرونِ یادآوریِ مهلتِ تحویل ساخته نشده. فعال/غیرفعال‌کردنش اثری ندارد.', $b, ['order_number', 'hours']],
            'shipment_returned' => ['عودت محموله به مبدأ', 'مهلت تأیید تحویل تمام می‌شود و محموله به مبدأ عودت می‌خورد (کرون shipments:expire-deliveries) — به پرداخت‌کننده و تأمین‌کننده. ارسال‌کننده: ExpireShipmentDeliveries.', $any, ['order_number']],
            'return_damage_awaiting_buyer' => ['هزینه‌ی عودت — در انتظار خریدار', 'هزینه‌ی عودت (خسارت) ثبت و توسط بک‌آفیس تأیید می‌شود — به پرداخت‌کننده برای پذیرش. ارسال‌کننده: ReturnDamageService::approveByBackoffice.', $b, ['order_number', 'amount']],
            'return_damage_settled' => ['تسویه هزینه‌ی عودت — تأمین‌کننده', 'خریدار هزینه‌ی عودت را تسویه می‌کند و بار آماده‌ی حمل مجدد است — به تأمین‌کننده. ارسال‌کننده: ReturnDamageService::settle.', $s, ['order_number']],
            'return_damage_cap_reached' => ['سقف هزینه‌ی عودت', 'مجموع هزینه‌های عودت به ارزش کالا می‌رسد و سفارش خاتمه می‌یابد — به پرداخت‌کننده. ارسال‌کننده: ReturnDamageService.', $b, ['order_number']],
            'driver_verify' => ['کد تأیید شماره‌ی راننده', 'تأمین‌کننده شماره‌ی راننده‌اش را تأیید می‌کند: کد به شماره‌ی راننده می‌رود. ارسال‌کننده: SupplierDriverController::verify.', $d, ['code']],
            'overweight_supplier_request' => ['اضافه‌بار — درخواست تأیید تأمین‌کننده', 'باسکول اضافه‌بارِ بیش از حد مجاز نشان می‌دهد — درخواست تأیید از تأمین‌کننده (مرحله‌ی یک). ارسال‌کننده: RequestOverweightApprovalAction.', $s, ['order', 'amount']],
            'overweight_buyer_request' => ['اضافه‌بار — درخواست پذیرش خریدار', 'تأمین‌کننده اضافه‌بار را تأیید می‌کند — درخواست پذیرشِ مابه‌التفاوت از پرداخت‌کننده (مرحله‌ی دو). ارسال‌کننده: RequestOverweightApprovalAction.', $b, ['order', 'amount']],
            'overweight_rejected' => ['رد درخواست اضافه‌بار', 'درخواست اضافه‌بار رد می‌شود (توسط هر طرف، یا با گذشتن مهلت) و بارنامه باطل می‌گردد — به تأمین‌کننده و پرداخت‌کننده. ارسال‌کننده: RequestOverweightApprovalAction.', $any, ['order', 'amount']],
            'order_cancelled' => ['لغو سفارش', 'سفارش لغو می‌شود — به پرداخت‌کننده (اگر استردادی در کار نبود) و به ثبت‌کننده اگر کسِ دیگری است. ارسال‌کننده: SendOrderCancellationNotification.', $any, ['order_number']],
            'order_cancelled_refunded' => ['لغو سفارش با استرداد', 'سفارش لغو و مبلغ به کیف پول پرداخت‌کننده برگردانده می‌شود. ارسال‌کننده: SendOrderCancellationNotification.', $b, ['order_number']],
            'wallet_funded' => ['شارژ کیف پول', 'کیف پول از درگاه یا با تأیید واریز پادیوم شارژ می‌شود (رویداد WalletFunded). :tracking_line یا خالی است یا «کد پیگیری: …» به‌همراهِ خطِ نو. ارسال‌کننده: SendWalletFundingNotification.', $any, ['amount', 'tracking_line']],
            // ── تمدیدِ رزرو با توافق (flow2) — Notifier: پیامک + زنگوله ──
            'reserve_extension_proposed' => ['پیشنهاد تمدید رزرو — خریدار', 'تأمین‌کننده تمدید رزرو را پیشنهاد می‌دهد — به پرداخت‌کننده‌ی سفارش برای پذیرش/رد تا :respond_until. ارسال‌کننده: ReserveExtensionAction::propose.', $b, ['order', 'until', 'respond_until']],
            'reserve_extension_accepted' => ['پذیرش تمدید رزرو — تأمین‌کننده', 'خریدار پیشنهاد تمدید را می‌پذیرد و مهلت تازه ثبت می‌شود — به تأمین‌کننده. ارسال‌کننده: ReserveExtensionAction::accept.', $s, ['order', 'until']],
            'reserve_extension_rejected' => ['رد تمدید رزرو — تأمین‌کننده', 'خریدار پیشنهاد تمدید را رد می‌کند و رزرو لغو می‌شود — به تأمین‌کننده (پیامک لغو/استرداد جداگانه از OrderCancelled می‌رود). ارسال‌کننده: ReserveExtensionAction::reject.', $s, ['order']],
            // ── لغوِ دوطرفه‌ی سفارش با ارجاع به بک‌آفیس (flow2) ──
            'order_cancel_requested' => ['درخواست لغو سفارش — طرف مقابل', 'یک طرف (خریدار یا تأمین‌کننده) درخواست لغو ثبت می‌کند — به طرف مقابل برای موافقت/مخالفت تا :until. ارسال‌کننده: OrderCancelRequestAction::request.', $any, ['order', 'until']],
            'order_cancel_accepted' => ['موافقت با لغو سفارش', 'طرف مقابل با لغو موافقت می‌کند و سفارش لغو می‌شود — به درخواست‌کننده. ارسال‌کننده: OrderCancelRequestAction::accept.', $any, ['order']],
            'order_cancel_rejected' => ['مخالفت با لغو سفارش', 'طرف مقابل با لغو مخالفت می‌کند و درخواست به پشتیبانی ارجاع می‌شود — به درخواست‌کننده، با دلیلِ مخالفت. ارسال‌کننده: OrderCancelRequestAction::reject.', $any, ['order', 'reason']],
            'order_cancel_escalated' => ['ارجاع لغو سفارش به پشتیبانی', 'درخواست لغو در مهلت مقرر پاسخ نمی‌گیرد و به پشتیبانی ارجاع می‌شود — به هر دو طرف. ارسال‌کننده: OrderCancelRequestAction::escalate (کرون).', $any, ['order']],
            'order_cancel_admin_approved' => ['تأیید لغو سفارش توسط پشتیبانی', 'پشتیبانی درخواستِ ارجاع‌شده را تأیید و سفارش را لغو می‌کند — به هر دو طرف، با دلیل. ارسال‌کننده: OrderCancelRequestAction::adminApprove.', $any, ['order', 'reason']],
            'order_cancel_admin_rejected' => ['رد لغو سفارش توسط پشتیبانی', 'پشتیبانی درخواستِ ارجاع‌شده را رد می‌کند و سفارش ادامه می‌یابد — به هر دو طرف، با دلیل. ارسال‌کننده: OrderCancelRequestAction::adminReject.', $any, ['order', 'reason']],
            // ── کلیدهای نشست‌های موازیِ ۲۰۲۶-۰۹-۱۴ — همه از ۲۴ شهریور از راهِ `::template` می‌روند
            'price_change_expired' => ['انقضای درخواست تغییر قیمت — تأمین‌کننده', 'مهلت بررسی درخواست تغییر قیمت می‌گذرد و رد می‌شود — به تأمین‌کننده. ارسال‌کننده: ExpirePriceChangeRequests (کرون).', $s, ['variant', 'hours']],
            'cart_payment_link_cancelled' => ['لغو لینک پرداخت سبد — کارفرما', 'الیت‌یار لینک پرداختی را که برای کارفرما فرستاده بود لغو می‌کند. ارسال‌کننده: CartPaymentLinkService::cancel.', $em, ['elite_yar_name']],
            'product_change_approved' => ['تأیید درخواست تغییر کاتالوگ — تأمین‌کننده', 'ادمین پیشنهاد ساخت/ویرایش محصول یا واریانت را تأیید و اعمال می‌کند. ارسال‌کننده: ProductChangeRequestService.', $s, ['subject']],
            'product_change_rejected' => ['رد درخواست تغییر کاتالوگ — تأمین‌کننده', 'ادمین پیشنهاد تغییر کاتالوگ را با دلیل رد می‌کند. ارسال‌کننده: ProductChangeRequestService.', $s, ['subject', 'reason']],
            'coupon_funding_requested' => ['درخواست تأمین تخفیف کوپن', 'سازنده‌ی کوپن (ادمین/تأمین‌کننده/الیت‌یار) بخشی از تخفیف را از سهمِ این کاربر پیشنهاد می‌کند؛ کاربر باید در پنلِ خودش بپذیرد یا رد کند. ارسال‌کننده: CouponFunderService.', $any, ['proposer', 'percent', 'code']],
            'coupon_funding_accepted' => ['پذیرش تأمین تخفیف کوپن', 'یک تأمین‌کننده‌ی تخفیف سهمش را پذیرفت؛ به سازنده‌ی کوپن می‌رود. ارسال‌کننده: CouponFunderService.', $any, ['funder', 'percent', 'code']],
            'coupon_funding_declined' => ['رد تأمین تخفیف کوپن', 'یک تأمین‌کننده‌ی تخفیف سهمش را نپذیرفت و کوپن قابل استفاده نیست؛ به سازنده‌ی کوپن می‌رود. ارسال‌کننده: CouponFunderService.', $any, ['funder', 'code']],
        ];
    }

    public function run(): void
    {
        SmsTemplate::syncFromLang(self::catalog());
    }
}
