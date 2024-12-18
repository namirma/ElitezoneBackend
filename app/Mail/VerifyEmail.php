<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmail extends Mailable
{
    use SerializesModels;

    public $token;

    public function __construct($token = null)
    {
        $this->token = $token;
    }


    public function build(): VerifyEmail
    {
        return $this->view('emails.verify-email')->with(['link' => $this->token,]);
    }

}
