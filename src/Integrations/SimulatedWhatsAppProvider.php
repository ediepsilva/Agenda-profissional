<?php
declare(strict_types=1);

namespace App\Integrations;

/**
 * Modo simulado (sem credenciais): nada é enviado. A mensagem é marcada como "enviada
 * (simulação)" e o texto fica visível no painel, para testar os fluxos sem a Meta.
 */
final class SimulatedWhatsAppProvider implements WhatsAppProvider
{
    public function name(): string
    {
        return 'simulado';
    }

    public function sendTemplate(string $toE164, string $templateName, string $language, array $params, string $previewText): array
    {
        return ['ok' => true, 'id' => 'sim-' . bin2hex(random_bytes(8))];
    }
}
