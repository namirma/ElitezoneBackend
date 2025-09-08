<?php

namespace App\Services\Gateways;

use App\Contracts\GatewayInterface;
use Illuminate\Support\Facades\Config;

class GatewayFactory
{
    public static function make(string $serviceName): GatewayInterface
    {
        $config = Config::get('services.' . $serviceName);

        //TODO errors should handle better in development
        if (!$config || !isset($config['class']))
            throw new \Exception("Service {$serviceName} not configured properly.");

        $class = $config['class'];

        if (!class_exists($class))
            throw new \Exception("Class {$class} does not exist.");

        return new $class($config);
    }
}
