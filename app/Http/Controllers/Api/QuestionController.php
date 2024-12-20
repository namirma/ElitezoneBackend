<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Mail\QuestionEmail;
use App\Mail\VerifyEmail;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class QuestionController extends Controller
{
    public function submitQuestion(StoreQuestionRequest $request)
    {
        Question::create($request->validated());
        Mail::to(config('companyEmailAddress'))->send(new QuestionEmail());

        return success([], __('global.received_successfully'));
    }

}
