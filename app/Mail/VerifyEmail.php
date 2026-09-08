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
        $this->link = rtrim((string) config('app.front_url'), '/').'/verify-email/'.$token;
    }

    public function build(): VerifyEmail
    {
        // موضوع باید بگوید کاربر چه کاری باید بکند، نه فقط اسم فرآیند را ببرد.
        return $this->subject('یک قدم تا تکمیل حساب شما در الیت‌زون')
            ->view('emails.verify-email')
            ->with(['link' => $this->link]);
    }
}
