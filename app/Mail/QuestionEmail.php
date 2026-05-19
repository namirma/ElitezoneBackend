<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
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

        $subject = $this->type === 'user'
            ? 'پیام شما دریافت شد'
            : 'پیام جدید از کاربر دریافت شد';

        return $this->view($view)
            ->subject($subject)
            ->with(['data' => $this->data]);
    }
}
