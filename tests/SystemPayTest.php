<?php

use Code16\Systempay\Components\Form;
use Code16\Systempay\Exceptions\InvalidSystempaySignatureException;
use Code16\Systempay\Exceptions\SystempayApiException;
use Code16\Systempay\Exceptions\SystempayConfigException;
use Code16\Systempay\Exceptions\SystempayMissingPaymentConfigException;
use Code16\Systempay\Facades\Systempay;
use Code16\Systempay\WebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;

test('config not found', function () {
    Systempay::config('noconfig');
})->throws(SystempayConfigException::class, 'No configuration "noconfig" found');

test('signature sha256', function () {
    $pay = Systempay::set([
        'amount' => 5124,
        'trans_date' => '20170129130025',
        'trans_id' => '123456',
    ]);

    $render = Blade::render('<x-systempay::form :config="$pay"></x-systempay::form>', [
        'pay' => $pay,
    ]);

    expect($render)->toMatch('#name="signature" value="ycA5Do5tNvsnKdc\/eP1bj2xa19z9q3iWPy9\/rpesfS0\="#');
});

test('blade extension', function () {
    $payment = (new Code16\Systempay\Systempay())->set([
        'amount' => 5124,
        'trans_date' => '20170129130025',
        'trans_id' => '123456',
    ]);

    $render = Blade::render('<x-systempay::form :config="$payment"><button type="submit">Pay</button></x-systempay::form>', [
        'payment' => $payment,
    ]);

    expect($render)->toContain('name="vads_amount" value="5124"')
        ->and($render)->toContain('name="signature"');
});

test('blade component with custom variable name', function () {
    $myPayment = (new Code16\Systempay\Systempay())->set([
        'amount' => 5124,
        'trans_date' => '20170129130025',
        'trans_id' => '123456',
    ]);

    $render = Blade::render('<x-systempay::form :config="$myPayment"><button type="submit">Pay</button></x-systempay::form>', [
        'myPayment' => $myPayment,
    ]);

    expect($render)->toContain('name="vads_amount" value="5124"')
        ->and($render)->toContain('name="signature"');
});

test('blade component with default button', function () {
    $payment = (new Code16\Systempay\Systempay())->set([
        'amount' => 5124,
        'trans_date' => '20170129130025',
        'trans_id' => '123456',
    ]);

    $render = Blade::render('<x-systempay::form :config="$payment" />', [
        'payment' => $payment,
    ]);

    expect($render)->toContain('<button type="submit">Pay</button>');
});

test('validate signature', function () {
    $request = new Request([
        'vads_amount' => '5124',
        'vads_trans_date' => '20170129130025',
        'vads_site_id' => '12345678',
        'vads_ctx_mode' => 'TEST',
        'signature' => 'onkKR1MfdjBzrD7WB0J87mekhoy6kqGukaFsU+t09gA=',
    ]);

    expect(Systempay::formatWebhookPayload($request)->validateSignature())->toBeTrue();
});

test('formatWebhookPayload uses the key from the given configuration', function () {
    config()->set('systempay.store_uk', [
        'site_id' => '87654321',
        'key' => '99887766554433221100',
        'env' => 'PRODUCTION',
    ]);

    $request = new Request([
        'vads_amount' => '1000',
        'vads_trans_date' => '20250101010101',
        'vads_site_id' => '87654321',
        'vads_ctx_mode' => 'PRODUCTION',
        'signature' => 'Kn9DbAO+UPsMizxZG5ZRAj65JwssNt4cuBN09yJJ5Fk=',
    ]);

    expect(Systempay::formatWebhookPayload($request, 'store_uk')->validateSignature())->toBeTrue();

    Systempay::formatWebhookPayload($request)->validateSignature();
})->throws(InvalidSystempaySignatureException::class);

