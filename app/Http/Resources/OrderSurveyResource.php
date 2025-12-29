<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderSurvey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderSurveyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            OrderSurvey::ID     => $this->{OrderSurvey::ID},
            'order_number'      => $this->order->{Order::ORDER_NUMBER} ?? null,
            OrderSurvey::RATING => $this->{OrderSurvey::RATING},
            OrderSurvey::COMMENT => $this->{OrderSurvey::COMMENT},
            'created_at_p'      => verta($this->{OrderSurvey::CREATED_AT})->format('Y/m/d'),
            'created_at_diff'   => $this->{OrderSurvey::CREATED_AT}->diffForHumans(),
        ];
    }
}
