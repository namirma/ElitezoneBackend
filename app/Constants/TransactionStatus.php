<?php

namespace App\Constants;

class TransactionStatus
{
    const PENDING = 'pending';

    const PAID = 'paid';

    const FAILED = 'failed';

    const ERR_INSUFFICIENT_BALANCE = 'ERR_LOW_BALANCE';
}
