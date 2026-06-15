<?php

namespace Code16\Systempay\Components;

use Code16\Systempay\Exceptions\SystemPayMissingPaymentConfigException;
use Code16\Systempay\SystemPay;
use Illuminate\View\Component;

class Form extends Component
{
    public function __construct(
        protected ?SystemPay $config = null
    ) {
        if(!$config) {
            throw new SystemPayMissingPaymentConfigException('Please provide a SystemPay payment configuration to build the form');
        }
    }

    public function render()
    {
        return view('systempay::components.form', [
            'config' => $this->config
        ]);
    }
}