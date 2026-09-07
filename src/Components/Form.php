<?php

namespace Code16\Systempay\Components;

use Code16\Systempay\Exceptions\SystempayMissingPaymentConfigException;
use Code16\Systempay\Systempay;
use Illuminate\View\Component;

class Form extends Component
{
    public function __construct(
        protected ?Systempay $config = null
    ) {
        if (!$config) {
            throw new SystempayMissingPaymentConfigException('Please provide a SystemPay payment configuration to build the form');
        }
    }

    public function render()
    {
        return view('systempay::components.form', [
            'config' => $this->config,
        ]);
    }
}
