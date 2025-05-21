<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\FactoryLocationResource;
use App\Http\Resources\FactoryResource;
use App\Models\Factory;
use Illuminate\Http\Request;

class FactoryController extends Controller
{
    public function listFactories(Request $request)
    {
        $factories = Factory::active()
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(Factory::NAME, 'like', '%' . $request->search . '%')
            )
            ->get();

        return success(FactoryResource::collection($factories)); 
    }
    public function listLocations(int $factoryId)
    {
        $factory = Factory::active()
            ->with(['locations' => fn ($q) => $q->active()])
            ->find($factoryId);

        if (!$factory) {
            return error('global.notFound', [], 404);
        }

        return success(
            FactoryLocationResource::collection($factory->locations)
        );
    }
}
