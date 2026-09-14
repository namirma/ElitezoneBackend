<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پاسخِ نامعتبر به تیکت باید ۴۲۲ با خطای فیلد بدهد، نه ۵۰۰.
 *
 * ‏`TicketReplyController` هر خطا را با `error($e->getMessage(), [], $e->getCode())`
 * برمی‌گرداند. `ValidationException` کدِ ۰ دارد، پس پاسخ با وضعیتِ HTTPِ نامعتبرِ ۰
 * ساخته می‌شد و خودش به ۵۰۰ می‌رسید: کاربری که متن را خالی فرستاده بود به‌جای
 * «متن الزامی است» یک خطای بی‌نام می‌دید.
 */
class TicketReplyValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function ticketOwnedBy(User $owner): Ticket
    {
        $department = Department::create([
            'name' => 'پشتیبانی',
            'slug' => 'support-'.uniqid(),
            'is_available_for_ticket' => true,
        ]);

        return Ticket::create([
            Ticket::CODE => 'TK-'.Str::random(8),
            Ticket::USER_ID => $owner->id,
            Ticket::DEPARTMENT_ID => $department->id,
            Ticket::SUBJECT => 'پرسش',
            Ticket::MESSAGE => 'متن اولیه',
            Ticket::STATUS => Ticket::STATUS_OPEN,
        ]);
    }

    #[Test]
    public function an_empty_reply_is_a_422_with_the_field_error_not_a_500(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $ticket = $this->ticketOwnedBy($owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/ticket/{$ticket->code}/reply", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    #[Test]
    public function a_valid_reply_is_still_created(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $ticket = $this->ticketOwnedBy($owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/ticket/{$ticket->code}/reply", ['message' => 'پاسخ آزمایشی'])
            ->assertCreated();

        $this->assertDatabaseHas('ticket_replies', ['ticket_id' => $ticket->id, 'message' => 'پاسخ آزمایشی']);
    }
}
