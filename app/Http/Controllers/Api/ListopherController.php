<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreListopherRequest;
use App\Models\Listopher;
use Illuminate\Http\Request;

class ListopherController extends Controller
{
    public function store(StoreListopherRequest $request)
    {

        if (Listopher::where([
            Listopher::PROJECT_CODE => $request->validated('project_code'),
            Listopher::TYPE => $request->validated('type'),
        ])->exists()) {
            $listopher = Listopher::where(
                [
                    Listopher::PROJECT_CODE => $request->validated('project_code'),
                    Listopher::TYPE => $request->validated('type')
                ],
            )->first();
            $listopher->items()->delete();

        }
        else {
            $listopher = Listopher::create([Listopher::PROJECT_CODE => $request->validated('project_code'), Listopher::TYPE => $request->validated('type')],);
        }

        $items = collect($request->validated('items'))->map(function ($item) {
            return [
                'pos' => $item['pos'],
                'b' => $item['b'] ?? null,
                'h' => $item['h'] ?? null,
                'shape' => $item['shape'],
                'length' => $item['length'],
                'size' => $item['size'],
                'hook' => $item['hook'] ?? null,
                'overlap' => $item['overlap'] ?? null,
                'quantity' => $item['quantity'],
                'length_total' => $item['lengthTotal'] ?? null,
            ];
        });

        $listopher->items()->createMany($items);

        return success([], 'global.listopherSavedSuccessfully');

    }
}
