<?php

namespace Code16\Systempay;

use Code16\Systempay\Exceptions\InvalidSystempaySignatureException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Represents the data sent by Systempay in an IPN (webhook) call.
 *
 * @see Systempay::formatWebhookPayload()
 */
final readonly class WebhookPayload
{
    public function __construct(
        public string $orderId,
        public string $transactionId,
        public string $transactionUuid,
        public int $amount,
        public string $currencyCode,
        public string $status,
        private string $urlCheckSrc,
        private array $signedParams,
        private string $signature,
        private string $key,
    ) {
    }

    public static function fromRequest(Request $request, string $key): self
    {
        $signedParams = collect($request->all())
            ->filter(fn ($value, $paramName) => Str::startsWith($paramName, 'vads_'))
            ->sortKeys()
            ->values()
            ->toArray();

        return new self(
            orderId: $request->string('vads_order_id')->toString(),
            transactionId: $request->string('vads_trans_id')->toString(),
            transactionUuid: $request->string('vads_trans_uuid')->toString(),
            amount: $request->string('vads_amount')->toInteger(),
            currencyCode: $request->string('vads_currency')->toString(),
            status: $request->string('vads_trans_status')->toString(),
            urlCheckSrc: $request->string('vads_url_check_src')->toString(),
            signedParams: $signedParams,
            signature: $request->string('signature')->toString(),
            key: $key,
        );
    }

    /**
     * @throws InvalidSystempaySignatureException
     */
    public function validateSignature(): bool
    {
        $builtSignature = base64_encode(
            hash_hmac(
                'sha256',
                implode('+', $this->signedParams).'+'.$this->key,
                $this->key,
                true
            )
        );

        if ($builtSignature !== $this->signature) {
            throw new InvalidSystempaySignatureException(
                "Computed signature and sent signature do not match: {$builtSignature} vs {$this->signature}"
            );
        }

        return true;
    }

    /**
     * @param  array  $validStatus  The list of valid status (default: ['CAPTURED', 'ACCEPTED', 'AUTHORISED'])
     */
    public function isValidPayment(array $validStatus = ['CAPTURED', 'ACCEPTED', 'AUTHORISED']): bool
    {
        return $this->urlCheckSrc === 'PAY'
            && in_array($this->status, $validStatus);
    }
}
