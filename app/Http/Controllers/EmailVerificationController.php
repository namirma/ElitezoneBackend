<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyEmailRequest;
use App\Mail\TestEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailVerificationController extends Controller
{
    public function verify(VerifyEmailRequest $request): void
    {
        $email = $request->input('email');

        Mail::to($email)->send(new TestEmail());
    }
}
