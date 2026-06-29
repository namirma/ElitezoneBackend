<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Project base resource.
 *
 * Declares an explicit constructor so Intelephense correctly resolves
 * `new FooResource($model)` calls without raising PHP0441 false-positives
 * (inherited constructors are invisible to Intelephense's argument-count check).
 *
 * All project resources extend this class instead of JsonResource directly.
 */
abstract class BaseJsonResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }
}
