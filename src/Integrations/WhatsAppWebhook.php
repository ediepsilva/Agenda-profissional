<?php
declare(strict_types=1);

namespace App\Integrations;

/** Interpretação e validação das notificações (webhooks) da Cloud API do WhatsApp. */
final class WhatsAppWebhook
{
    /** Palavras que pedem para não receber mais ofertas. */
    private const OPT_OUT = ['sair', 'parar', 'pare', 'stop', 'cancelar', 'descadastrar', 'nao quero', 'não quero'];

    /** Header X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(corpo bruto, app secret). */
    public static function validSignature(string $rawBody, ?string $header, string $appSecret): bool
    {
        if ($appSecret === '' || !$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $appSecret), $header);
    }

    /**
     * @return array{statuses: array<int,array{id:string,status:string,error:?string}>, inbound: array<int,array{from:string,text:string}>}
     */
    public static function parse(array $payload): array
    {
        $statuses = [];
        $inbound = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['statuses'] ?? [] as $s) {
                    if (isset($s['id'], $s['status'])) {
                        $statuses[] = [
                            'id' => (string) $s['id'],
                            'status' => (string) $s['status'],
                            'error' => isset($s['errors'][0]) ? (string) (($s['errors'][0]['code'] ?? '') . ' ' . ($s['errors'][0]['title'] ?? '')) : null,
                        ];
                    }
                }
                foreach ($value['messages'] ?? [] as $m) {
                    $text = $m['text']['body'] ?? ($m['button']['text'] ?? ($m['interactive']['button_reply']['title'] ?? ''));
                    if (isset($m['from'])) {
                        $inbound[] = ['from' => (string) $m['from'], 'text' => (string) $text];
                    }
                }
            }
        }
        return ['statuses' => $statuses, 'inbound' => $inbound];
    }

    public static function isOptOut(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $t = trim(preg_replace('/[^\p{L}\s]/u', '', $t));
        foreach (self::OPT_OUT as $w) {
            if ($t === $w || str_starts_with($t, $w . ' ')) {
                return true;
            }
        }
        return false;
    }
}
