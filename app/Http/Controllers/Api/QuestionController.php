<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreQuestionRequest;
use App\Mail\QuestionEmail;
use App\Models\Question;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class QuestionController extends Controller
{
    public function submitQuestion(StoreQuestionRequest $request)
    {
        Question::create($request->validated());

        Mail::to($request->email)->send(new QuestionEmail($request->validated(), 'user'));

        Mail::to(Config::get('app.companyInfoEmailAddress'))->send(new QuestionEmail($request->validated(), 'support'));

        return success([], __('global.received_successfully'));
    }
}
