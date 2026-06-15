<?php

namespace Code16\Systempay\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Code16\Systempay\SystemPay
 */
class SystemPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Code16\Systempay\SystemPay::class;
    }
}
