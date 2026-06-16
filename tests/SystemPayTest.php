<?php

use Code16\Systempay\Exceptions\SystemPayConfigException;
use Code16\Systempay\Facades\SystemPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

$renderSignature = 'c60bedc09fae8040d35faabb9f526244';

test('config not found', function () {
    SystemPay::config('noconfig');
})->throws(SystemPayConfigException::class, 'No configuration "noconfig" found');

test('signature sha256', function () {
    $pay = SystemPay::set([
        'amount' => 51.24,
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
        'amount' => 51.24,
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
        'amount' => 51.24,
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
        'amount' => 51.24,
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
