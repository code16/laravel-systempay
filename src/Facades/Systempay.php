<?php

namespace Code16\Systempay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Code16\Systempay\Systempay
 */
class Systempay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Code16\Systempay\Systempay::class;
    }
}
