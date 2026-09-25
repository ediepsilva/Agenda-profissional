<?php
declare(strict_types=1);

namespace App\Integrations;

use Throwable;

/**
 * Mercado Pago — Checkout Pro (Pix, cartão, boleto).
 * - Cria preferência: POST /checkout/preferences  → init_point (link de pagamento)
 * - Notificação: POST notification_url com {type:"payment", data:{id}} e cabeçalho x-signature
 * - Confirmação: GET /v1/payments/{id} (nunca confiar só no corpo da notificação)
 */
final class MercadoPagoGateway implements PaymentGateway
{
    private const API = 'https://api.mercadopago.com';

    public function __construct(
        private HttpClient $http,
        private string $accessToken,
        private string $webhookSecret,
    ) {
    }

    public function name(): string
    {
        return 'mercadopago';
    }

    public function createCheckout(string $externalReference, string $title, int $amountCents, string $payerName, string $notificationUrl, string $returnUrl): array
    {
        $body = [
            'items' => [[
                'title' => mb_substr($title, 0, 250),
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => round($amountCents / 100, 2),
            ]],
            'payer' => ['name' => $payerName],
            'external_reference' => $externalReference,
            'notification_url' => $notificationUrl,
            'back_urls' => ['success' => $returnUrl, 'pending' => $returnUrl, 'failure' => $returnUrl],
            'auto_return' => 'approved',
            'statement_descriptor' => 'AGENDA',
        ];
        try {
            $res = $this->http->request('POST', self::API . '/checkout/preferences', $this->headers() + [
                'X-Idempotency-Key' => $externalReference,
            ], json_encode($body, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $data = json_decode($res['body'], true) ?? [];
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($data['id'], $data['init_point'])) {
            return ['ok' => true, 'preference_id' => (string) $data['id'], 'url' => (string) $data['init_point']];
        }
        return ['ok' => false, 'error' => (string) ($data['message'] ?? 'HTTP ' . $res['status'])];
    }

    public function fetchPayment(string $paymentId): array
    {
        if (!preg_match('/^\d{1,30}$/', $paymentId)) {
            return ['ok' => false, 'error' => 'id inválido'];
        }
        try {
            $res = $this->http->request('GET', self::API . '/v1/payments/' . $paymentId, $this->headers());
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $d = json_decode($res['body'], true) ?? [];
        if ($res['status'] !== 200 || !isset($d['status'])) {
            return ['ok' => false, 'error' => 'HTTP ' . $res['status']];
        }
        $method = match ($d['payment_type_id'] ?? '') {
            'bank_transfer' => 'pix',
            'credit_card' => 'cartao_credito',
            'debit_card' => 'cartao_debito',
            default => 'outro',
        };
        return [
            'ok' => true,
            'status' => (string) $d['status'],
            'external_reference' => (string) ($d['external_reference'] ?? ''),
            'amount_cents' => (int) round(((float) ($d['transaction_amount'] ?? 0)) * 100),
            'method' => $method,
            'date' => substr((string) ($d['date_approved'] ?? $d['date_created'] ?? ''), 0, 10),
        ];
    }

    /**
     * x-signature: "ts=...,v1=..."; manifesto "id:{data.id};request-id:{x-request-id};ts:{ts};"
     * assinado com HMAC-SHA256 usando a chave secreta do webhook.
     */
    public function validNotification(array $headers, string $dataId): bool
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $sig = (string) ($h['x-signature'] ?? '');
        $requestId = (string) ($h['x-request-id'] ?? '');
        if ($this->webhookSecret === '' || $sig === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $sig) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k] = $v;
        }
        if (empty($parts['ts']) || empty($parts['v1'])) {
            return false;
        }
        $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $parts['ts'] . ';';
        return hash_equals(hash_hmac('sha256', $manifest, $this->webhookSecret), $parts['v1']);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->accessToken, 'Content-Type' => 'application/json'];
    }
}
