<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyEmail extends Mailable
{
    use SerializesModels;

    public mixed $token;

    public string $link;

    public function __construct(?string $token)
    {
        $this->link = env('FRONT_APP_URL').'/verify-email/'.$token;
    }

    public function build(): VerifyEmail
    {
        return $this->subject('تایید ایمیل شما در الیت زون')
            ->view('emails.verify-email')
            ->with(['link' => $this->link]);
    }
}
