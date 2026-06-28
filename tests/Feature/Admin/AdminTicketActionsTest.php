<?php

namespace Tests\Feature\Admin;

use App\DataTransferObjects\Ticket\TicketDTO;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laratrust\Models\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the ticket Assignment + department-routing + Escalation (priority) admin
 * actions — the spec wanted these and `TicketService` already had the logic; they
 * just weren't exposed as admin endpoints until now.
 */
class AdminTicketActionsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->addRole(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']));

        return $user;
    }

    private function department(string $name = 'پشتیبانی'): Department
    {
        return Department::create([
            'name' => $name,
            'slug' => 'dep-'.uniqid(),
            'is_available_for_ticket' => true,
        ]);
    }

    private function makeTicket(): Ticket
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        return app(TicketService::class)->create(new TicketDTO(
            department_id: $this->department()->id,
            subject: 'تست',
            message: 'متن تست',
            priority: Ticket::PRIORITY_NORMAL,
        ));
    }

    #[Test]
    public function admin_can_assign_a_ticket_to_an_admin(): void
    {
        $ticket = $this->makeTicket();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/tickets/{$ticket->id}/assign", ['admin_id' => $admin->id])->assertOk();
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'admin_id' => $admin->id]);
    }

    #[Test]
    public function assigning_to_a_non_admin_is_rejected(): void
    {
        $ticket = $this->makeTicket();
        $stranger = User::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/tickets/{$ticket->id}/assign", ['admin_id' => $stranger->id])
            ->assertStatus(422);
    }

    #[Test]
    public function admin_can_move_a_ticket_to_another_department(): void
    {
        $ticket = $this->makeTicket();
        $target = $this->department('مالی');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/tickets/{$ticket->id}/department", ['department_id' => $target->id])->assertOk();
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'department_id' => $target->id]);
    }

    #[Test]
    public function admin_can_escalate_priority(): void
    {
        $ticket = $this->makeTicket();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/tickets/{$ticket->id}/priority", ['priority' => Ticket::PRIORITY_URGENT])->assertOk();
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'priority' => Ticket::PRIORITY_URGENT]);
    }

    #[Test]
    public function admin_can_list_assignable_departments_and_non_admin_cannot(): void
    {
        $this->department();
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/tickets/departments')->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/tickets/departments')->assertForbidden();
    }
}
