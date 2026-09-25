<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Domain\MessageService;
use App\Domain\OnlinePaymentService;
use App\Integrations\WhatsAppWebhook;

/**
 * Notificações de serviços externos. Nunca confiam no conteúdo sem validar a
 * assinatura HMAC do provedor; respostas curtas e sem dados internos.
 */
final class WebhookController extends Controller
{
    /** Verificação do webhook no painel da Meta (GET com hub.challenge). */
    public function whatsappVerify(): void
    {
        $token = (string) Env::get('WHATSAPP_VERIFY_TOKEN', '');
        // O PHP converte "hub.mode" em "hub_mode" na querystring.
        if ($token !== '' && ($_GET['hub_mode'] ?? '') === 'subscribe' && hash_equals($token, (string) ($_GET['hub_verify_token'] ?? ''))) {
            header('Content-Type: text/plain');
            echo preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['hub_challenge'] ?? ''));
            return;
        }
        http_response_code(403);
        echo 'forbidden';
    }

    public function whatsapp(): void
    {
        $raw = (string) file_get_contents('php://input');
        if (!WhatsAppWebhook::validSignature($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null, (string) Env::get('WHATSAPP_APP_SECRET', ''))) {
            http_response_code(401);
            echo 'invalid signature';
            return;
        }
        $data = WhatsAppWebhook::parse(json_decode($raw, true) ?: []);
        $svc = new MessageService();
        foreach ($data['statuses'] as $s) {
            $svc->applyStatus($s['id'], $s['status'], $s['error']);
        }
        foreach ($data['inbound'] as $m) {
            $svc->handleInbound($m['from'], $m['text']);
        }
        echo 'ok';
    }

    public function mercadopago(): void
    {
        $raw = (string) file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $type = (string) ($_GET['type'] ?? $body['type'] ?? '');
        $id = (string) ($_GET['data_id'] ?? $body['data']['id'] ?? '');
        if ($type !== 'payment' || $id === '') {
            echo 'ignored'; // outros tópicos (merchant_order etc.) não são usados
            return;
        }
        $headers = [
            'x-signature' => $_SERVER['HTTP_X_SIGNATURE'] ?? '',
            'x-request-id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
        ];
        $result = (new OnlinePaymentService())->handleNotification($headers, $id);
        if ($result === 'invalid_signature') {
            http_response_code(401);
        }
        echo $result;
    }
}
