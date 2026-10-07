<?php

namespace Database\Seeders;

use App\Events\Financial\TransactionProcessed;
use App\Events\Financial\WalletFunded;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderPaid;
use App\Events\Order\OrderPlaced;
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
     * key => [title, trigger, audience, placeholders, [kind, eventOrSender, schedule?]]
     *
     * عنصرِ پنجم همان محرک به شکلِ داده است — `SmsTemplate::KIND_*`:
     * - `event`     داخلِ یک رویدادِ `EventCatalog` می‌رود؛ عنصرِ دوم کلاسِ رویداد.
     * - `action`    یک کلاس/متدِ مشخص آن را می‌فرستد (`grep "::template(" app`).
     * - `scheduled` یک کرون (`routes/console.php`)؛ عنصرِ سوم زمان‌بندی به زبانِ آدم.
     * - `none`      متن آماده است، فرستنده‌ای ندارد.
     * `SmsCatalogCompletenessTest` این فهرست را با کد مقایسه می‌کند — در هر دو جهت.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>, 4: array{0: string, 1?: string, 2?: string}}>
     */
    public static function catalog(): array
    {
        $b = SmsTemplate::AUDIENCE_BUYER;
        $s = SmsTemplate::AUDIENCE_SUPPLIER;
        $e = SmsTemplate::AUDIENCE_ELITE_YAR;
        $em = SmsTemplate::AUDIENCE_EMPLOYER;
        $d = SmsTemplate::AUDIENCE_DRIVER;
        $any = SmsTemplate::AUDIENCE_ANY;
        $adm = SmsTemplate::AUDIENCE_ADMIN;

        $ev = SmsTemplate::KIND_EVENT;
        $ac = SmsTemplate::KIND_ACTION;
        $cr = SmsTemplate::KIND_SCHEDULED;
        $no = SmsTemplate::KIND_NONE;

        return [
            'loginMessage' => ['کد ورود (OTP)', 'درخواست کد ورود: هنگام ورود/ثبت‌نام با شماره‌ی موبایل، و هنگام تغییر شماره‌ی موبایل (کد به شماره‌ی جدید می‌رود). ارسال‌کننده: RequestOtpAction و AccountSecurityController.', $any, ['code'], [$ac, 'RequestOtpAction, AccountSecurityController::requestChangeMobile']],
            'newRegisterUser' => ['خوش‌آمد ثبت‌نام', 'اولین ورودِ یک شماره‌ی تازه (ثبت‌نام) — بلافاصله پس از تأیید کد ورود، فقط برای کاربرِ جدید. ارسال‌کننده: AuthController::verify.', $any, [], [$ac, 'AuthController::verifyCode']],
            'beneficiary_verification' => ['کد تأیید ذی‌نفع (خرید به نام دیگری)', 'الیت‌یار برای «خرید به نام دیگری» شماره‌ی کارفرما را وارد می‌کند؛ کد تأیید به شماره‌ی کارفرما می‌رود. ارسال‌کننده: BeneficiaryController::requestVerification.', $em, ['name', 'code'], [$ac, 'BeneficiaryController::startVerification']],
            'elite_yar_verified' => ['تأیید الیت یار', 'حکمِ «الیت‌یارِ تأییدشده» برای کاربر true می‌شود — با تشخیصِ لایسنسِ سازه ۹۰ در همگام‌سازیِ پروفایل (SyncUserBadgeAction) یا با «تأیید مدارک» ادمین وقتی یکی از روش‌های مدرکی روشن است. همین متن به‌عنوان اعلانِ درون‌برنامه‌ای هم ثبت می‌شود. ارسال‌کننده: NotifyEliteYARVerificationChange.', $e, [], [$ac, 'NotifyEliteYARVerificationChange']],
            'elite_yar_unverified' => ['برداشته‌شدن تأیید الیت یار', 'حکمِ «الیت‌یارِ تأییدشده» برای کاربر false می‌شود — سازه ۹۰ لایسنسش را تأیید نکرد (حساب هولد می‌شود)، یا ادمین «لغو تأیید مدارک» زد. همین متن به‌عنوان اعلانِ درون‌برنامه‌ای هم ثبت می‌شود. ارسال‌کننده: NotifyEliteYARVerificationChange.', $e, [], [$ac, 'NotifyEliteYARVerificationChange']],
            'coupon_wallet_low_admin' => ['کمبودِ کیف پول کوپن‌های تخفیف — مدیرِ مالی', 'یک کد تخفیف رد شد چون «کیف پول کوپن‌های تخفیف» سهمِ الیت‌زون را نمی‌پوشاند (مدیریت، دورِ ششم، پرسشِ ۵). به ادمین‌های فعالِ دارای مجوزِ مالی، حداکثر هر ۶ ساعت یک بار. همین متن به‌عنوان اعلانِ درون‌برنامه‌ای هم ثبت می‌شود. ارسال‌کننده: CouponWalletLowAlert.', $adm, ['first_name', 'amount', 'balance'], [$ac, 'CouponWalletLowAlert']],
            'elite_yar_hold_employer' => ['توقف موقت همکاری الیت یار — کارفرما', 'حکمِ «الیت‌یارِ تأییدشده» برای یک الیت‌یار false می‌شود (هولد) — به هر کارفرمایی که همکاری‌اش با این الیت‌یار تأییدشده است. متنِ مدیریت (دورِ پنجم، پرسشِ ۶). همین متن به‌عنوان اعلانِ درون‌برنامه‌ای هم ثبت می‌شود. ارسال‌کننده: NotifyEliteYARVerificationChange.', $em, ['elite_yar_name'], [$ac, 'NotifyEliteYARVerificationChange']],
            'elite_yar_restored_employer' => ['ازسرگیری همکاری الیت یار — کارفرما', 'حکمِ «الیت‌یارِ تأییدشده» دوباره true می‌شود (رفعِ هولد) — به کارفرماهای تأییدشده‌ی همان الیت‌یار. همین متن به‌عنوان اعلانِ درون‌برنامه‌ای هم ثبت می‌شود. ارسال‌کننده: NotifyEliteYARVerificationChange.', $em, ['elite_yar_name'], [$ac, 'NotifyEliteYARVerificationChange']],
            'elite_yar_invitation' => ['دعوت الیت‌یارِ ثبت‌نام‌شده', 'کارفرما یک الیت‌یارِ ثبت‌نام‌شده را به همکاری دعوت می‌کند (ثبتِ رابطه‌ی کارفرما–الیت‌یار در وضعیت «در انتظار»). همین متن به‌عنوان اعلانِ درون‌برنامه‌ای هم ثبت می‌شود. ارسال‌کننده: EliteYARRelationController::invite.', $e, ['employer_name'], [$ac, 'EliteYARRelationController::invite']],
            'transaction_confirmed' => ['تأیید تراکنش کیف پول', 'ادمین درخواست واریز/برداشت کیف پول را تأیید می‌کند (رویداد TransactionProcessed). :type یکی از «واریز/برداشت/تراکنش» و :amount به ریال.', $any, ['type', 'amount'], [$ev, TransactionProcessed::class]],
            'transaction_rejected' => ['رد تراکنش کیف پول', 'ادمین درخواست واریز/برداشت کیف پول را رد می‌کند (رویداد TransactionProcessed).', $any, ['type', 'amount'], [$ev, TransactionProcessed::class]],
            'order_placed' => ['رزرو سفارش', 'ثبت سفارشِ رزرو (پرداخت‌نشده) — به ثبت‌کننده‌ی سفارش. سفارشی که همان لحظه پرداخت می‌شود این پیامک را نمی‌گیرد (فقط «پرداخت سفارش»). ارسال‌کننده: SendOrderPlacedNotification.', $b, ['order_number'], [$ev, OrderPlaced::class]],
            'order_paid_buyer' => ['پرداخت سفارش — خریدار', 'پرداخت کامل سفارش — به ثبت‌کننده، و در «خرید برای دیگری» به پرداخت‌کننده (کارفرما) هم. ⚠️ زیر ۷۰ کاراکتر نگه دارید تا یک سگمنت بماند. ارسال‌کننده: SendOrderPaymentNotification.', $b, ['order_number'], [$ev, OrderPaid::class]],
            'order_paid_supplier' => ['پرداخت سفارش — تأمین‌کننده', 'پرداخت کامل سفارش — به تأمین‌کننده‌ی سفارش، یک بار به‌ازای هر سفارش. ⚠️ زیر ۷۰ کاراکتر نگه دارید. ارسال‌کننده: SendSupplierOrderNotification.', $s, ['order_number'], [$ev, OrderPaid::class]],
            'supplier_debt_notification' => ['بدهی کسری باسکول — تأمین‌کننده', 'پس از باسکول با کسری وزن، وقتی موجودی کیف پول تأمین‌کننده برای برداشتِ کسری کافی نیست و بدهی ثبت می‌شود. ارسال‌کننده: SupplierDebtHandler.', $s, ['order_number', 'amount'], [$ac, 'SupplierDebtHandler::parkDebtAndNotify (داخلِ ProcessWeighbridgeResultAction)']],
            'buyer_refund_released' => ['آزادسازی استرداد کسری باسکول — خریدار', 'وقتی تأمین‌کننده‌ی بدهکار کیف پولش را شارژ می‌کند و استردادِ کسری باسکول به خریدار آزاد می‌شود (کرون بررسی موجودی تأمین‌کنندگان). ارسال‌کننده: CheckSupplierBalancesJob.', $b, ['amount', 'order_number'], [$cr, 'CheckSupplierBalancesJob', 'هر دقیقه']],
            'ticket_admin_replied' => ['پاسخ ادمین به تیکت', 'ادمین به تیکت پاسخ می‌دهد و وضعیت «پاسخ داده شده» می‌شود. فقط وضعیت‌های فهرستِ ticketing.sms_on_status پیامک دارند. ارسال‌کننده: AdminTicketController::reply و TicketService::changeStatus.', $any, ['code'], [$ac, 'AdminTicketController::reply, TicketService::changeStatus']],
            'ticket_resolved' => ['حل شدن تیکت', 'وضعیت تیکت به «حل شده» تغییر می‌کند (فهرست ticketing.sms_on_status). ارسال‌کننده: TicketService::changeStatus.', $any, ['code'], [$ac, 'TicketService::changeStatus']],
            'ticket_closed' => ['بسته شدن تیکت', 'تیکت توسط پشتیبانی بسته می‌شود (فهرست ticketing.sms_on_status). ارسال‌کننده: TicketService::changeStatus.', $any, ['code'], [$ac, 'TicketService::changeStatus']],
            'wallet_deposited_podium' => ['واریز مستقیم بانکی (پادیوم)', 'واریز مستقیم بانکی با شناسه‌ی واریز پادیوم که به کاربر مچ و به کیف پول اضافه شده. ⚠️ :amount این‌جا به **تومان** است. ارسال‌کننده: ProcessPodiumDepositAction.', $any, ['amount'], [$cr, 'PollPodiumDepositsJob → ProcessPodiumDepositAction', 'هر ۵ دقیقه']],
            'cart_expiring' => ['هشدار انقضای سبد مهلت‌دار', 'کرونِ هشدار سبد مهلت‌دار: :minutes دقیقه پیش از انقضای سبدِ پارک‌شده، یک بار برای هر سبد. ارسال‌کننده: carts:warn-expiring.', $b, ['minutes'], [$cr, 'carts:warn-expiring', 'هر ۵ دقیقه']],
            'cart_prices_updated' => ['به‌روزرسانی قیمت سبدهای منتظر', 'تأمین‌کننده پروفایلش را به‌روز می‌کند و سبدهای منتظرِ او دوباره مهلت می‌گیرند — به صاحبِ هر سبد. ارسال‌کننده: SupplierController::update.', $b, [], [$ac, 'SupplierController::update']],
            'elite_yar_sms_invitation' => ['دعوت الیت‌یارِ ثبت‌نام‌نکرده', 'کارفرما شماره‌ای که هنوز ثبت‌نام نکرده را به‌عنوان الیت‌یار دعوت می‌کند (حداکثر ۱۰ دعوت). اگر ارسال نشود رکوردِ دعوت حذف می‌شود تا سهمیه نسوزد. ارسال‌کننده: EliteYARInviteController.', $e, ['employer_name', 'link'], [$ac, 'EliteYARInviteController::sendSms']],
            'employer_invitation' => ['دعوت کارفرمای ثبت‌نام‌نکرده', 'الیت‌یار شماره‌ای ثبت‌نام‌نکرده را به‌عنوان کارفرما دعوت می‌کند (حداکثر ۱۰ دعوت). ارسال‌کننده: EmployerInviteController.', $em, ['advisor_name', 'link'], [$ac, 'EmployerInviteController::send']],
            'cart_payment_link' => ['لینک پرداخت سبد (خرید برای دیگری)', 'الیت‌یار سبدی را برای کارفرما می‌بندد و لینک پرداخت می‌فرستد. ارسال‌کننده: CartPaymentLinkService::issue.', $em, ['elite_yar_name', 'link'], [$ac, 'CartPaymentLinkService::issue']],
            'driver_assigned' => ['تخصیص بار به راننده', 'تأمین‌کننده محموله را ثبت و راننده را تعیین می‌کند؛ لینکِ امضاشده‌ی «رسیدم» برای راننده (بدون حساب کاربری). ارسال‌کننده: RegisterShipmentAction.', $d, ['order_number', 'link'], [$ac, 'RegisterShipmentAction']],
            'driver_arrived' => ['اعلام رسیدن راننده — خریدار', 'راننده از لینک پیامکی اعلام رسیدن می‌کند؛ لینکِ تأیید تحویل برای پرداخت‌کننده‌ی سفارش. ارسال‌کننده: RecordShipmentArrivalAction.', $b, ['order_number', 'link'], [$ac, 'RecordShipmentArrivalAction']],
            'delivery_confirmed' => ['تأیید تحویل — راننده', 'خریدار تحویل محموله را تأیید می‌کند — به راننده. ارسال‌کننده: ConfirmShipmentDeliveryAction.', $d, ['order_number'], [$ac, 'ConfirmShipmentDeliveryAction']],
            'delivery_expiring' => ['یادآوری مهلت تأیید تحویل', '⚠️ در حال حاضر هیچ‌جا ارسال نمی‌شود: متن آماده است ولی کرونِ یادآوریِ مهلتِ تحویل ساخته نشده. فعال/غیرفعال‌کردنش اثری ندارد.', $b, ['order_number', 'hours'], [$no]],
            'shipment_returned' => ['عودت محموله به مبدأ', 'مهلت تأیید تحویل تمام می‌شود و محموله به مبدأ عودت می‌خورد (کرون shipments:expire-deliveries) — به پرداخت‌کننده و تأمین‌کننده. ارسال‌کننده: ExpireShipmentDeliveries.', $any, ['order_number'], [$cr, 'shipments:expire-delivery', 'هر دقیقه']],
            'return_damage_awaiting_buyer' => ['هزینه‌ی عودت — در انتظار خریدار', 'هزینه‌ی عودت (خسارت) ثبت و توسط بک‌آفیس تأیید می‌شود — به پرداخت‌کننده برای پذیرش. ارسال‌کننده: ReturnDamageService::approveByBackoffice.', $b, ['order_number', 'amount'], [$ac, 'ReturnDamageService::approveByBackoffice']],
            'return_damage_settled' => ['تسویه هزینه‌ی عودت — تأمین‌کننده', 'خریدار هزینه‌ی عودت را تسویه می‌کند و بار آماده‌ی حمل مجدد است — به تأمین‌کننده. ارسال‌کننده: ReturnDamageService::settle.', $s, ['order_number'], [$ac, 'ReturnDamageService::settle']],
            'return_damage_cap_reached' => ['سقف هزینه‌ی عودت', 'مجموع هزینه‌های عودت به ارزش کالا می‌رسد و سفارش خاتمه می‌یابد — به پرداخت‌کننده. ارسال‌کننده: ReturnDamageService.', $b, ['order_number'], [$ac, 'ReturnDamageService::applyCapIfReached']],
            'driver_verify' => ['کد تأیید شماره‌ی راننده', 'تأمین‌کننده شماره‌ی راننده‌اش را تأیید می‌کند: کد به شماره‌ی راننده می‌رود. ارسال‌کننده: SupplierDriverController::verify.', $d, ['code'], [$ac, 'SupplierDriverController::verify']],
            'overweight_supplier_request' => ['اضافه‌بار — درخواست تأیید تأمین‌کننده', 'باسکول اضافه‌بارِ بیش از حد مجاز نشان می‌دهد — درخواست تأیید از تأمین‌کننده (مرحله‌ی یک). ارسال‌کننده: RequestOverweightApprovalAction.', $s, ['order', 'amount'], [$ac, 'RequestOverweightApprovalAction::open']],
            'overweight_buyer_request' => ['اضافه‌بار — درخواست پذیرش خریدار', 'تأمین‌کننده اضافه‌بار را تأیید می‌کند — درخواست پذیرشِ مابه‌التفاوت از پرداخت‌کننده (مرحله‌ی دو). ارسال‌کننده: RequestOverweightApprovalAction.', $b, ['order', 'amount'], [$ac, 'RequestOverweightApprovalAction::approveBySupplier']],
            'overweight_rejected' => ['رد درخواست اضافه‌بار', 'درخواست اضافه‌بار رد می‌شود (توسط هر طرف، یا با گذشتن مهلت) و بارنامه باطل می‌گردد — به تأمین‌کننده و پرداخت‌کننده. ارسال‌کننده: RequestOverweightApprovalAction.', $any, ['order', 'amount'], [$ac, 'RequestOverweightApprovalAction::reject', 'هر ساعت هم از کرون orders:expire-overweight (انقضای مهلت)']],
            'order_cancelled' => ['لغو سفارش', 'سفارش لغو می‌شود — به پرداخت‌کننده (اگر استردادی در کار نبود) و به ثبت‌کننده اگر کسِ دیگری است. ارسال‌کننده: SendOrderCancellationNotification.', $any, ['order_number'], [$ev, OrderCancelled::class]],
            'order_cancelled_refunded' => ['لغو سفارش با استرداد', 'سفارش لغو و مبلغ به کیف پول پرداخت‌کننده برگردانده می‌شود. ارسال‌کننده: SendOrderCancellationNotification.', $b, ['order_number'], [$ev, OrderCancelled::class]],
            'wallet_funded' => ['شارژ کیف پول', 'کیف پول از درگاه یا با تأیید واریز پادیوم شارژ می‌شود (رویداد WalletFunded). :tracking_line یا خالی است یا «کد پیگیری: …» به‌همراهِ خطِ نو. ارسال‌کننده: SendWalletFundingNotification.', $any, ['amount', 'tracking_line'], [$ev, WalletFunded::class]],
            // ── تمدیدِ رزرو با توافق (flow2) — Notifier: پیامک + زنگوله ──
            'reserve_extension_proposed' => ['پیشنهاد تمدید رزرو — خریدار', 'تأمین‌کننده تمدید رزرو را پیشنهاد می‌دهد — به پرداخت‌کننده‌ی سفارش برای پذیرش/رد تا :respond_until. ارسال‌کننده: ReserveExtensionAction::propose.', $b, ['order', 'until', 'respond_until'], [$ac, 'ReserveExtensionAction::propose']],
            'reserve_extension_accepted' => ['پذیرش تمدید رزرو — تأمین‌کننده', 'خریدار پیشنهاد تمدید را می‌پذیرد و مهلت تازه ثبت می‌شود — به تأمین‌کننده. ارسال‌کننده: ReserveExtensionAction::accept.', $s, ['order', 'until'], [$ac, 'ReserveExtensionAction::accept']],
            'reserve_extension_rejected' => ['رد تمدید رزرو — تأمین‌کننده', 'خریدار پیشنهاد تمدید را رد می‌کند و رزرو لغو می‌شود — به تأمین‌کننده (پیامک لغو/استرداد جداگانه از OrderCancelled می‌رود). ارسال‌کننده: ReserveExtensionAction::reject.', $s, ['order'], [$ac, 'ReserveExtensionAction::reject']],
            // ── لغوِ دوطرفه‌ی سفارش با ارجاع به بک‌آفیس (flow2) ──
            'order_cancel_requested' => ['درخواست لغو سفارش — طرف مقابل', 'یک طرف (خریدار یا تأمین‌کننده) درخواست لغو ثبت می‌کند — به طرف مقابل برای موافقت/مخالفت تا :until. ارسال‌کننده: OrderCancelRequestAction::request.', $any, ['order', 'until'], [$ac, 'OrderCancelRequestAction::request']],
            'order_cancel_accepted' => ['موافقت با لغو سفارش', 'طرف مقابل با لغو موافقت می‌کند و سفارش لغو می‌شود — به درخواست‌کننده. ارسال‌کننده: OrderCancelRequestAction::accept.', $any, ['order'], [$ac, 'OrderCancelRequestAction::accept']],
            'order_cancel_rejected' => ['مخالفت با لغو سفارش', 'طرف مقابل با لغو مخالفت می‌کند و درخواست به پشتیبانی ارجاع می‌شود — به درخواست‌کننده، با دلیلِ مخالفت. ارسال‌کننده: OrderCancelRequestAction::reject.', $any, ['order', 'reason'], [$ac, 'OrderCancelRequestAction::reject']],
            'order_cancel_escalated' => ['ارجاع لغو سفارش به پشتیبانی', 'درخواست لغو در مهلت مقرر پاسخ نمی‌گیرد و به پشتیبانی ارجاع می‌شود — به هر دو طرف. ارسال‌کننده: OrderCancelRequestAction::escalate (کرون).', $any, ['order'], [$cr, 'orders:escalate-cancel-requests', 'هر ساعت']],
            'order_cancel_admin_approved' => ['تأیید لغو سفارش توسط پشتیبانی', 'پشتیبانی درخواستِ ارجاع‌شده را تأیید و سفارش را لغو می‌کند — به هر دو طرف، با دلیل. ارسال‌کننده: OrderCancelRequestAction::adminApprove.', $any, ['order', 'reason'], [$ac, 'OrderCancelRequestAction::adminApprove']],
            'order_cancel_admin_rejected' => ['رد لغو سفارش توسط پشتیبانی', 'پشتیبانی درخواستِ ارجاع‌شده را رد می‌کند و سفارش ادامه می‌یابد — به هر دو طرف، با دلیل. ارسال‌کننده: OrderCancelRequestAction::adminReject.', $any, ['order', 'reason'], [$ac, 'OrderCancelRequestAction::adminReject']],
            // ── کلیدهای نشست‌های موازیِ ۲۰۲۶-۰۹-۱۴ — همه از ۲۴ شهریور از راهِ `::template` می‌روند
            'price_change_expired' => ['انقضای درخواست تغییر قیمت — تأمین‌کننده', 'مهلت بررسی درخواست تغییر قیمت می‌گذرد و رد می‌شود — به تأمین‌کننده. ارسال‌کننده: ExpirePriceChangeRequests (کرون).', $s, ['variant', 'hours'], [$cr, 'supplier-requests:expire-price-changes', 'هر ساعت']],
            'cart_payment_link_cancelled' => ['لغو لینک پرداخت سبد — کارفرما', 'الیت‌یار لینک پرداختی را که برای کارفرما فرستاده بود لغو می‌کند. ارسال‌کننده: CartPaymentLinkService::cancel.', $em, ['elite_yar_name'], [$ac, 'CartPaymentLinkService::cancel']],
            'product_change_approved' => ['تأیید درخواست تغییر کاتالوگ — تأمین‌کننده', 'ادمین پیشنهاد ساخت/ویرایش محصول یا واریانت را تأیید و اعمال می‌کند. ارسال‌کننده: ProductChangeRequestService.', $s, ['subject'], [$ac, 'ProductChangeRequestService::notify']],
            'product_change_rejected' => ['رد درخواست تغییر کاتالوگ — تأمین‌کننده', 'ادمین پیشنهاد تغییر کاتالوگ را با دلیل رد می‌کند. ارسال‌کننده: ProductChangeRequestService.', $s, ['subject', 'reason'], [$ac, 'ProductChangeRequestService::notify']],
            'supplier_approved' => ['تأیید صلاحیت تأمین‌کننده', 'ادمین درخواست احراز صلاحیت را تأیید می‌کند؛ نقش تأمین‌کننده وصل و پنل باز می‌شود. ارسال‌کننده: AdminSupplierController::approve.', $s, ['panel_url'], [$ac, 'AdminSupplierController::approve']],
            'supplier_needs_correction' => ['نیاز به اصلاح درخواست تأمین‌کنندگی', 'ادمین از متقاضی اصلاح می‌خواهد؛ متقاضی دلیل را در پروفایل سایت می‌بیند و دوباره ارسال می‌کند. ارسال‌کننده: AdminSupplierController::requestCorrection.', $s, ['reason'], [$ac, 'AdminSupplierController::requestCorrection']],
            'supplier_rejected' => ['رد درخواست تأمین‌کنندگی', 'ادمین درخواست احراز صلاحیت را با دلیل رد می‌کند. ارسال‌کننده: AdminSupplierController::reject.', $s, ['reason'], [$ac, 'AdminSupplierController::reject']],
            'coupon_funding_requested' => ['درخواست تأمین تخفیف کوپن', 'سازنده‌ی کوپن (ادمین/تأمین‌کننده/الیت‌یار) بخشی از تخفیف را از سهمِ این کاربر پیشنهاد می‌کند؛ کاربر باید در پنلِ خودش بپذیرد یا رد کند. ارسال‌کننده: CouponFunderService.', $any, ['proposer', 'percent', 'code'], [$ac, 'CouponFunderService::notifyProposed']],
            'coupon_funding_accepted' => ['پذیرش تأمین تخفیف کوپن', 'یک تأمین‌کننده‌ی تخفیف سهمش را پذیرفت؛ به سازنده‌ی کوپن می‌رود. ارسال‌کننده: CouponFunderService.', $any, ['funder', 'percent', 'code'], [$ac, 'CouponFunderService::respond']],
            'coupon_funding_declined' => ['رد تأمین تخفیف کوپن', 'یک تأمین‌کننده‌ی تخفیف سهمش را نپذیرفت و کوپن قابل استفاده نیست؛ به سازنده‌ی کوپن می‌رود. ارسال‌کننده: CouponFunderService.', $any, ['funder', 'code'], [$ac, 'CouponFunderService::respond']],
        ];
    }

    public function run(): void
    {
        SmsTemplate::syncFromLang(self::catalog());
    }
}