test('is valid payment', function () {
    $request = new Request([
        'vads_url_check_src' => 'PAY',
        'vads_trans_status' => 'ACCEPTED',
    ]);

    expect(Systempay::formatWebhookPayload($request)->isValidPayment())->toBeTrue();

    $request = new Request([
        'vads_url_check_src' => 'PAY',
        'vads_trans_status' => 'REFUSED',
    ]);

    expect(Systempay::formatWebhookPayload($request)->isValidPayment())->toBeFalse();
});

test('format webhook payload', function () {
    $request = new Request([
        'vads_order_id' => 'ORDER123',
        'vads_trans_id' => 'TRANS456',
        'vads_trans_uuid' => 'UUID789',
        'vads_amount' => '5124',
        'vads_currency' => '978',
        'vads_trans_status' => 'CAPTURED',
    ]);

    $payload = Systempay::formatWebhookPayload($request);

    expect($payload)->toBeInstanceOf(WebhookPayload::class)
        ->and($payload->orderId)->toBe('ORDER123')
        ->and($payload->transactionId)->toBe('TRANS456')
        ->and($payload->transactionUuid)->toBe('UUID789')
        ->and($payload->amount)->toBe(5124)
        ->and($payload->currencyCode)->toBe('978')
        ->and($payload->status)->toBe('CAPTURED');
});

test('format webhook payload defaults to zero amount and empty strings when missing', function () {
    $request = new Request();

    $payload = Systempay::formatWebhookPayload($request);

    expect($payload->orderId)->toBe('')
        ->and($payload->transactionId)->toBe('')
        ->and($payload->transactionUuid)->toBe('')
        ->and($payload->amount)->toBe(0)
        ->and($payload->currencyCode)->toBe('')
        ->and($payload->status)->toBe('');
});

test('set stores amount as the integer cents given, with no unit conversion', function () {
    $pay = (new Code16\Systempay\Systempay())->set('amount', 5124);

    expect($pay->prepareFormParams()['vads_amount'])->toBe('5124');
});

test('set removes a param when value is null or an empty string', function () {
    $pay = (new Code16\Systempay\Systempay())
        ->set('trans_id', '123456')
        ->set('trans_id', null);

    expect($pay->prepareFormParams())->not->toHaveKey('vads_trans_id');

    $pay = (new Code16\Systempay\Systempay())
        ->set('trans_id', '123456')
        ->set('trans_id', '');

    expect($pay->prepareFormParams())->not->toHaveKey('vads_trans_id');
});

test('set treats vads_-prefixed and unprefixed keys as the same param', function () {
    $pay = (new Code16\Systempay\Systempay())
        ->set('trans_id', 'FIRST')
        ->set('vads_trans_id', 'SECOND');

    $params = $pay->prepareFormParams();
    $keys = array_keys($params);

    expect($keys)->toBe(array_unique($keys))
        ->and($params['vads_trans_id'])->toBe('SECOND');
});

test('config seeds the expected default params', function () {
    $params = (new Code16\Systempay\Systempay())->prepareFormParams();

    expect($params['vads_site_id'])->toBe('12345678')
        ->and($params['vads_ctx_mode'])->toBe('TEST')
        ->and($params['vads_amount'])->toBe('0')
        ->and($params['vads_page_action'])->toBe('PAYMENT')
        ->and($params['vads_action_mode'])->toBe('INTERACTIVE')
        ->and($params['vads_payment_config'])->toBe('SINGLE')
        ->and($params['vads_version'])->toBe('V2')
        ->and($params['vads_currency'])->toBe('978');
});

test('config allows a custom api url', function () {
    config()->set('systempay.custom', [
        'site_id' => '12345678',
        'key' => '1122334455667788',
        'env' => 'TEST',
        'api_url' => 'https://custom.example.com/vads-payment/',
    ]);

    $pay = (new Code16\Systempay\Systempay())->config('custom');

    expect($pay->url)->toBe('https://custom.example.com/vads-payment/');
});

test('validate signature throws when the sent signature does not match', function () {
    $request = new Request([
        'vads_amount' => '5124',
        'vads_trans_date' => '20170129130025',
        'vads_site_id' => '12345678',
        'vads_ctx_mode' => 'TEST',
        'signature' => 'not-the-right-signature',
    ]);

    Systempay::formatWebhookPayload($request)->validateSignature();
})->throws(InvalidSystempaySignatureException::class);

