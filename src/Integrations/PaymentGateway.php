<?php
declare(strict_types=1);

namespace App\Integrations;

interface PaymentGateway
{
    public function name(): string;

    /**
     * Cria um checkout (link de pagamento).
     * @return array{ok:bool, preference_id?:string, url?:string, error?:string}
     */
    public function createCheckout(string $externalReference, string $title, int $amountCents, string $payerName, string $notificationUrl, string $returnUrl): array;

    /**
     * Consulta um pagamento no gateway (fonte da verdade após a notificação).
     * @return array{ok:bool, status?:string, external_reference?:string, amount_cents?:int, method?:string, date?:string, error?:string}
     */
    public function fetchPayment(string $paymentId): array;

    /** Valida a assinatura da notificação recebida. */
    public function validNotification(array $headers, string $dataId): bool;
}
