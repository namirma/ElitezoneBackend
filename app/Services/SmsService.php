<?php

namespace App\Services;

use App\Http\Classes\Constant;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;

class SmsService
{
    private string $link, $from, $username, $password;

    public function __construct()
    {
        $this->link = Constant::TSMS_LINK;
        $this->from = Constant::TSMS_NUMBER;
        $this->username = Constant::TSMS_USERNAME;
        $this->password = Constant::TSMS_PASSWORD;
    }

    public function send($to, $message)
    {
        $link = $this->link . '?from=' . $this->from . '&to=' . $to . '&username=' . $this->username . '&password=' . $this->password . '&message=' . urlencode($message);
        $response = Http::get($link);
        return $response->getBody();
    }
}
