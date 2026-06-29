<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestEmail extends Mailable
{
    use SerializesModels;

    public function build(): TestEmail
    {
        return $this->subject('Test Email')
            ->view('emails.test');

    }
}
