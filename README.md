# Systempay tools for Laravel

## Features

* Fast and easy form generation for Systempay (by Banque Populaire)
* Support multiple site id for multiple stores within the same project
* Validate payment signature and status

## Installation

1. Install the package

```
composer require code16/laravel-systempay
```

2. Publish the config file

```
php artisan vendor:publish --tag="systempay-config"
```

## Configuration

After publishing edit the default configuration file : [`config/systempay.php`](config/systempay.php)

```php
return [
    'default' => [
        'site_id' => 'YOUR_SITE_ID',
        'key'     => env('SYSTEMPAY_SITE_KEY', 'YOUR_KEY'),
        'env'     => env('SYSTEMPAY_ENV', 'PRODUCTION'),
    ]
];
```

You need to set `YOUR_SITE_ID` and `YOUR_KEY` with your own values. This two values are given by Systempay.

### Specific parameters

These parameters are set by default :

| name | default value | note |
|---|---|---|
| currency | 978 | [List of currency codes](https://www.iban.com/currency-codes) | 
| payment_config | SINGLE | SINGLE or MULTIPLE |
| trans_date | [current datetime] | Generated automaticaly |
| page_action | PAYMENT |  |
| action_mode | INTERACTIVE |  |
| version | V2 |  |
| signature | [generated] | Generated automaticaly |

Also see [Systempay documentation](https://paiement.systempay.fr/doc/fr-FR/form-payment/quick-start-guide/envoyer-un-formulaire-de-paiement-en-post.html)

**NB** : you don't have to add the `vads_` prefix to parameters, the prefix will be automaticaly added.
But you can also set the parameters with the `vads_` prefix, it will be automaticaly removed.

There is also possible to set some specific parameters to a configuration by setting `params` values.

Example :

```php
return [
    'default' => [
        // ...
        'params'  => [
            'currency' => '826'
        ]
    ]
];
```

In this case, default configuration will use the currency code 826.

### Additional configuration

You can add as many configuration as you need by adding a new key to the configuration file.

For example :

```php
return [
    'default' => [
       // ...
    ],
    'store_uk' => [
        'site_id' => '123456',
        'key'     => env('SYSTEMPAY_UK_SITE_KEY', '12345678'),
        'env'     => env('SYSTEMPAY_UK_ENV', 'PRODUCTION'),   
    ]
];
```

To use another configuration, call the `config` method, for example :

```php 
$systemPay = SystemPay::config('store_uk')->set([
    'amount' => 12.34,
    'trans_id' => 123456
]);
```

## IPN Callback

When a payment is processed, Systempay sends a POST request to your IPN URL (to be configured in your Systempay back office). 

You can use the `SystemPay` facade to validate the signature and check the payment status.

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SystemPay;

class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        // 1. Validate the signature
        if (! SystemPay::validateSignature($request)) {
            abort(403, 'Invalid signature');
        }

        // 2. Check if the payment is valid (status is ACCEPTED, CAPTURED, or AUTHORISED)
        if (! SystemPay::isValidPayment($request)) {
            // Payment refused or cancelled
            return response()->json(['status' => 'error']);
        }

        // 3. Retrieve order information
        [$orderId, $transId, $uuid] = SystemPay::retrieveOrderAndTransaction($request);

        // Update your database...

        return response()->json(['status' => 'ok']);
    }
}
```

### Signature validation for specific configuration

If you have multiple configurations, pass the configuration name to `validateSignature`:

```php
SystemPay::validateSignature($request, 'store_uk');
```

### Customize valid payment status

By default, `isValidPayment` returns true if the status is `CAPTURED`, `ACCEPTED`, or `AUTHORISED`. You can customize this by passing an array of valid statuses as the second parameter:

```php
SystemPay::isValidPayment($request, ['CAPTURED']);
```

## Create a payment form
To create a payment form, you can use the `SystemPay` facade.

In your controller :

```php
<?php namespace App\Http\Controllers;

use SystemPay; // Facade

class PaymentController extends Controller
{
    public function create()
    {
        $systemPay = SystemPay::set([
            'amount' => 12.34,
            'trans_id' => 123456
        ]);
        
        return view('payment', compact('systemPay'));
    }
}
```

In your view

```blade
<x-systempay::form :config="$systemPay">
    <x-slot:button>
        <button type="submit" class="btn btn-primary">
            Pay
        </button>
    </x-slot:button>
</x-systempay::form>
```