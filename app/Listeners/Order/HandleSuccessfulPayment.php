<?php

namespace App\Listeners\Order;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Events\Order\OrderPaid;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleSuccessfulPayment implements ShouldQueue
{
    public function __construct(
        protected ProcessPreScalePaymentAction $action
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->action->execute($event->order);
    }
}
