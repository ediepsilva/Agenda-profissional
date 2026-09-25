# Integrações externas — o que falta configurar

O sistema funciona sem nenhuma conta externa:
- **WhatsApp em modo simulado**: as mensagens são geradas, ficam em *Painel → Mensagens* com o texto final e o status "Enviada (simulado)". Nada é enviado de verdade.
- **Pagamento manual por Pix**: a cliente vê a chave Pix cadastrada em *Configurações*; a profissional registra o pagamento no painel.

Para ativar os envios e pagamentos reais, siga os passos abaixo. Todas as credenciais ficam **somente no arquivo `.env`** (nunca no código).

## Pré-requisito: endereço público com HTTPS

Os webhooks (avisos que a Meta e o Mercado Pago enviam para o sistema) exigem uma URL pública com HTTPS.
- **Produção**: hospedagem PHP 8.1+ com MySQL/MariaDB e certificado SSL (a maioria das hospedagens brasileiras oferece SSL gratuito). Configure `APP_URL=https://seudominio.com.br` no `.env`.
- **Teste no computador**: use um túnel como *Cloudflare Tunnel* (`cloudflared tunnel --url http://localhost:8000`) ou *ngrok*, e coloque o endereço gerado em `APP_URL`.

## 1. WhatsApp Business Platform (Cloud API oficial)

**Contas necessárias:** Gerenciador de Negócios da Meta (business.facebook.com), conta de desenvolvedor (developers.facebook.com) e um número de telefone que **não** esteja em uso no aplicativo WhatsApp comum (ou migre o número).

1. Em developers.facebook.com, crie um app do tipo **Empresa** e adicione o produto **WhatsApp**.
2. Em *WhatsApp → Configuração da API*: adicione e verifique o número; anote o **Phone number ID**.
3. Gere um **token permanente**: Gerenciador de Negócios → Usuários do sistema → crie um usuário "Admin" → gere token com as permissões `whatsapp_business_messaging` e `whatsapp_business_management`.
4. Em *Configurações do app → Básico*, copie a **Chave secreta do app**.
5. Preencha no `.env`:
   ```
   WHATSAPP_TOKEN=token-permanente
   WHATSAPP_PHONE_NUMBER_ID=123456789012345
   WHATSAPP_APP_SECRET=chave-secreta-do-app
   WHATSAPP_VERIFY_TOKEN=uma-frase-que-voce-inventa
   ```
6. Em *WhatsApp → Configuração → Webhook*: URL `https://seudominio.com.br/webhooks/whatsapp` (no Apache em subpasta, inclua o caminho até `public`) e o mesmo verify token. Clique em **Verificar e salvar** e assine o campo **messages**.
7. No **WhatsApp Manager → Modelos de mensagem**, crie um modelo para cada item de *Painel → Mensagens → Modelos*, com **o mesmo nome**, idioma **Português (BR)** e as variáveis `{{1}}`, `{{2}}`… na ordem da coluna "Variáveis". Categoria **Utilidade** para avisos da reserva e **Marketing** para campanhas. Aguarde a aprovação.
8. Agende o worker (abaixo).

**Custos:** a Meta cobra por mensagem de modelo enviada (valores por categoria e país, publicados na página de preços do WhatsApp Business Platform). Mensagens de marketing custam mais que as de utilidade.

**Regras importantes já respeitadas pelo sistema:**
- Campanhas só vão para quem aceitou (consentimento registrado com data e canal) e toda campanha oferece "responda SAIR".
- "SAIR", "PARAR", "STOP"… revogam o consentimento automaticamente.
- Avisos da reserva e campanhas usam modelos e categorias separados.

## 2. Mercado Pago (Checkout Pro — Pix e cartão)

**Conta necessária:** conta Mercado Pago (pessoa física ou jurídica).

1. Em mercadopago.com.br/developers → *Suas integrações* → crie uma aplicação (produto **Checkout Pro**, pagamentos online).
2. Em *Credenciais de produção*, copie o **Access Token** (`APP_USR-...`). Para testes, use as credenciais de teste e contas de teste.
3. Em *Webhooks*, configure a URL `https://seudominio.com.br/webhooks/mercadopago`, marque o evento **Pagamentos** e copie a **assinatura secreta**.
4. Preencha no `.env`:
   ```
   MERCADOPAGO_ACCESS_TOKEN=APP_USR-...
   MERCADOPAGO_WEBHOOK_SECRET=assinatura-secreta
   ```
5. Em *Configurações* do painel, deixe marcado "Oferecer pagamento online do sinal".

**Como funciona:** o botão "Pagar online" (página da reserva, após a profissional aceitar) cria o checkout; o Mercado Pago avisa o sistema pela notificação assinada; o sistema **consulta o pagamento na API** antes de registrar (não confia só no aviso), registra uma única vez e, se for o sinal, confirma a reserva.

**Custos:** o Mercado Pago cobra uma taxa por pagamento recebido, que varia com o meio de pagamento e o prazo de recebimento (veja a tabela de tarifas na conta).

## 3. Worker (lembretes e envio da fila)

O worker cria os lembretes no horário certo e envia a fila. Rode a cada 5 minutos:

**Windows (Agendador de Tarefas):**
```
schtasks /Create /SC MINUTE /MO 5 /TN "Agenda - mensagens" /TR "C:\xampp\php\php.exe \"C:\xampp\htdocs\Agenda profissional\bin\worker.php\""
```
**Linux (cron):**
```
*/5 * * * * php /caminho/do/projeto/bin/worker.php >> /caminho/do/projeto/storage/logs/worker.log 2>&1
```
Também é possível processar manualmente em *Painel → Mensagens → Processar fila agora*.
