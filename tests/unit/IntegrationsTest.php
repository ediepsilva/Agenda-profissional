<?php
declare(strict_types=1);

use App\Domain\MessageService;
use App\Integrations\MercadoPagoGateway;
use App\Integrations\WhatsAppCloudProvider;
use App\Integrations\WhatsAppWebhook;
use Tests\FakeHttp;
use Tests\TestCase;

final class IntegrationsTest extends TestCase
{
    public function testWhatsAppSignatureValidation(): void
    {
        $body = '{"entry":[]}';
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'segredo');
        $this->assertTrue(WhatsAppWebhook::validSignature($body, $sig, 'segredo'));
        $this->assertFalse(WhatsAppWebhook::validSignature($body . ' ', $sig, 'segredo'), 'Corpo alterado');
        $this->assertFalse(WhatsAppWebhook::validSignature($body, $sig, 'outro'), 'Segredo errado');
        $this->assertFalse(WhatsAppWebhook::validSignature($body, null, 'segredo'), 'Sem cabeçalho');
        $this->assertFalse(WhatsAppWebhook::validSignature($body, $sig, ''), 'Sem segredo configurado nunca aceita');
    }

    public function testWhatsAppWebhookParsing(): void
    {
        $payload = ['entry' => [['changes' => [['value' => [
            'statuses' => [['id' => 'wamid.1', 'status' => 'read'], ['id' => 'wamid.2', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Undeliverable']]]],
            'messages' => [['from' => '5511987654321', 'type' => 'text', 'text' => ['body' => 'SAIR']]],
        ]]]]]];
        $r = WhatsAppWebhook::parse($payload);
        $this->assertSame(2, count($r['statuses']));
        $this->assertSame('131026 Undeliverable', $r['statuses'][1]['error']);
        $this->assertSame([['from' => '5511987654321', 'text' => 'SAIR']], $r['inbound']);
    }

    public function testOptOutKeywords(): void
    {
        foreach (['SAIR', 'sair.', ' Parar ', 'Stop', 'cancelar', 'Não quero'] as $t) {
            $this->assertTrue(WhatsAppWebhook::isOptOut($t), "\"$t\" deveria revogar");
        }
        foreach (['Obrigada!', 'Vou sair mais cedo do trabalho', 'ok'] as $t) {
            $this->assertFalse(WhatsAppWebhook::isOptOut($t), "\"$t\" não deveria revogar");
        }
    }

    public function testCloudApiRequestFormat(): void
    {
        $http = (new FakeHttp())->push(200, ['messages' => [['id' => 'wamid.ABC']]]);
        $p = new WhatsAppCloudProvider($http, 'TOKEN', '123456', 'v21.0');
        $r = $p->sendTemplate('5511987654321', 'lembrete_atendimento', 'pt_BR', ['Ana', 'Maquiagem'], 'texto');
        $this->assertSame(['ok' => true, 'id' => 'wamid.ABC'], $r);
        $req = $http->last();
        $this->assertSame('https://graph.facebook.com/v21.0/123456/messages', $req['url']);
        $this->assertSame('Bearer TOKEN', $req['headers']['Authorization']);
        $body = $http->lastJson();
        $this->assertSame('template', $body['type']);
        $this->assertSame('lembrete_atendimento', $body['template']['name']);
        $this->assertSame('pt_BR', $body['template']['language']['code']);
        $this->assertSame([['type' => 'text', 'text' => 'Ana'], ['type' => 'text', 'text' => 'Maquiagem']], $body['template']['components'][0]['parameters']);
    }

    public function testCloudApiErrorsClassifiedForRetry(): void
    {
        $http = (new FakeHttp())
            ->push(500, ['error' => ['message' => 'Service unavailable', 'code' => 2]])
            ->push(400, ['error' => ['message' => 'Template name does not exist', 'code' => 132001]]);
        $p = new WhatsAppCloudProvider($http, 'T', '1');
        $a = $p->sendTemplate('5511987654321', 'x', 'pt_BR', [], '');
        $b = $p->sendTemplate('5511987654321', 'x', 'pt_BR', [], '');
        $this->assertTrue($a['retryable']);
        $this->assertFalse($b['retryable']);
        $this->assertTrue(str_contains($b['error'], '132001'));
    }

    public function testMercadoPagoSignature(): void
    {
        $gw = new MercadoPagoGateway(new FakeHttp(), 'TOKEN', 'segredo-mp');
        $ts = '1742505638683';
        $v1 = hash_hmac('sha256', "id:123456;request-id:req-1;ts:$ts;", 'segredo-mp');
        $this->assertTrue($gw->validNotification(['x-signature' => "ts=$ts,v1=$v1", 'x-request-id' => 'req-1'], '123456'));
        $this->assertFalse($gw->validNotification(['x-signature' => "ts=$ts,v1=$v1", 'x-request-id' => 'req-2'], '123456'));
        $this->assertFalse($gw->validNotification(['x-signature' => "ts=$ts,v1=$v1", 'x-request-id' => 'req-1'], '999'));
        $this->assertFalse((new MercadoPagoGateway(new FakeHttp(), 'T', ''))->validNotification(['x-signature' => "ts=$ts,v1=$v1"], '123456'));
    }

    public function testMercadoPagoCheckoutAndPaymentLookup(): void
    {
        $http = (new FakeHttp())
            ->push(201, ['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1'])
            ->push(200, ['status' => 'approved', 'external_reference' => 'ref1', 'transaction_amount' => 60.5, 'payment_type_id' => 'bank_transfer', 'date_approved' => '2030-01-02T10:00:00.000-03:00']);
        $gw = new MercadoPagoGateway($http, 'TOKEN', 's');
        $r = $gw->createCheckout('ref1', 'Sinal', 6050, 'Ana', 'https://x/webhooks/mercadopago', 'https://x/retorno');
        $this->assertTrue($r['ok']);
        $body = $http->lastJson();
        $this->assertSame(60.5, $body['items'][0]['unit_price']);
        $this->assertSame('BRL', $body['items'][0]['currency_id']);
        $this->assertSame('ref1', $body['external_reference']);
        $this->assertSame('ref1', $http->last()['headers']['X-Idempotency-Key']);

        $p = $gw->fetchPayment('987');
        $this->assertSame(['ok' => true, 'status' => 'approved', 'external_reference' => 'ref1', 'amount_cents' => 6050, 'method' => 'pix', 'date' => '2030-01-02'], $p);
        $this->assertSame(['ok' => false, 'error' => 'id inválido'], $gw->fetchPayment('../../etc'));
    }

    public function testTemplateRendering(): void
    {
        $this->assertSame('Olá, Ana! Dia 08/01.', MessageService::render('Olá, {{nome}}! Dia {{data}}.', ['nome' => 'Ana', 'data' => '08/01']));
        $this->assertSame('Oi, !', MessageService::render('Oi, {{inexistente}}!', []));
    }
}
