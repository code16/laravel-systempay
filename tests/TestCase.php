<?php

namespace Code16\Systempay\Tests;

use Code16\Systempay\SystempayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            SystempayServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('systempay', [
            'default' => [
                'site_id' => '12345678',
                'key' => '1122334455667788',
                'env' => 'TEST',
                'rest' => [
                    'password' => 'testpassword_1122334455667788',
                ],
            ],
        ]);
    }
}
