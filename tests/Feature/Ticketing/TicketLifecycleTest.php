<?php

namespace Tests\Feature\Ticketing;

use App\DataTransferObjects\Ticket\TicketDTO;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the helpdesk behaviour added per the design spec ("بخش پشتیبانی"):
 * initial status + SLA stamping on create, lifecycle timestamps + reopen counting
 * on status transitions, internal notes, order-ownership guard, and KPI shape.
 *
 * DatabaseTransactions: all writes roll back; the live DB is never mutated.
 */
class TicketLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private TicketService $service;

    private User $user;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TicketService::class);
        $this->user = User::factory()->create();
        $this->department = Department::create([
            'name' => 'پشتیبانی',
            'slug' => 'support-'.uniqid(),
            'is_available_for_ticket' => true,
        ]);
    }

    private function makeTicket(string $priority = Ticket::PRIORITY_HIGH): Ticket
    {
        Sanctum::actingAs($this->user);

        return $this->service->create(new TicketDTO(
            department_id: $this->department->id,
            subject: 'تست',
            message: 'متن تست',
            priority: $priority,
        ));
    }

    /** @test */
    public function create_stamps_open_status_priority_and_sla_deadline(): void
    {
        $ticket = $this->makeTicket(Ticket::PRIORITY_HIGH);

        $this->assertSame(Ticket::STATUS_OPEN, $ticket->status);
        $this->assertSame(Ticket::PRIORITY_HIGH, $ticket->priority);
        $this->assertNotNull($ticket->sla_due_at);

        // high priority SLA = 8h by default → deadline is in the future, < 9h out.
        $this->assertTrue($ticket->sla_due_at->isFuture());
        $this->assertTrue($ticket->sla_due_at->lessThan(now()->addHours(9)));
    }

    /** @test */
    public function answering_stamps_first_response_once(): void
    {
        $ticket = $this->makeTicket();

        $this->service->changeStatus($ticket, Ticket::STATUS_ANSWERED);
        $firstAt = $ticket->fresh()->first_responded_at;
        $this->assertNotNull($firstAt);

        // A later transition must NOT overwrite the first-response timestamp.
        $this->service->changeStatus($ticket, Ticket::STATUS_WAITING_CUSTOMER);
        $this->service->changeStatus($ticket, Ticket::STATUS_ANSWERED);
        $this->assertEquals($firstAt, $ticket->fresh()->first_responded_at);
    }

    /** @test */
    public function resolving_then_reopening_counts_the_reopen_and_clears_end_timestamps(): void
    {
        $ticket = $this->makeTicket();

        $this->service->changeStatus($ticket, Ticket::STATUS_RESOLVED);
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->assertSame(0, (int) $ticket->fresh()->reopened_count);

        $this->service->changeStatus($ticket, Ticket::STATUS_OPEN);
        $reopened = $ticket->fresh();
        $this->assertSame(1, (int) $reopened->reopened_count);
        $this->assertNull($reopened->resolved_at);
    }

    /** @test */
    public function adding_a_note_persists_it_against_the_ticket(): void
    {
        $ticket = $this->makeTicket();

        $note = $this->service->addNote($ticket, (int) $this->user->id, 'یادداشت داخلی');

        $this->assertDatabaseHas('ticket_notes', [
            'id' => $note->id,
            'ticket_id' => $ticket->id,
            'note' => 'یادداشت داخلی',
        ]);
        $this->assertCount(1, $ticket->fresh()->notes);
    }

    /** @test */
    public function attaching_an_order_the_user_does_not_own_is_rejected(): void
    {
        $stranger = User::factory()->create();
        $foreignOrder = \App\Models\Order::create([
            \App\Models\Order::PLACER_USER_ID => $stranger->id,
            \App\Models\Order::ORDER_NUMBER => 'TCK-ORD-'.uniqid(),
            \App\Models\Order::STATUS => \App\Models\Order::STATUS_PENDING,
            \App\Models\Order::PAYMENT_STATUS => 'pending',
        ]);

        Sanctum::actingAs($this->user);
        $this->expectException(AuthorizationException::class);

        $this->service->create(new TicketDTO(
            department_id: $this->department->id,
            subject: 'x',
            message: 'y',
            order_id: $foreignOrder->id,
        ));
    }

    /** @test */
    public function opening_message_attachments_are_persisted_against_the_ticket(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user);

        $ticket = $this->service->create(new TicketDTO(
            department_id: $this->department->id,
            subject: 'با پیوست',
            message: 'متن',
            file: [UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')],
        ));

        $files = $ticket->fresh()->files;
        $this->assertCount(1, $files);
        $this->assertSame('doc.pdf', $files->first()->original_name);
        Storage::disk('public')->assertExists($files->first()->path);
    }

    /** @test */
    public function kpis_return_the_expected_shape(): void
    {
        $this->makeTicket();
        $kpis = $this->service->kpis();

        $this->assertArrayHasKey('total', $kpis);
        $this->assertArrayHasKey('open', $kpis);
        $this->assertArrayHasKey('sla_breaches', $kpis);
        $this->assertArrayHasKey('by_status', $kpis);
        $this->assertArrayHasKey('avg_first_response_minutes', $kpis);
        $this->assertArrayHasKey('reopen_rate', $kpis);
        $this->assertGreaterThanOrEqual(1, $kpis['total']);
    }
}
