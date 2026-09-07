# Changelog

All notable changes to `laravel-systempay` will be documented in this file.

## v3.0.2

### Fixed

- Fixed package auto-discovery: `composer.json`'s `extra.laravel.providers`/`aliases` entries were
  missing their full namespace (a leftover from the `SystemPay` → `Systempay` rename in v3.0.0), so
  Laravel silently failed to register the service provider and facade in any app that relies on
  auto-discovery. **If you're on v3.0.0 or v3.0.1, upgrade to v3.0.2.**

## v3.0.0

### Breaking changes

- **Classes renamed from `SystemPay` to `Systempay`.** The `SystemPay` facade alias itself is
  unchanged, but direct imports must be updated:

  | Before | After |
  |---|---|
  | `Code16\Systempay\SystemPay` | `Code16\Systempay\Systempay` |
  | `Code16\Systempay\Facades\SystemPay` | `Code16\Systempay\Facades\Systempay` |
  | `Code16\Systempay\SystemPayServiceProvider` | `Code16\Systempay\SystempayServiceProvider` |
  | `Code16\Systempay\Exceptions\SystemPayApiException` | `Code16\Systempay\Exceptions\SystempayApiException` |
  | `Code16\Systempay\Exceptions\SystemPayConfigException` | `Code16\Systempay\Exceptions\SystempayConfigException` |
  | `Code16\Systempay\Exceptions\SystemPayMissingPaymentConfigException` | `Code16\Systempay\Exceptions\SystempayMissingPaymentConfigException` |
  | `Code16\Systempay\Exceptions\InvalidSystemPaySignatureException` | `Code16\Systempay\Exceptions\InvalidSystempaySignatureException` |

- **IPN webhook handling consolidated into a `WebhookPayload` object.** `validateSignature()`,
  `isValidPayment()`, `retrieveOrderAndTransaction()`, and `retrievePaymentAmountAndCurrency()` are
  removed from the `Systempay` facade. Use `formatWebhookPayload()` instead — it returns a single
  `WebhookPayload` object carrying both the data and the validation logic:

  ```php
  // Before
  SystemPay::validateSignature($request);
  SystemPay::isValidPayment($request);
  [$orderId, $transId, $uuid] = SystemPay::retrieveOrderAndTransaction($request);
  [$amount, $currency] = SystemPay::retrievePaymentAmountAndCurrency($request);

  // After
  $payload = SystemPay::formatWebhookPayload($request);
  $payload->validateSignature();
  $payload->isValidPayment();
  $payload->orderId; $payload->transactionId; $payload->transactionUuid;
  $payload->amount; $payload->currencyCode;
  ```

- **Config: REST API password moved under `rest`.** Update your published `config/systempay.php`:

  ```php
  // Before
  'password' => env('SYSTEMPAY_REST_API_PASSWORD'),

  // After
  'rest' => [
      'password' => env('SYSTEMPAY_REST_API_PASSWORD'),
  ],
  ```

### Added

- REST API transaction management: `cancel()`, `refund()`, `cancelOrRefund()`, and `getTransaction()`.
