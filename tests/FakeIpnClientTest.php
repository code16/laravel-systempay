<?php

use Code16\Systempay\FakeIpnClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts the return params to the IPN url with vads_url_check_src and a valid signature', function () {
    config(['systempay.default.key' => 'test-key']);
    Http::fake();

    FakeIpnClient::make('https://example.test/systempay/ipn')->post([
        'vads_trans_id' => 'OXYZ99',
        'vads_trans_status' => 'AUTHORISED',
        'vads_amount' => '5000',
        'vads_threeds_xid' => null,
        'signature' => 'return-signature',
    ]);

    Http::assertSent(function (Request $request) {
        $data = $request->data();
        $signed = collect($data)->except('signature')->sortKeys()->implode('+');
        $expectedSignature = base64_encode(hash_hmac('sha256', $signed.'+test-key', 'test-key', true));

        return $request->url() === 'https://example.test/systempay/ipn'
            && $data['vads_url_check_src'] === 'PAY'
            && $data['vads_trans_id'] === 'OXYZ99'
            && $data['vads_threeds_xid'] === ''
            && $data['signature'] === $expectedSignature;
    });
});

it('signs the payload with the key of a custom config', function () {
    config([
        'systempay.default.key' => 'default-key',
        'systempay.shop2.key' => 'shop2-key',
    ]);
    Http::fake();

    FakeIpnClient::make('https://example.test/systempay/ipn', 'shop2')->post([
        'vads_trans_id' => 'OXYZ99',
        'vads_amount' => '5000',
    ]);

    Http::assertSent(function (Request $request) {
        $data = $request->data();
        $signed = collect($data)->except('signature')->sortKeys()->implode('+');
        $expectedSignature = base64_encode(hash_hmac('sha256', $signed.'+shop2-key', 'shop2-key', true));

        return $data['signature'] === $expectedSignature;
    });
});
