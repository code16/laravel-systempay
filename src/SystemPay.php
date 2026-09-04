<?php

namespace Code16\Systempay;

use Code16\Systempay\Exceptions\InvalidSystemPaySignatureException;
use Code16\Systempay\Exceptions\Sha256NotAvailableException;
use Code16\Systempay\Exceptions\SystemPayApiException;
use Code16\Systempay\Exceptions\SystemPayConfigException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SystemPay
{
    protected const REST_API_URL = 'https://api.systempay.fr/api-payment/V4/';

    protected string $key;
    protected array $params = [];
    public string $url = 'https://paiement.systempay.fr/vads-payment/';

    /**
     * Systempay constructor.
     *
     * @throws SystemPayConfigException
     */
    public function __construct(string $config = 'default')
    {
        return $this->config($config);
    }

    /**
     * @param  string  $config
     * @return self
     *
     * @throws SystemPayConfigException
     */
    public function config(string $configName = 'default'): static
    {
        if (!$config = config("systempay.{$configName}")) {
            throw new SystemPayConfigException(sprintf('No configuration "%s" found', $configName));
        }

        if (empty($config['key'])) {
            throw new SystemPayConfigException('No key found for config '.$configName);
        }

        $this->key = $config['key'];

        if (!isset($config['params'])) {
            $config['params'] = [];
        }

        if (isset($config['api_url'])) {
            // allow to use a custom endpoint
            $this->url = $config['api_url'];
        }

        $this->set($config['params'] + [
            'ctx_mode' => $config['env'] ?? '',
            'site_id' => $config['site_id'] ?? '',
            'amount' => 0,
            'page_action' => 'PAYMENT',
            'action_mode' => 'INTERACTIVE',
            'payment_config' => 'SINGLE',
            'version' => 'V2',
            'currency' => '978',
        ]);

        return $this;
    }

    /**
     * Set parameter(s). You can do a massive assignment by passing an associative array as $param.
     *
     * Note: `amount` must be given as an integer in the smallest currency unit (e.g. cents for
     * EUR), as expected by Systempay. It is stored as-is, no conversion is performed.
     *
     * @param  string|array  $param
     * @param  string  $value
     *
     * @see https://paiement.systempay.fr/doc/fr-FR/form-payment/quick-start-guide/envoyer-un-formulaire-de-paiement-en-post.html
     */
    public function set($param, $value = null): self
    {
        if (is_string($param)) {
            $param = [$param => $value];
        }

        foreach ($param as $k => $v) {
            if ($v === null || $v === '') {
                unset($this->params[$k]);

                continue;
            }

            if (preg_match('#^vads_#', $k)) {
                $k = preg_replace('#^vads_#', '', $k);
            }

            $this->params[$k] = (string) $v;
        }

        ksort($this->params);

        return $this;
    }

    /**
     * @throws Sha256NotAvailableException
     */
    private function getSignature(): string
    {
        if (!in_array('sha256', hash_hmac_algos())) {
            throw new Sha256NotAvailableException('Algorithm SHA-256 is not available on this server');
        }

        $str = implode('+', $this->params).'+'.$this->key;

        return base64_encode(hash_hmac('sha256', $str, $this->key, true));
    }

    /**
     * @param  Request  $request  The IPN request
     * @param  string  $config  The config profile to use
     *
     * @throws InvalidSystemPaySignatureException
     * @throws SystemPayConfigException
     */
    public function validateSignature(Request $request, string $config = 'default'): bool
    {
        $key = config("systempay.{$config}.key");

        if (empty($key)) {
            throw new SystemPayConfigException('No key found for config '.$config);
        }

        $params = collect($request->all())
            ->filter(function ($value, $key) {
                return Str::startsWith($key, 'vads_');
            })
            ->sortKeys()
            ->values();

        $builtSignature = base64_encode(
            hash_hmac(
                'sha256',
                implode('+', $params->toArray()).'+'.$key,
                $key,
                true
            )
        );

        if ($builtSignature != $request->string('signature')) {
            throw new InvalidSystemPaySignatureException(
                "Computed signature and sent signature do not match: {$builtSignature} vs ".$request->string('signature')
            );
        }

        return true;
    }

    /**
     * @param  Request  $request  The IPN request
     * @param  array  $validStatus  The list of valid status (default: ['CAPTURED', 'ACCEPTED', 'AUTHORISED'])
     */
    public function isValidPayment(Request $request, array $validStatus = ['CAPTURED', 'ACCEPTED', 'AUTHORISED']): bool
    {
        return $request->string('vads_url_check_src') == 'PAY'
            && in_array($request->string('vads_trans_status'), $validStatus);
    }

    /**
     * @return array Array of order_id, transaction_id, transaction_uuid
     */
    public function retrieveOrderAndTransaction(Request $request): array
    {
        return [
            $request->string('vads_order_id')->toString(),
            $request->string('vads_trans_id')->toString(),
            $request->string('vads_trans_uuid')->toString(),
        ];
    }

    public function retrievePaymentAmountAndCurrency(Request $request): array
    {
        return [
            $request->string('vads_amount')->toInteger(),
            $request->string('vads_currency')->toString(),
        ];
    }

    /**
     * Prepare the form parameters.
     *
     * @throws Sha256NotAvailableException
     */
    public function prepareFormParams(): array
    {
        if (!isset($this->params['trans_date'])) {
            $this->set('trans_date', gmdate('YmdHis'));
        }

        return [
            ...collect($this->params)->mapWithKeys(fn ($value, $key) => ['vads_'.$key => $value])->toArray(),
            'signature' => $this->getSignature(),
        ];
    }

    /**
     * Cancel a transaction, before it has been captured (i.e. before it is remised en banque).
     *
     * @param  string  $uuid  The transaction UUID (see retrieveOrderAndTransaction())
     *
     * @throws SystemPayApiException
     * @throws SystemPayConfigException
     */
    public function cancel(string $uuid, ?string $comment = null, string $config = 'default'): array
    {
        return $this->cancelOrRefund($uuid, resolutionMode: 'CANCELLATION_ONLY', comment: $comment, config: $config);
    }

    /**
     * Refund a captured transaction, totally or partially.
     *
     * @param  string  $uuid  The transaction UUID (see retrieveOrderAndTransaction())
     * @param  int|null  $amount  The amount to refund, in the smallest currency unit (e.g. cents for
     *                            EUR). Omit to refund the full transaction amount.
     * @param  string|null  $currency  ISO 4217 alpha-3 currency code (e.g. "EUR"). Required when $amount is given.
     *
     * @throws SystemPayApiException
     * @throws SystemPayConfigException
     */
    public function refund(string $uuid, ?int $amount = null, ?string $currency = null, ?string $comment = null, string $config = 'default'): array
    {
        return $this->cancelOrRefund($uuid, amount: $amount, currency: $currency, resolutionMode: 'REFUND_ONLY', comment: $comment, config: $config);
    }

    /**
     * Cancel or refund a transaction. Systempay picks the operation to perform based on the
     * transaction's capture status (AUTO), unless $resolutionMode forces one of them.
     *
     * @param  string  $uuid  The transaction UUID (see retrieveOrderAndTransaction())
     * @param  int|null  $amount  The amount to refund, in the smallest currency unit (e.g. cents for EUR)
     * @param  string|null  $currency  ISO 4217 alpha-3 currency code (e.g. "EUR")
     * @param  string  $resolutionMode  AUTO, CANCELLATION_ONLY or REFUND_ONLY
     *
     * @see https://paiement.systempay.fr/doc/fr-FR/rest/V4.0/api/playground/Transaction/CancelOrRefund
     *
     * @throws SystemPayApiException
     * @throws SystemPayConfigException
     */
    public function cancelOrRefund(
        string $uuid,
        ?int $amount = null,
        ?string $currency = null,
        string $resolutionMode = 'AUTO',
        ?string $comment = null,
        string $config = 'default'
    ): array {
        return $this->restApiCall('Transaction/CancelOrRefund', [
            'uuid' => $uuid,
            'amount' => $amount,
            'currency' => $currency,
            'resolutionMode' => $resolutionMode,
            'comment' => $comment,
        ], $config);
    }

    /**
     * Retrieve all the data Systempay holds for a transaction.
     *
     * @param  string  $uuid  The transaction UUID (see retrieveOrderAndTransaction())
     *
     * @see https://paiement.systempay.fr/doc/fr-FR/rest/V4.0/api/playground/Transaction/Get
     *
     * @throws SystemPayApiException
     * @throws SystemPayConfigException
     */
    public function getTransaction(string $uuid, string $config = 'default'): array
    {
        return $this->restApiCall('Transaction/Get', ['uuid' => $uuid], $config);
    }

    /**
     * Call a Systempay REST API management Web Service (Basic Auth, using site_id/password)
     * and return its "answer". $payload entries set to null are omitted from the request.
     *
     * @throws SystemPayApiException
     * @throws SystemPayConfigException
     */
    protected function restApiCall(string $webService, array $payload, string $config): array
    {
        if (!$configValues = config("systempay.{$config}")) {
            throw new SystemPayConfigException(sprintf('No configuration "%s" found', $config));
        }

        if (empty($configValues['site_id']) || empty($configValues['password'])) {
            throw new SystemPayConfigException('No REST API credentials (site_id/password) found for config '.$config);
        }

        $restApiUrl = rtrim($configValues['rest_api_url'] ?? self::REST_API_URL, '/');

        $response = Http::withBasicAuth($configValues['site_id'], $configValues['password'])
            ->post("{$restApiUrl}/{$webService}", array_filter($payload, fn ($value) => $value !== null));

        if ($response->json('status') !== 'SUCCESS') {
            throw new SystemPayApiException(
                sprintf(
                    'Systempay API error on %s: %s (%s)',
                    $webService,
                    $response->json('answer.errorMessage') ?? $response->body(),
                    $response->json('answer.errorCode') ?? $response->status(),
                ),
                $response->json() ?? []
            );
        }

        return $response->json('answer');
    }
}
