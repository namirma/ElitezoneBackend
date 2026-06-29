<?php

namespace App\Events\Financial;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletFunded implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var User
     */
    public $user;

    /**
     * @var WalletTransaction
     */
    public $transaction;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, WalletTransaction $transaction)
    {
        $this->user = $user;
        $this->transaction = $transaction;
    }
}
