<?php

namespace App\Events\User;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerSegmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * اطلاعاتی که باید منتقل شود
     */
    public User $user;

    public string $oldSegment;

    public string $newSegment;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, string $oldSegment, string $newSegment)
    {
        $this->user = $user;
        $this->oldSegment = $oldSegment;
        $this->newSegment = $newSegment;
    }
}
