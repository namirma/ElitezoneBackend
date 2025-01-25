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
    public $type;

    public function __construct($data = null, $type = 'support')
    {
        $this->data = $data;
        $this->type = $type;
    }

    public function build(): QuestionEmail
    {
        $view = $this->type === 'user'
            ? 'emails.user-new-question'
            : 'emails.support-new-question';

        return $this->view($view)->subject('پیام شما دریافت شد')->with(['data' => $this->data]);
    }
}
