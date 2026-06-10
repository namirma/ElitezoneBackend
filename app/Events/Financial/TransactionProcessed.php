<?php

namespace App\Events\Financial;

use App\Models\WalletTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionProcessed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public WalletTransaction $transaction) {}
}
