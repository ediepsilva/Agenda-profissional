<?php
declare(strict_types=1);

namespace App\Integrations;

/** Envio de mensagens-modelo (templates) pelo WhatsApp. */
interface WhatsAppProvider
{
    public function name(): string;

    /**
     * @param string[] $params valores de {{1}}, {{2}}... na ordem do modelo
     * @return array{ok:bool, id?:string, error?:string, retryable?:bool}
     */
    public function sendTemplate(string $toE164, string $templateName, string $language, array $params, string $previewText): array;
}
