<?php
declare(strict_types=1);

namespace App\Integrations;

use App\Core\Env;

/**
 * Ponto único de configuração das integrações externas (lidas do .env).
 * Sem credenciais: WhatsApp em modo simulado e pagamento online desativado (Pix manual).
 * Os testes substituem o cliente HTTP por um falso.
 */
final class Integrations
{
    private static ?HttpClient $http = null;

    public static function useHttpClient(?HttpClient $http): void
    {
        self::$http = $http;
    }

    public static function http(): HttpClient
    {
        return self::$http ??= new CurlHttpClient();
    }

    public static function whatsappConfigured(): bool
    {
        return Env::get('WHATSAPP_TOKEN', '') !== '' && Env::get('WHATSAPP_PHONE_NUMBER_ID', '') !== '';
    }

    public static function whatsapp(): WhatsAppProvider
    {
        if (self::whatsappConfigured()) {
            return new WhatsAppCloudProvider(
                self::http(),
                (string) Env::get('WHATSAPP_TOKEN'),
                (string) Env::get('WHATSAPP_PHONE_NUMBER_ID'),
                (string) Env::get('WHATSAPP_API_VERSION', 'v21.0'),
            );
        }
        return new SimulatedWhatsAppProvider();
    }

    public static function paymentsConfigured(): bool
    {
        return Env::get('MERCADOPAGO_ACCESS_TOKEN', '') !== '';
    }

    public static function payments(): ?PaymentGateway
    {
        if (!self::paymentsConfigured()) {
            return null;
        }
        return new MercadoPagoGateway(
            self::http(),
            (string) Env::get('MERCADOPAGO_ACCESS_TOKEN'),
            (string) Env::get('MERCADOPAGO_WEBHOOK_SECRET', ''),
        );
    }
}
