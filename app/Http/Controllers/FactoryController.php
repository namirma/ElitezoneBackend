<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\FactoryResource;
use App\Models\Factory;

class FactoryController extends Controller
{
    public function index()
    {
        $query = Factory::query()->active();

        if (request()->filled('search')) {
            $search = request()->input('search');
            $query->where(function ($q) use ($search) {
                $q->where(Factory::NAME, 'like', "%{$search}%");
            });
        }

        $factories = $query->get();

        return response()->json([
            'status' => 'success',
            'data'   => FactoryResource::collection($factories),
        ]);
    }

    public function getLocations($id)
    {
        $factory = Factory::findOrFail($id);
        $locations = $factory->locations()->active()->get();

        return response()->json([
            'status' => 'success',
            'data' => $locations
        ]);
    }
}
