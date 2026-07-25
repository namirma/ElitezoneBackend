<?php

use App\Http\Controllers\Api\Ticket\TicketController;
use App\Http\Controllers\Api\Ticket\TicketFileController;
use App\Http\Controllers\Api\Ticket\TicketReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/departments', [TicketController::class, 'getDepartments']);
    Route::get('/', [TicketController::class, 'index']);
    Route::post('/', [TicketController::class, 'store']);

    // دانلود پیوست — دسترسی داخل خود کنترلر بررسی می‌شود (صاحب تیکت یا ادمین)،
    // چون پارامتر روت فایل است نه تیکت و can-see-ticket اینجا کاربرد ندارد.
    Route::get('/files/{ticketFile}', [TicketFileController::class, 'download'])
        ->whereNumber('ticketFile')
        ->name('ticket.file.download');

    Route::middleware('can-see-ticket')->group(function () {
        Route::get('/{ticket:code}', [TicketController::class, 'show']);
        Route::post('/{ticket:code}/reply', [TicketReplyController::class, 'store']);
    });
});
