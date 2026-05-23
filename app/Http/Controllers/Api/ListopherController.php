<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreListopherRequest;
use App\Models\Listopher;

class ListopherController extends Controller
{
    public function store(StoreListopherRequest $request)
    {
        $listopher = Listopher::updateOrCreate(
            [
                Listopher::PROJECT_CODE => $request->validated('project_code'),
                Listopher::TYPE => $request->validated('type'),
            ],
            []
        );

        $items = collect($request->validated('items'))->map(function ($item) {
            return [
                'pos' => (string) $item['pos'],
                'b' => $item['b'] ?? null,
                'h' => $item['h'] ?? null,
                'shape' => (string) $item['shape'],
                'length' => $item['length'],
                'size' => (string) $item['size'],
                'hook' => $item['hook'] ?? null,
                'overlap' => $item['overlap'] ?? null,
                'quantity' => $item['quantity'],
                'length_total' => $item['lengthTotal'] ?? null,
            ];
        });

        $listopher->items()->delete();
        $listopher->items()->createMany($items);

        return success([], 'global.listopherSavedSuccessfully');

    }
}
