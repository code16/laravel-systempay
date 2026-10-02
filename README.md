# Systempay tools for Laravel

## Features

* Fast and easy form generation for Systempay (by Banque Populaire)
* Support multiple site id for multiple stores within the same project
* Validate payment signature and status
* Cancel or refund a transaction (Using the API-REST)
* Retrieve a transaction's data (Using the API-REST)
* Simulate IPN calls in local environments, where Systempay cannot reach your webserver

> [!NOTE]
> If you want to use this SystemPay client to cancel or refund transactions, you must create also a REST API key in the SystemPay Back Office.

## Table of contents

* [Installation](#installation)
* [Configuration](#configuration)
* [IPN Callback](#ipn-callback)
* [Testing the IPN locally](#testing-the-ipn-locally)
* [Create a payment form](#create-a-payment-form)
* [Cancel or refund a payment](#cancel-or-refund-a-payment)
* [Get a transaction](#get-a-transaction)

## Installation

1. Install the package

```bash
composer require code16/laravel-systempay
```

2. Publish the config file

```bash
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

        'rest' => [
            // Only required to use cancel()/refund()/cancelOrRefund()/getTransaction().
            'password' => env('SYSTEMPAY_REST_API_PASSWORD'),
        ],
    ]
];
```

You need to set `YOUR_SITE_ID` and `YOUR_KEY` with your own values. These two values are given by Systempay.

`key` is only used to sign/verify the payment form and IPN callbacks. To use `cancel()`, `refund()`,
`cancelOrRefund()` (see [Cancel or refund a payment](#cancel-or-refund-a-payment)) or `getTransaction()`
(see [Get a transaction](#get-a-transaction)), you also need to set `rest.password`, the REST API
password found in the Back Office, under **Paramétrage > Boutique > Clés d'API REST** (use the test
or production password depending on `env`).

### Specific parameters

These parameters are set by default :

| name | default value | note |
|---|---|---|
| currency | 978 | [List of currency codes](https://www.iban.com/currency-codes) | 
| payment_config | SINGLE | SINGLE or MULTIPLE |
| trans_date | [current datetime] | Generated automatically |
| page_action | PAYMENT |  |
| action_mode | INTERACTIVE |  |
| version | V2 |  |
| signature | [generated] | Generated automatically |

Also see [Systempay documentation](https://paiement.systempay.fr/doc/fr-FR/form-payment/quick-start-guide/envoyer-un-formulaire-de-paiement-en-post.html)

**NB** : you don't have to add the `vads_` prefix to parameters, the prefix will be automatically added.
But you can also set the parameters with the `vads_` prefix, it will be automatically removed.

**NB** : `amount` must be given as an integer in the smallest currency unit expected by Systempay
(e.g. cents for EUR). For example, use `1234` for 12.34€, not `12.34`. No unit conversion is
performed by this package.

It is also possible to set some specific parameters to a configuration by setting `params` values.

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

You can add as many configurations as you need by adding a new key to the configuration file.

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
    'amount' => 1234, // 12.34€, in cents
    'trans_id' => 123456
]);
```

## IPN Callback

When a payment is processed, Systempay sends a POST request to your IPN URL (to be configured in your Systempay back office). 

You can use the `Systempay` facade to validate the signature and check the payment status.

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SystemPay;

class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        // 1. Retrieve the webhook data
        $payload = SystemPay::formatWebhookPayload($request);

        // 2. Validate the signature
        if (! $payload->validateSignature()) {
            abort(403, 'Invalid signature');
        }

        // 3. Check if the payment is valid (status is ACCEPTED, CAPTURED, or AUTHORISED)
        if (! $payload->isValidPayment()) {
            // Payment refused or cancelled
            abort(400, 'Invalid payment');
        }

        // 4. Verify the paid amount and currency
        abort_unless(Order::find($payload->orderId)->amount == $payload->amount, 400, 'Invalid amount');

        // Update your database...

        return response()->json(['status' => 'ok']);
    }
}
```

### Webhook payload

`formatWebhookPayload()` looks up the signing key for the given configuration (`default` unless
specified) and returns a `Code16\Systempay\WebhookPayload` object with the following read-only
properties:

| property | type | source field |
|---|---|---|
| `orderId` | `string` | `vads_order_id` |
| `transactionId` | `string` | `vads_trans_id` |
| `transactionUuid` | `string` | `vads_trans_uuid` |
| `amount` | `int` | `vads_amount` |
| `currencyCode` | `string` | `vads_currency` |
| `status` | `string` | `vads_trans_status` |

It also exposes `validateSignature()` and `isValidPayment()`, so the whole IPN payload — data and
validation — comes from a single object.

### Signature validation for specific configuration

If you have multiple configurations, pass the configuration name to `formatWebhookPayload`:

```php
SystemPay::formatWebhookPayload($request, 'store_uk')->validateSignature();
```

### Customize valid payment status

By default, `isValidPayment()` returns true if the status is `CAPTURED`, `ACCEPTED`, or `AUTHORISED`. You can customize this by passing an array of valid statuses as the parameter:

```php
$payload->isValidPayment(['CAPTURED']);
```

## Testing the IPN locally

Systempay cannot reach a webserver running on your machine, so the IPN is never called during local
development. `Code16\Systempay\FakeIpnClient` works around this: it takes the `vads_*` parameters
Systempay sends back with the customer when they return to your shop, and posts them to your IPN
endpoint, as Systempay would have done.

The return parameters do not include `vads_url_check_src`, so the client adds it (set to `PAY`) and
signs the payload again with the configuration key (`default` unless specified), so that `validateSignature()` passes
in your IPN controller.

To receive the parameters on your return URL, set `return_mode` (to `GET` or `POST`) and `url_return`
when building the form, then call the client from the return controller, in local only:

```php
use Code16\Systempay\FakeIpnClient;
use Illuminate\Http\Request;

class PaymentReturnController extends Controller
{
    public function __invoke(Request $request)
    {
        if (app()->isLocal()) {
            FakeIpnClient::make(route('webhooks.systempay'))
                ->post($request->all());
        }

        // Show the payment result page...
    }
}
```

`post()` only keeps `vads_*` parameters, so you can pass the whole request. It returns the
`Illuminate\Http\Client\Response` of your IPN endpoint and throws an
`Illuminate\Http\Client\RequestException` if the endpoint answers with an error status.
SSL verification is disabled, so self-signed local certificates work.

If you use [several configurations](#additional-configuration), pass the configuration name as the
second argument of `make()` so the payload is signed with the right key:

```php
FakeIpnClient::make(route('webhooks.systempay'), 'store_uk')->post($request->all());
```

> [!WARNING]
> Only use this client in local environments: it bypasses Systempay entirely and would let anyone
> who reaches your return URL trigger your IPN logic.

## Create a payment form

To create a payment form, you can use the `Systempay` facade.

In your controller :

```php
<?php namespace App\Http\Controllers;

use SystemPay; // Facade

class PaymentController extends Controller
{
    public function create()
    {
        $systemPay = SystemPay::set([
            'amount' => 1234, // 12.34€, in cents
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

## Cancel or refund a payment

Use `cancel()` to cancel a transaction that has not been captured yet (i.e. before it is remised
en banque), and `refund()` to refund a captured transaction, totally or partially. Both need the
transaction `uuid`, as returned by `formatWebhookPayload()`.

```php
use SystemPay;

// Cancel a transaction, with an optional comment
SystemPay::cancel($uuid, 'Order cancelled by customer');

// Refund a transaction in full
SystemPay::refund($uuid);

// Refund a transaction partially: amount in the smallest currency unit (e.g. cents for EUR)
SystemPay::refund($uuid, amount: 1000, currency: 'EUR');
```

Both throw `Code16\Systempay\Exceptions\SystempayApiException` if Systempay rejects the request
(e.g. the transaction cannot be cancelled or refunded in its current state). The exception's
`response()` method returns the decoded API response, including the `errorCode` returned by
Systempay:

```php
use Code16\Systempay\Exceptions\SystempayApiException;

try {
    SystemPay::refund($uuid);
} catch (SystempayApiException $e) {
    logger()->error($e->getMessage(), $e->response());
}
```

Under the hood, both call the `Transaction/CancelOrRefund` Web Service, which lets Systempay pick
the operation automatically based on the transaction's status. You can call it directly and force
the operation with `resolutionMode` (`AUTO`, `CANCELLATION_ONLY` or `REFUND_ONLY`):

```php
SystemPay::cancelOrRefund($uuid, amount: 1000, currency: 'EUR', resolutionMode: 'REFUND_ONLY');
```

As with `formatWebhookPayload()`, pass a configuration name as the last argument to target a specific
store:

```php
SystemPay::refund($uuid, config: 'store_uk');
```

## Get a transaction

Use `getTransaction()` to retrieve all the data Systempay holds for a transaction, identified by
its `uuid` (as returned by `formatWebhookPayload()`):

```php
$transaction = SystemPay::getTransaction($uuid);

$transaction['status']; // e.g. "CAPTURED"
$transaction['amount']; // in the smallest currency unit (e.g. cents for EUR)
```

It calls the `Transaction/Get` Web Service and, like `cancel()`/`refund()`, throws
`SystempayApiException` if Systempay rejects the request, and accepts a configuration name as the
second argument:

```php
SystemPay::getTransaction($uuid, 'store_uk');
```
