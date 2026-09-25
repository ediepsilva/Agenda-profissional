<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use App\Integrations\Integrations;
use App\Integrations\PaymentGateway;
use DomainException;
use PDOException;

/**
 * Pagamento online do sinal/saldo pelo gateway (Mercado Pago Checkout Pro).
 * A reserva só é considerada paga quando o GATEWAY confirma (notificação assinada +
 * consulta do pagamento na API). Cada pagamento do gateway é registrado uma única vez.
 */
final class OnlinePaymentService
{
    public function __construct(private ?PaymentGateway $gateway = null)
    {
        $this->gateway ??= Integrations::payments();
    }

    public function available(): bool
    {
        return $this->gateway !== null && Settings::int('online_payment_enabled', 1) === 1;
    }

    /** Valor a pagar agora: o que falta do sinal; com o sinal quitado, o saldo. */
    public function amountDue(array $booking): array
    {
        $s = (new FinanceService())->bookingSummary($booking);
        if (!$s['deposit_ok']) {
            return ['kind' => 'deposit', 'cents' => $s['deposit'] - $s['deposit_paid']];
        }
        return ['kind' => 'balance', 'cents' => $s['balance']];
    }

    /** Cria (ou reaproveita) o link de pagamento. @return string URL do checkout */
    public function checkoutUrl(array $booking): string
    {
        if (!$this->available()) {
            throw new DomainException('Pagamento online não está configurado.');
        }
        if (!in_array($booking['status'], ['awaiting_deposit', 'confirmed', 'completed'], true)) {
            throw new DomainException('O pagamento fica disponível depois que a profissional aceitar a reserva.');
        }
        $due = $this->amountDue($booking);
        if ($due['cents'] <= 0) {
            throw new DomainException('Não há valor pendente para esta reserva.');
        }
        $existing = Db::one(
            "SELECT * FROM payment_intents WHERE booking_id = ? AND status = 'pending' AND amount_cents = ? AND checkout_url IS NOT NULL AND created_at >= ? ORDER BY id DESC LIMIT 1",
            [$booking['id'], $due['cents'], Clock::now()->modify('-1 day')->format('Y-m-d H:i:s')]
        );
        if ($existing) {
            return $existing['checkout_url'];
        }

        $ref = bin2hex(random_bytes(16));
        $intentId = Db::insert('payment_intents', [
            'booking_id' => $booking['id'], 'provider' => $this->gateway->name(), 'external_reference' => $ref, 'amount_cents' => $due['cents'],
            'created_at' => Clock::now()->format('Y-m-d H:i:s'),
        ]);
        $what = $booking['kind'] === 'event' ? $booking['event_name'] : $booking['service_name'];
        $title = ($due['kind'] === 'deposit' ? 'Sinal' : 'Saldo') . ' - ' . $what . ' em ' . date_br($booking['starts_at']);
        $r = $this->gateway->createCheckout(
            $ref, $title, $due['cents'], (string) $booking['client_name'],
            absolute_url('/webhooks/mercadopago'),
            absolute_url('/pagamento/retorno/' . $booking['public_code'])
        );
        if (!$r['ok']) {
            Db::update('payment_intents', ['status' => 'cancelled'], 'id = ?', [$intentId]);
            throw new DomainException('Não foi possível gerar o link de pagamento: ' . ($r['error'] ?? 'erro'));
        }
        Db::update('payment_intents', ['preference_id' => $r['preference_id'], 'checkout_url' => $r['url']], 'id = ?', [$intentId]);
        return $r['url'];
    }

    /**
     * Processa a notificação do gateway.
     * @return string resultado ('invalid_signature', 'ignored', 'registered', 'duplicate', 'updated')
     */
    public function handleNotification(array $headers, string $paymentId): string
    {
        if (!$this->gateway || !$this->gateway->validNotification($headers, $paymentId)) {
            return 'invalid_signature';
        }
        $p = $this->gateway->fetchPayment($paymentId);
        if (!$p['ok']) {
            return 'ignored';
        }
        $intent = Db::one('SELECT * FROM payment_intents WHERE external_reference = ?', [$p['external_reference'] ?? '']);
        if (!$intent) {
            return 'ignored';
        }

        if ($p['status'] !== 'approved') {
            $map = ['rejected' => 'rejected', 'cancelled' => 'cancelled', 'refunded' => 'cancelled', 'charged_back' => 'cancelled'];
            if (isset($map[$p['status']]) && $intent['status'] === 'pending') {
                Db::update('payment_intents', ['status' => $map[$p['status']]], 'id = ?', [$intent['id']]);
                return 'updated';
            }
            return 'ignored';
        }

        $booking = Db::one('SELECT * FROM bookings WHERE id = ?', [$intent['booking_id']]);
        $kind = (new FinanceService())->bookingSummary($booking)['deposit_ok'] ? 'balance' : 'deposit';
        try {
            Db::transaction(function () use ($intent, $p, $paymentId, $kind) {
                Db::insert('booking_payments', [
                    'booking_id' => $intent['booking_id'],
                    'kind' => $kind,
                    'amount_cents' => $p['amount_cents'],
                    'method' => $p['method'],
                    'paid_on' => $p['date'] ?: Clock::now()->format('Y-m-d'),
                    'note' => 'Pagamento online (' . $this->gateway->name() . ')',
                    'external_id' => $this->gateway->name() . ':' . $paymentId,
                ]);
                Db::update('payment_intents', ['status' => 'approved'], 'id = ?', [$intent['id']]);
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return 'duplicate'; // notificação repetida: já registrado
            }
            throw $e;
        }

        // Sinal quitado: confirma automaticamente (com checagem de conflito).
        $booking = Db::one('SELECT * FROM bookings WHERE id = ?', [$intent['booking_id']]);
        if ($booking['status'] === 'awaiting_deposit' && (new FinanceService())->bookingSummary($booking)['deposit_ok']) {
            try {
                (new BookingService())->changeStatus((int) $booking['id'], 'confirmed', null, 'Confirmada após pagamento online do sinal');
            } catch (DomainException $e) {
                Db::insert('booking_status_history', [
                    'booking_id' => $booking['id'], 'from_status' => $booking['status'], 'to_status' => $booking['status'],
                    'note' => 'Sinal pago online, mas não foi possível confirmar: ' . mb_substr($e->getMessage(), 0, 150),
                ]);
            }
        }
        return 'registered';
    }
}
