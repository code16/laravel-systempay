<?php

use App\Services\FakeIpnClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts the return params to the IPN url with vads_url_check_src and a valid signature', function () {
    config(['systempay.default.key' => 'test-key']);
    Http::fake();

    app(FakeIpnClient::class)->post([
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

        return $request->url() === route('webhooks.systempay')
            && $data['vads_url_check_src'] === 'PAY'
            && $data['vads_trans_id'] === 'OXYZ99'
            && $data['vads_threeds_xid'] === ''
            && $data['signature'] === $expectedSignature;
    });
});
