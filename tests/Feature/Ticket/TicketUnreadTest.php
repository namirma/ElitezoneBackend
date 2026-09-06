<?php

namespace Tests\Feature\Ticket;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * نشانگرِ «خوانده‌نشده» — یک تعریف، دو مخاطب.
 *
 * پیشینه (نشست ۲۲): هیچ ستونی برای ردیابی خوانده‌شدن وجود نداشت، پس نه پنل
 * می‌فهمید کدام تیکت پیام تازه دارد و نه کاربر می‌فهمید پشتیبانی جواب داده.
 *
 * قاعده‌ی مرکزی: «پیامی که *طرف مقابل* فرستاده و بعد از آخرین بازدید من ثبت شده».
 * منطق در `Ticket` است تا پنل و سایت دو تعریف واگرا نسازند.
 */
class TicketUnreadTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsAdminPermissions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $this->grantAllAdminPermissions($user);
    }

    private function makeTicket(?User $owner = null): Ticket
    {
        $owner ??= User::factory()->create();

        // `tickets.department_id` مقدار پیش‌فرض ندارد؛ در تست هم مثل تولید باید
        // یک دپارتمان واقعی داشته باشد.
        $departmentId = Department::query()->value('id')
            ?? Department::create(['name' => 'تست', 'slug' => 'test-'.uniqid()])->id;

        return Ticket::create([
            Ticket::CODE => 'TCK-'.substr(md5(uniqid('', true)), 0, 8),
            Ticket::USER_ID => $owner->id,
            Ticket::DEPARTMENT_ID => $departmentId,
            Ticket::SUBJECT => 'موضوع تست',
            Ticket::MESSAGE => 'متن تست',
            Ticket::STATUS => Ticket::STATUS_OPEN,
            Ticket::PRIORITY => 'normal',
        ]);
    }

    /**
     * پاسخ در «همین لحظه»ی ساعتِ تست ثبت می‌شود.
     *
     * ⚠️ هر دو ستون دقتِ ثانیه دارند، پس اگر پاسخ و «خوانده شد» در یک ثانیه بیفتند
     * مقایسه‌ی `>` طبیعتاً false است. تست‌ها به‌جای تکیه بر زمانِ واقعی، با
     * `travelTo` ساعت را جلو می‌برند تا ترتیب قطعی باشد.
     */
    private function reply(Ticket $ticket, ?int $adminId): TicketReply
    {
        return TicketReply::create([
            TicketReply::TICKET_ID => $ticket->id,
            TicketReply::USER_ID => $ticket->{Ticket::USER_ID},
            TicketReply::ADMIN_ID => $adminId,
            TicketReply::MESSAGE => 'پاسخ',
        ])->fresh();
    }

    /** ساعتِ تست را جلو می‌برد تا دو رویداد در یک ثانیه نیفتند. */
    private function laterBy(int $minutes = 2): void
    {
        $this->travelTo(now()->addMinutes($minutes));
    }

    // ── سمت ادمین ─────────────────────────────────────────────────────────

    #[Test]
    public function a_brand_new_ticket_is_unread_for_admin_even_without_replies(): void
    {
        $ticket = $this->makeTicket()->load('replies');

        $this->assertTrue($ticket->isUnreadForAdmin());
        $this->assertSame(0, $ticket->unreadForAdminCount());
    }

    #[Test]
    public function opening_the_ticket_marks_it_read_for_admin(): void
    {
        $ticket = $this->makeTicket();
        $ticket->markReadByAdmin();

        $this->assertFalse($ticket->fresh()->load('replies')->isUnreadForAdmin());
    }

    #[Test]
    public function a_later_user_reply_makes_it_unread_again(): void
    {
        $ticket = $this->makeTicket();
        $ticket->markReadByAdmin();
        $this->laterBy();
        $this->reply($ticket, adminId: null);

        $fresh = $ticket->fresh()->load('replies');
        $this->assertSame(1, $fresh->unreadForAdminCount());
        $this->assertTrue($fresh->isUnreadForAdmin());
    }

    #[Test]
    public function the_admins_own_reply_does_not_count_as_unread_for_admin(): void
    {
        $admin = $this->admin();
        $ticket = $this->makeTicket();
        $ticket->markReadByAdmin();
        $this->laterBy();
        $this->reply($ticket, adminId: $admin->id);

        $this->assertSame(0, $ticket->fresh()->load('replies')->unreadForAdminCount());
    }

    // ── سمت کاربر ─────────────────────────────────────────────────────────

    #[Test]
    public function a_support_reply_is_unread_for_the_owner(): void
    {
        $admin = $this->admin();
        $ticket = $this->makeTicket();
        $ticket->markReadByUser();
        $this->laterBy();
        $this->reply($ticket, adminId: $admin->id);

        $this->assertSame(1, $ticket->fresh()->load('replies')->unreadForUserCount());
    }

    #[Test]
    public function the_users_own_reply_does_not_count_as_unread_for_the_user(): void
    {
        $ticket = $this->makeTicket();
        $ticket->markReadByUser();
        $this->laterBy();
        $this->reply($ticket, adminId: null);

        $this->assertSame(0, $ticket->fresh()->load('replies')->unreadForUserCount());
    }

    // ── endpoint ──────────────────────────────────────────────────────────

    #[Test]
    public function the_admin_show_response_still_reports_the_unread_count_it_is_clearing(): void
    {
        // اگر اول mark بزنیم و بعد Resource بسازیم، ادمین همان لحظه صفر می‌بیند و
        // هرگز نمی‌فهمد چه چیزی تازه بود.
        $admin = $this->admin();
        $ticket = $this->makeTicket();
        $ticket->markReadByAdmin();
        $this->laterBy();
        $this->reply($ticket, adminId: null);
        $this->laterBy();

        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        // و بلافاصله بعدش خوانده شده است
        $this->getJson("/api/admin/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    #[Test]
    public function kpis_count_tickets_the_panel_has_not_seen(): void
    {
        $service = app(TicketService::class);
        $before = $service->kpis()['unread'];

        $unseen = $this->makeTicket();

        $seen = $this->makeTicket();
        $seen->markReadByAdmin();

        $this->assertSame($before + 1, app(TicketService::class)->kpis()['unread']);

        // یک پاسخ تازه از کاربر روی تیکتِ خوانده‌شده → دوباره شمرده می‌شود
        $this->laterBy();
        $this->reply($seen, adminId: null);
        $this->assertSame($before + 2, app(TicketService::class)->kpis()['unread']);

        $this->assertNotNull($unseen->id);
    }
}
