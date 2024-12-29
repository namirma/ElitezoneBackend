<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuestionEmail extends Mailable
{
    use SerializesModels;

    public array $data;

    public function __construct($data = null)
    {
        $this->data = $data;
    }

    public function build(): QuestionEmail
    {
        return $this->view('emails.new-question')->with(['data' => $this->data]);
    }
}