test('formatWebhookPayload throws when the config key is missing', function () {
    $request = new Request(['signature' => 'irrelevant']);

    Systempay::formatWebhookPayload($request, 'missing');
})->throws(SystempayConfigException::class, 'No key found for config missing');

test('config throws when the key is missing', function () {
    config()->set('systempay.no_key', [
        'site_id' => '12345678',
        'env' => 'TEST',
    ]);

    new Code16\Systempay\Systempay('no_key');
})->throws(SystempayConfigException::class, 'No key found for config no_key');

test('is valid payment returns false when url check src is not PAY', function () {
    $request = new Request([
        'vads_url_check_src' => 'OTHER',
        'vads_trans_status' => 'ACCEPTED',
    ]);

    expect(Systempay::formatWebhookPayload($request)->isValidPayment())->toBeFalse();
});

test('is valid payment accepts a custom list of valid statuses', function () {
    $request = new Request([
        'vads_url_check_src' => 'PAY',
        'vads_trans_status' => 'CUSTOM_STATUS',
    ]);

    $payload = Systempay::formatWebhookPayload($request);

    expect($payload->isValidPayment())->toBeFalse()
        ->and($payload->isValidPayment(['CUSTOM_STATUS']))->toBeTrue();
});

test('form component throws when no payment config is provided', function () {
    new Form();
})->throws(SystempayMissingPaymentConfigException::class, 'Please provide a SystemPay payment configuration to build the form');

test('cancel sends a CANCELLATION_ONLY request to Transaction/CancelOrRefund', function () {
    Http::fake([
        'api.systempay.fr/*' => Http::response([
            'status' => 'SUCCESS',
            'answer' => ['uuid' => 'UUID789', 'status' => 'UNPAID', 'detailedStatus' => 'CANCELLED'],
        ]),
    ]);

    $answer = Systempay::cancel('UUID789', 'customer request');

    expect($answer)->toBe(['uuid' => 'UUID789', 'status' => 'UNPAID', 'detailedStatus' => 'CANCELLED']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.systempay.fr/api-payment/V4/Transaction/CancelOrRefund'
            && $request->method() === 'POST'
            && $request['uuid'] === 'UUID789'
            && $request['resolutionMode'] === 'CANCELLATION_ONLY'
            && $request['comment'] === 'customer request'
            && !isset($request['amount'])
            && !isset($request['currency'])
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('12345678:testpassword_1122334455667788'));
    });
});

test('refund sends a REFUND_ONLY request with the given amount and currency', function () {
    Http::fake([
        'api.systempay.fr/*' => Http::response([
            'status' => 'SUCCESS',
            'answer' => ['uuid' => 'UUID789', 'status' => 'CAPTURED', 'detailedStatus' => 'REFUNDED'],
        ]),
    ]);

    $answer = Systempay::refund('UUID789', 1000, 'EUR');

    expect($answer)->toBe(['uuid' => 'UUID789', 'status' => 'CAPTURED', 'detailedStatus' => 'REFUNDED']);

    Http::assertSent(function ($request) {
        return $request['uuid'] === 'UUID789'
            && $request['amount'] === 1000
            && $request['currency'] === 'EUR'
            && $request['resolutionMode'] === 'REFUND_ONLY';
    });
});

test('refund without an amount omits amount and currency to refund the full transaction', function () {
    Http::fake(['api.systempay.fr/*' => Http::response(['status' => 'SUCCESS', 'answer' => []])]);

    Systempay::refund('UUID789');

    Http::assertSent(function ($request) {
        return $request['uuid'] === 'UUID789'
            && !isset($request['amount'])
            && !isset($request['currency'])
            && $request['resolutionMode'] === 'REFUND_ONLY';
    });
});

