<?php

namespace App\DataTransferObjects;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BaseDTO
{
    public function __construct(array $args = [])
    {
        foreach ($args as $key => $arg)
            if ($this->isConstantDefinedAndNotEmpty('fields') && in_array($key, static::fields))
                $this->{$key} = $arg;
    }

    public function toArray(): array
    {
        $data = [];
        foreach (static::fields as $field)
            if (property_exists($this, $field))
                $data[$field] = $this->{$field};

        return $data;
    }

    public function set($key, $value): void
    {
        $this->{$key} = $value;
    }

    protected function isConstantDefinedAndNotEmpty($constantName): bool
    {
        $class = get_called_class();
        while ($class) {
            if (defined("$class::$constantName")) {
                $constantValue = constant("$class::$constantName");
                return !empty($constantValue);
            }
            $class = get_parent_class($class);
        }

        return false;
    }

    public static function validateAndMake(Request $request, array $rules): static
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails())
            throw new ValidationException($validator);

        return new static($request->only(static::fields));
    }

}
