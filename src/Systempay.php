<?php

namespace Code16\Systempay;

use Code16\Systempay\Exceptions\Sha256NotAvailableException;
use Code16\Systempay\Exceptions\SystempayApiException;
use Code16\Systempay\Exceptions\SystempayConfigException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Systempay
{
    protected const REST_API_URL = 'https://api.systempay.fr/api-payment/V4/';

    protected string $key;
    protected array $params = [];
    public string $url = 'https://paiement.systempay.fr/vads-payment/';

    /**
     * Systempay constructor.
     *
     * @throws SystempayConfigException
     */
    public function __construct(string $config = 'default')
    {
        return $this->config($config);
    }

    /**
     * @param  string  $config
     * @return self
     *
     * @throws SystempayConfigException
     */
    public function config(string $configName = 'default'): static
    {
        if (!$config = config("systempay.{$configName}")) {
            throw new SystempayConfigException(sprintf('No configuration "%s" found', $configName));
        }

        if (empty($config['key'])) {
            throw new SystempayConfigException('No key found for config '.$configName);
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
     * @throws SystempayConfigException
     */
    public function formatWebhookPayload(Request $request, string $config = 'default'): WebhookPayload
    {
        $key = config("systempay.{$config}.key");

        if (empty($key)) {
            throw new SystempayConfigException('No key found for config '.$config);
        }

        return WebhookPayload::fromRequest($request, $key);
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
     * @param  string  $uuid  The transaction UUID (see formatWebhookPayload())
     *
     * @throws SystempayApiException
     * @throws SystempayConfigException
     */
    public function cancel(string $uuid, ?string $comment = null, string $config = 'default'): array
    {
        return $this->cancelOrRefund($uuid, resolutionMode: 'CANCELLATION_ONLY', comment: $comment, config: $config);
    }

    /**
     * Refund a captured transaction, totally or partially.
     *
     * @param  string  $uuid  The transaction UUID (see formatWebhookPayload())
     * @param  int|null  $amount  The amount to refund, in the smallest currency unit (e.g. cents for
     *                            EUR). Omit to refund the full transaction amount.
     * @param  string|null  $currency  ISO 4217 alpha-3 currency code (e.g. "EUR"). Required when $amount is given.
     *
     * @throws SystempayApiException
     * @throws SystempayConfigException
     */
    public function refund(string $uuid, ?int $amount = null, ?string $currency = null, ?string $comment = null, string $config = 'default'): array
    {
        return $this->cancelOrRefund($uuid, amount: $amount, currency: $currency, resolutionMode: 'REFUND_ONLY', comment: $comment, config: $config);
    }

    /**
     * Cancel or refund a transaction. Systempay picks the operation to perform based on the
     * transaction's capture status (AUTO), unless $resolutionMode forces one of them.
     *
     * @param  string  $uuid  The transaction UUID (see formatWebhookPayload())
     * @param  int|null  $amount  The amount to refund, in the smallest currency unit (e.g. cents for EUR)
     * @param  string|null  $currency  ISO 4217 alpha-3 currency code (e.g. "EUR")
     * @param  string  $resolutionMode  AUTO, CANCELLATION_ONLY or REFUND_ONLY
     *
     * @throws SystempayApiException
     * @throws SystempayConfigException
     *
     *@see https://paiement.systempay.fr/doc/fr-FR/rest/V4.0/api/playground/Transaction/CancelOrRefund
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
     * @param  string  $uuid  The transaction UUID (see formatWebhookPayload())
     *
     * @throws SystempayApiException
     * @throws SystempayConfigException
     *
     *@see https://paiement.systempay.fr/doc/fr-FR/rest/V4.0/api/playground/Transaction/Get
     */
    public function getTransaction(string $uuid, string $config = 'default'): array
    {
        return $this->restApiCall('Transaction/Get', ['uuid' => $uuid], $config);
    }

    /**
     * Call a Systempay REST API management Web Service (Basic Auth, using site_id/password)
     * and return its "answer". $payload entries set to null are omitted from the request.
     *
     * @throws SystempayApiException
     * @throws SystempayConfigException
     */
    protected function restApiCall(string $webService, array $payload, string $config): array
    {
        if (!$configValues = config("systempay.{$config}")) {
            throw new SystempayConfigException(sprintf('No configuration "%s" found', $config));
        }

        if (empty($configValues['site_id']) || empty($configValues['rest']['password'])) {
            throw new SystempayConfigException('No REST API credentials (site_id/password) found for config '.$config);
        }

        $restApiUrl = rtrim($configValues['rest_api_url'] ?? self::REST_API_URL, '/');

        $response = Http::withBasicAuth($configValues['site_id'], $configValues['rest']['password'])
            ->post("{$restApiUrl}/{$webService}", array_filter($payload, fn ($value) => $value !== null));

        if ($response->json('status') !== 'SUCCESS') {
            throw new SystempayApiException(
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
