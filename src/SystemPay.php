<?php

namespace Code16\Systempay;

use Code16\Systempay\Exceptions\InvalidSystemPaySignatureException;
use Code16\Systempay\Exceptions\Sha256NotAvailableException;
use Code16\Systempay\Exceptions\SystemPayConfigException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SystemPay {

    protected string $key;
    protected array $params = [];
    public string $url = 'https://paiement.systempay.fr/vads-payment/';

    /**
     * Systempay constructor.
     * @throws SystemPayConfigException
     * @param string $config
     */
    public function __construct(string $config = 'default')
    {
        return $this->config($config);
    }

    /**
     * @param string $config
     * @throws SystemPayConfigException
     * @return self
     */
    public function config(string $configName = 'default'): static
    {
        if (!$config = config("systempay.{$configName}")) {
            throw new SystemPayConfigException(sprintf('No configuration "%s" found', $configName));
        }

        $this->key = $config['key'] ?? '';

        if (!isset($config['params'])) {
            $config['params'] = [];
        }

        if(isset($config['api_url'])) {
            // allow to use a custom endpoint
            $this->url = $config['api_url'];
        }

        $this->set($config['params'] + [
                'ctx_mode'       => $config['env'] ?? '',
                'site_id'        => $config['site_id'] ?? '',
                'amount'         => 0,
                'page_action'    => 'PAYMENT',
                'action_mode'    => 'INTERACTIVE',
                'payment_config' => 'SINGLE',
                'version'        => 'V2',
                'currency'       => '978',
            ]);

        return $this;
    }

    /**
     * Set parameter(s). You can do a massive assignment by passing an associative array as $param.
     * @param string|array $param
     * @param string       $value
     * @return self
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

            if ($k === 'amount') {
                $v = (int) ($v * 100);
            }

            $this->params[$k] = (string) $v;
        }

        ksort($this->params);

        return $this;
    }

    /**
     * @throws Sha256NotAvailableException
     * @return string
     */
    private function getSignature(): string
    {
        $str = implode('+', $this->params).'+'.$this->key;

        if(($this->params['signature_algo'] ?? null) === 'sha1') {
            $params = $this->params;
            unset($params['signature_algo']);
            $str = implode('+', $params).'+'.$this->key;
            return sha1($str);
        }

        if (!in_array('sha256', hash_hmac_algos())) {
            throw new Sha256NotAvailableException('Algorithm SHA-256 is not available on this server');
        }

        return base64_encode(hash_hmac('sha256', $str, $this->key, true));
    }

    /**
     * @param Request $request The IPN request
     * @param string $config The config profile to use
     * @return bool
     * @throws InvalidSystemPaySignatureException
     * @throws SystemPayConfigException
     */
    public function validateSignature(Request $request, string $config = 'default'): bool
    {
        $key = config("systempay.{$config}.key");

        if(empty($key)) {
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
     * @param Request $request The IPN request
     * @param array $validStatus The list of valid status (default: ['CAPTURED', 'ACCEPTED', 'AUTHORISED'])
     * @return bool
     */
    public function isValidPayment(Request $request, array $validStatus = ['CAPTURED', 'ACCEPTED', 'AUTHORISED']): bool
    {
        return $request->string('vads_url_check_src') == 'PAY'
            && in_array($request->string('vads_trans_status'), $validStatus);
    }

    /**
     * @param Request $request
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


    /**
     * Prepare the form parameters.
     * @throws Sha256NotAvailableException
     * @return array
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
}
