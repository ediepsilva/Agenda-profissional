# Agenda Profissional — maquiagem artística

Aplicação web (responsiva, instalável como PWA) de agendamento e gestão para maquiadora freelancer, preparada para crescer para uma equipe.

- **Página pública** (sem cadastro): serviços, preços iniciais, área atendida, políticas e agendamento com horários realmente livres.
- **Painel protegido** (login): visão geral, agenda dia/semana/mês (com coluna por profissional), reservas e seus status, eventos com várias profissionais, clientes, serviços, disponibilidade e bloqueios, áreas atendidas, equipe e permissões, pagamentos, despesas, comissões e relatórios.

- **Mensagens e captação**: avisos automáticos pelo WhatsApp oficial (confirmação, lembrete, orientações, agradecimento com pedido de avaliação), campanhas só para quem aceitou, avaliações publicadas na página, indicação com link pessoal, links de divulgação com origem rastreável e pagamento online do sinal (Mercado Pago).

> Status: **Fases 1, 2 e 3 concluídas** (ver [Roteiro](#roteiro)). Integrações externas funcionam em modo simulado até você configurar as contas — veja [docs/INTEGRACOES.md](docs/INTEGRACOES.md).

## Papéis

| Papel | O que pode fazer |
|---|---|
| Dona | Tudo, inclusive equipe e configurações. |
| Gerente | Agenda, reservas, eventos, clientes, serviços, disponibilidade, áreas, financeiro e relatórios. Vê a equipe, mas não a altera. |
| Maquiadora | Só a própria agenda, as próprias reservas e clientes, a própria disponibilidade e as próprias comissões. Pode marcar os próprios atendimentos como concluídos ou não comparecimento. |
| Assistente | Somente leitura da agenda e das reservas (sem valores). |

Mensagens, campanhas e captação: dona e gerente. Edição dos modelos de mensagem: só a dona.

As restrições são aplicadas **no servidor**: um filtro forjado na URL ou um POST manual é ignorado ou recusado.

## Requisitos

| Item | Versão | Observação |
|---|---|---|
| PHP | 8.1+ (testado 8.2.12) | extensões `pdo_mysql`, `mbstring`, `curl` (só para os testes ponta a ponta) |
| MariaDB/MySQL | MariaDB 10.4+ / MySQL 8 | |
| XAMPP | 8.2 | já traz PHP + MariaDB + Apache |

Não há dependências de Composer nem de Node. No VS Code, recomenda-se a extensão **PHP Intelephense** (sugerida automaticamente em `.vscode/extensions.json`).

## Instalação

```powershell
# 1. Na pasta do projeto, crie o arquivo de configuração
copy .env.example .env
#    (edite DB_* se seu MySQL tiver senha; defina ADMIN_EMAIL e, se quiser, ADMIN_PASSWORD)

# 2. Inicie o MySQL/MariaDB pelo XAMPP Control Panel

# 3. Crie o banco e as tabelas
php bin/migrate.php

# 4. Crie a usuária dona + dados de exemplo
php bin/seed.php            # ou: php bin/seed.php --minimo  (sem serviços/clientes de exemplo)
```

O `seed` mostra o e-mail de login. Se `ADMIN_PASSWORD` estiver vazio, ele **gera uma senha aleatória e a exibe uma única vez** no terminal.

Para recomeçar do zero (apaga tudo; bloqueado com `APP_ENV=production`):

```powershell
php bin/migrate.php --fresh
php bin/seed.php
```

## Executando

**Opção A — servidor embutido do PHP (mais simples):**

```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

- Página pública: http://127.0.0.1:8000/
- Painel: http://127.0.0.1:8000/admin

**Opção B — Apache do XAMPP:** com o Apache ligado, acesse
http://localhost/Agenda%20profissional/ (é redirecionado para `public/`; o painel fica em `.../public/admin`).

**Mensagens automáticas:** agende `php bin/worker.php` a cada 5 minutos (instruções em [docs/INTEGRACOES.md](docs/INTEGRACOES.md#3-worker-lembretes-e-envio-da-fila)) ou use *Mensagens → Processar fila agora*.

Para testar no celular na mesma rede Wi-Fi: `php -S 0.0.0.0:8000 -t public public/index.php` e acesse `http://IP-DO-COMPUTADOR:8000`.

## Testes

```powershell
php tests/run.php                        # tudo (unitários, integração e ponta a ponta)
php tests/run.php unit                   # só uma pasta
php tests/run.php BookingService Concurrent   # filtro por arquivo e por método
```

Os testes usam um **banco separado** (`<DB_NAME>_test`), recriado a cada execução — seus dados reais não são tocados.

Cobertura das regras críticas:

- **Disponibilidade** (unitário): janelas de trabalho, duração, deslocamento de ida/volta, intervalo entre atendimentos, antecedência mínima/máxima, grade de horários, sobreposição.
- **Conflitos** (integração): mesmo horário, sobreposição parcial, intervalo, bloqueios, deslocamento, cancelamento liberando horário, confirmação rechecando conflito, reagendamento, e **concorrência real** (4 processos disputando o mesmo horário → só 1 consegue).
- **Permissões e autenticação**: papéis, bloqueio após tentativas, senha com hash, CSRF, acesso negado no servidor mesmo com POST forjado.
- **Fluxo completo via HTTP** (ponta a ponta): criar serviço → configurar disponibilidade → cliente solicita → dona confirma → horário some e nova tentativa é recusada.
- **Equipe e distribuição** (Fase 2): atribuição equilibrada, sugestão de profissionais livres, troca com checagem de agenda, áreas exclusivas de uma profissional, regras do cadastro (última dona, próprio papel, e-mail único, senha mínima).
- **Eventos**: várias profissionais ocupadas ao mesmo tempo, recusa se qualquer uma estiver ocupada, reagendamento da equipe inteira, inclusão/remoção com troca de responsável.
- **Financeiro**: comissão sem taxa de deslocamento e proporcional à divisão, sinal/saldo, despesas no resultado, totais do relatório, escopo da profissional.
- **Mensagens (Fase 3)**: confirmação sem duplicar, lembretes/orientações no horário certo, reagendamento substitui lembretes, agradecimento após concluir, reenvio com limite, status de entrega/leitura, campanha só com consentimento (conferido de novo no envio), "SAIR" revoga, formato exato das chamadas à API da Meta e verificação de assinatura dos webhooks.
- **Captação e pagamentos**: indicação e origem registradas (sem auto-indicação), consentimento no formulário (nunca pré-marcado), avaliação única e só após concluir, nome abreviado, checkout do Mercado Pago, notificação assinada, pagamento registrado uma única vez, confirmação automática do sinal.
- **Papéis via HTTP**: maquiadora não vê dados de outra (reservas, agenda, clientes, disponibilidade), gerente não mexe em equipe/configurações, dona cadastra equipe, cria evento, registra sinal (confirmação automática), conclui e exporta o CSV.

## Estrutura

```
bootstrap.php            autoload, .env, fuso horário
routes.php               todas as rotas e a permissão exigida por cada uma
public/                  ÚNICA pasta exposta na web (index.php, CSS, JS, PWA)
src/Core/                infraestrutura: Db, Router, View, Session, Csrf, Auth, Migrator
src/Domain/              regras de negócio: AvailabilityCalculator, BookingService, ClientService,
                         TeamService, FinanceService, MessageService, CampaignService, ConsentService,
                         ReviewService, ReferralService, OnlinePaymentService, Permissions, Settings, Clock
src/Integrations/        WhatsApp Cloud API, Mercado Pago, cliente HTTP (substituível nos testes)
src/Controllers/         controladores HTTP (finos; delegam ao domínio)
views/                   templates PHP (layouts público e painel)
database/migrations/     SQL versionado (aplicado por bin/migrate.php)
database/seeds/          dados de exemplo
bin/                     migrate.php, seed.php, worker.php (mensagens), make-icons.php
tests/                   unit/, integration/, e2e/ + executor run.php
docs/DECISOES.md         decisões técnicas e regras de negócio
docs/INTEGRACOES.md      passo a passo: WhatsApp oficial, Mercado Pago, HTTPS e worker
```

## Segurança e privacidade

- Senhas com `password_hash` (bcrypt); bloqueio temporário após 5 falhas por e-mail / 20 por IP em 15 min; ID de sessão regenerado no login; expiração por inatividade (8 h).
- Cookie de sessão `HttpOnly` + `SameSite=Lax` (`Secure` com HTTPS ou `SESSION_SECURE=1`).
- Token CSRF em todos os formulários; consultas SQL sempre parametrizadas; saída HTML escapada.
- Permissão verificada **no servidor** em cada rota (o menu só esconde o que não pode ser usado).
- Cabeçalhos: CSP sem scripts inline, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`.
- Formulário público com honeypot anti-robô e limite de 8 solicitações/hora por IP.
- Link da reserva da cliente usa código aleatório de 96 bits (não sequencial).
- LGPD: coleta mínima (nome e WhatsApp obrigatórios; e-mail opcional), consentimento registrado com data, página de privacidade.
- Credenciais só no `.env` (fora do Git, não acessível pela web).
- Webhooks aceitos só com assinatura HMAC válida (Meta: `X-Hub-Signature-256`; Mercado Pago: `x-signature`); o pagamento é conferido na API do gateway antes de ser registrado.
- Consentimento para ofertas separado dos avisos do serviço, com histórico (data, canal, IP/usuária) e revogação imediata. Links pessoais (reserva, avaliação, preferências) usam códigos aleatórios.
- Exportação CSV protegida contra injeção de fórmulas.

## Produção (resumo)

`APP_ENV=production`, `APP_DEBUG=0`, HTTPS obrigatório com `SESSION_SECURE=1`, usuário de banco próprio com senha (não usar `root`), `APP_URL` com o domínio, e o *document root* do servidor apontando para `public/`.

## Roteiro

- **Fase 1 (concluída):** estrutura multi-profissional, login, serviços, disponibilidade e bloqueios, página pública com agendamento, agenda e gestão de reservas, clientes, interface responsiva, PWA básico.
- **Fase 2 (concluída):** equipe e permissões (dona, gerente, maquiadora, assistente) com escopo "somente os meus", agenda por profissional, distribuição equilibrada e sugestão automática, troca/inclusão de profissionais, eventos com várias profissionais, pagamentos (sinal/saldo), despesas, comissões, resultado por atendimento, relatórios e exportação CSV.
- **Fase 3 (concluída):** WhatsApp Business Platform (API oficial, com modo simulado), lembretes automáticos, consentimento para campanhas, campanhas segmentadas, avaliações com moderação, indicações, links de divulgação com origem, pagamento online do sinal (Mercado Pago) e Pix manual.
- **Próximos passos sugeridos:** portfólio com upload de fotos, QR code do link de agendamento, integração com Google Agenda, backup automático, hospedagem com HTTPS.
