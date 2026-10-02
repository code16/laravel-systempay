<?php

namespace Code16\Systempay;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use function App\Services\collect;
use function App\Services\config;
use function App\Services\route;

/**
 * Local dev only: posts Systempay return params to our own IPN endpoint, as Systempay would do
 * if it could reach it. Return params carry no vads_url_check_src, so it is added and the
 * payload is signed again.
 */
class FakeIpnClient
{
    /**
     * @param  array<string, mixed>  $params
     *
     * @throws RequestException
     */
    public function post(array $params): Response
    {
        $params = collect($params)
            ->filter(fn ($value, $name) => str_starts_with($name, 'vads_'))
            ->map(fn ($value) => $value ?? '') // empty strings were converted to null by the middleware, and would be dropped from the form body
            ->put('vads_url_check_src', 'PAY')
            ->sortKeys();

        $key = config('systempay.default.key');
        $signature = base64_encode(hash_hmac('sha256', $params->implode('+').'+'.$key, $key, true));

        return Http::asForm()
            ->withoutVerifying()
            ->post(route('webhooks.systempay'), [...$params, 'signature' => $signature])
            ->throw();
    }
}
