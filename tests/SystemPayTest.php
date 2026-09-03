<?php

use Code16\Systempay\Components\Form;
use Code16\Systempay\Exceptions\InvalidSystemPaySignatureException;
use Code16\Systempay\Exceptions\SystemPayConfigException;
use Code16\Systempay\Exceptions\SystemPayMissingPaymentConfigException;
use Code16\Systempay\Facades\SystemPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

test('config not found', function () {
    SystemPay::config('noconfig');
})->throws(SystemPayConfigException::class, 'No configuration "noconfig" found');

test('signature sha256', function () {
    $pay = SystemPay::set([
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
    $payment = (new Code16\Systempay\SystemPay())->set([
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
    $myPayment = (new Code16\Systempay\SystemPay())->set([
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
    $payment = (new Code16\Systempay\SystemPay())->set([
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

    expect(SystemPay::validateSignature($request))->toBeTrue();
});

test('is valid payment', function () {
    $request = new Request([
        'vads_url_check_src' => 'PAY',
        'vads_trans_status' => 'ACCEPTED',
    ]);

    expect(SystemPay::isValidPayment($request))->toBeTrue();

    $request = new Request([
        'vads_url_check_src' => 'PAY',
        'vads_trans_status' => 'REFUSED',
    ]);

    expect(SystemPay::isValidPayment($request))->toBeFalse();
});

test('retrieve order and transaction', function () {
    $request = new Request([
        'vads_order_id' => 'ORDER123',
        'vads_trans_id' => 'TRANS456',
        'vads_trans_uuid' => 'UUID789',
    ]);

    [$orderId, $transId, $uuid] = SystemPay::retrieveOrderAndTransaction($request);

    expect($orderId)->toBe('ORDER123')
        ->and($transId)->toBe('TRANS456')
        ->and($uuid)->toBe('UUID789');
});

test('retrieve payment amount and currency', function () {
    $request = new Request([
        'vads_amount' => '5124',
        'vads_currency' => '978',
    ]);

    [$amount, $currency] = SystemPay::retrievePaymentAmountAndCurrency($request);

    expect($amount)->toBe('5124')
        ->and($currency)->toBe('978');
});

test('retrieve payment amount and currency defaults to empty strings when missing', function () {
    $request = new Request();

    [$amount, $currency] = SystemPay::retrievePaymentAmountAndCurrency($request);

    expect($amount)->toBe('')
        ->and($currency)->toBe('');
});

test('set stores amount as the integer cents given, with no unit conversion', function () {
    $pay = (new Code16\Systempay\SystemPay())->set('amount', 5124);

    expect($pay->prepareFormParams()['vads_amount'])->toBe('5124');
});

test('set removes a param when value is null or an empty string', function () {
    $pay = (new Code16\Systempay\SystemPay())
        ->set('trans_id', '123456')
        ->set('trans_id', null);

    expect($pay->prepareFormParams())->not->toHaveKey('vads_trans_id');

    $pay = (new Code16\Systempay\SystemPay())
        ->set('trans_id', '123456')
        ->set('trans_id', '');

    expect($pay->prepareFormParams())->not->toHaveKey('vads_trans_id');
});

test('set treats vads_-prefixed and unprefixed keys as the same param', function () {
    $pay = (new Code16\Systempay\SystemPay())
        ->set('trans_id', 'FIRST')
        ->set('vads_trans_id', 'SECOND');

    $params = $pay->prepareFormParams();
    $keys = array_keys($params);

    expect($keys)->toBe(array_unique($keys))
        ->and($params['vads_trans_id'])->toBe('SECOND');
});

test('config seeds the expected default params', function () {
    $params = (new Code16\Systempay\SystemPay())->prepareFormParams();

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

    $pay = (new Code16\Systempay\SystemPay())->config('custom');

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

    SystemPay::validateSignature($request);
})->throws(InvalidSystemPaySignatureException::class);

test('validate signature throws when the config key is missing', function () {
    $request = new Request(['signature' => 'irrelevant']);

    SystemPay::validateSignature($request, 'missing');
})->throws(SystemPayConfigException::class, 'No key found for config missing');

test('config throws when the key is missing', function () {
    config()->set('systempay.no_key', [
        'site_id' => '12345678',
        'env' => 'TEST',
    ]);

    new Code16\Systempay\SystemPay('no_key');
})->throws(SystemPayConfigException::class, 'No key found for config no_key');

test('is valid payment returns false when url check src is not PAY', function () {
    $request = new Request([
        'vads_url_check_src' => 'OTHER',
        'vads_trans_status' => 'ACCEPTED',
    ]);

    expect(SystemPay::isValidPayment($request))->toBeFalse();
});

test('is valid payment accepts a custom list of valid statuses', function () {
    $request = new Request([
        'vads_url_check_src' => 'PAY',
        'vads_trans_status' => 'CUSTOM_STATUS',
    ]);

    expect(SystemPay::isValidPayment($request))->toBeFalse()
        ->and(SystemPay::isValidPayment($request, ['CUSTOM_STATUS']))->toBeTrue();
});

test('form component throws when no payment config is provided', function () {
    new Form();
})->throws(SystemPayMissingPaymentConfigException::class, 'Please provide a SystemPay payment configuration to build the form');