test('cancelOrRefund defaults to AUTO resolution mode', function () {
    Http::fake(['api.systempay.fr/*' => Http::response(['status' => 'SUCCESS', 'answer' => []])]);

    Systempay::cancelOrRefund('UUID789');

    Http::assertSent(fn ($request) => $request['resolutionMode'] === 'AUTO');
});

test('cancelOrRefund throws a SystemPayApiException when the API returns an error status', function () {
    Http::fake([
        'api.systempay.fr/*' => Http::response([
            'status' => 'ERROR',
            'answer' => ['errorCode' => 'PSP_100', 'errorMessage' => 'Transaction not found'],
        ]),
    ]);

    try {
        Systempay::cancel('UUID789');
        expect(false)->toBeTrue('Expected SystemPayApiException to be thrown');
    } catch (SystempayApiException $e) {
        expect($e->getMessage())->toContain('Transaction not found')
            ->and($e->getMessage())->toContain('PSP_100')
            ->and($e->response())->toHaveKey('status', 'ERROR');
    }
});

test('cancelOrRefund throws when the REST API password is missing', function () {
    config()->set('systempay.no_password', [
        'site_id' => '12345678',
        'key' => '1122334455667788',
        'env' => 'TEST',
    ]);

    Systempay::cancelOrRefund('UUID789', config: 'no_password');
})->throws(SystempayConfigException::class, 'No REST API credentials (site_id/password) found for config no_password');

test('cancelOrRefund throws when the config is not found', function () {
    Systempay::cancelOrRefund('UUID789', config: 'noconfig');
})->throws(SystempayConfigException::class, 'No configuration "noconfig" found');

test('cancelOrRefund uses a custom rest_api_url when configured', function () {
    config()->set('systempay.custom_rest', [
        'site_id' => '12345678',
        'key' => '1122334455667788',
        'env' => 'TEST',
        'rest' => [
            'password' => 'testpassword_1122334455667788',
        ],
        'rest_api_url' => 'https://custom.example.com/api-payment/V4',
    ]);

    Http::fake(['custom.example.com/*' => Http::response(['status' => 'SUCCESS', 'answer' => []])]);

    Systempay::cancelOrRefund('UUID789', config: 'custom_rest');

    Http::assertSent(fn ($request) => $request->url() === 'https://custom.example.com/api-payment/V4/Transaction/CancelOrRefund');
});

test('getTransaction retrieves a transaction by uuid', function () {
    Http::fake([
        'api.systempay.fr/*' => Http::response([
            'status' => 'SUCCESS',
            'answer' => ['uuid' => 'UUID789', 'amount' => 5124, 'currency' => '978', 'status' => 'CAPTURED'],
        ]),
    ]);

    $transaction = Systempay::getTransaction('UUID789');

    expect($transaction)->toBe(['uuid' => 'UUID789', 'amount' => 5124, 'currency' => '978', 'status' => 'CAPTURED']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.systempay.fr/api-payment/V4/Transaction/Get'
            && $request->method() === 'POST'
            && $request['uuid'] === 'UUID789'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('12345678:testpassword_1122334455667788'));
    });
});

test('getTransaction throws a SystemPayApiException when the API returns an error status', function () {
    Http::fake([
        'api.systempay.fr/*' => Http::response([
            'status' => 'ERROR',
            'answer' => ['errorCode' => 'PSP_050', 'errorMessage' => 'Transaction not found'],
        ]),
    ]);

    try {
        Systempay::getTransaction('UUID789');
        expect(false)->toBeTrue('Expected SystemPayApiException to be thrown');
    } catch (SystempayApiException $e) {
        expect($e->getMessage())->toContain('Transaction not found')
            ->and($e->getMessage())->toContain('PSP_050');
    }
});

test('getTransaction throws when the REST API password is missing', function () {
    config()->set('systempay.no_password', [
        'site_id' => '12345678',
        'key' => '1122334455667788',
        'env' => 'TEST',
    ]);

    Systempay::getTransaction('UUID789', 'no_password');
})->throws(SystempayConfigException::class, 'No REST API credentials (site_id/password) found for config no_password');
