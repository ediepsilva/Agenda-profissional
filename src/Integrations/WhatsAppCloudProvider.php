<?php
declare(strict_types=1);

namespace App\Integrations;

use Throwable;

/**
 * WhatsApp Business Platform — Cloud API (oficial, da Meta).
 * POST https://graph.facebook.com/{versão}/{phone_number_id}/messages
 * Mensagens iniciadas pela empresa precisam usar modelos aprovados no WhatsApp Manager.
 */
final class WhatsAppCloudProvider implements WhatsAppProvider
{
    public function __construct(
        private HttpClient $http,
        private string $token,
        private string $phoneNumberId,
        private string $apiVersion = 'v21.0',
    ) {
    }

    public function name(): string
    {
        return 'whatsapp_cloud';
    }

    public function sendTemplate(string $toE164, string $templateName, string $language, array $params, string $previewText): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $toE164,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
            ],
        ];
        if ($params) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(static fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params)),
            ]];
        }

        try {
            $res = $this->http->request(
                'POST',
                sprintf('https://graph.facebook.com/%s/%s/messages', rawurlencode($this->apiVersion), rawurlencode($this->phoneNumberId)),
                ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'],
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'retryable' => true];
        }

        $data = json_decode($res['body'], true) ?? [];
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($data['messages'][0]['id'])) {
            return ['ok' => true, 'id' => (string) $data['messages'][0]['id']];
        }
        $msg = $data['error']['message'] ?? ('HTTP ' . $res['status']);
        $code = $data['error']['code'] ?? null;
        // 429/5xx e limite de taxa (código 130429/131048/80007) podem ser tentados de novo; erros de modelo/número não.
        $retryable = $res['status'] >= 500 || $res['status'] === 429 || in_array($code, [130429, 131048, 80007], true);
        return ['ok' => false, 'error' => mb_substr(($code ? "[$code] " : '') . $msg, 0, 250), 'retryable' => $retryable];
    }
}
