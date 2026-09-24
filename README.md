# Agenda Profissional — maquiagem artística

Aplicação web (responsiva, instalável como PWA) de agendamento e gestão para maquiadora freelancer, preparada para crescer para uma equipe.

- **Página pública** (sem cadastro): serviços, preços iniciais, área atendida, políticas e agendamento com horários realmente livres.
- **Painel protegido** (login): visão geral, agenda dia/semana/mês (com coluna por profissional), reservas e seus status, eventos com várias profissionais, clientes, serviços, disponibilidade e bloqueios, áreas atendidas, equipe e permissões, pagamentos, despesas, comissões e relatórios.

> Status: **Fases 1 e 2 concluídas** (ver [Roteiro](#roteiro)).

## Papéis

| Papel | O que pode fazer |
|---|---|
| Dona | Tudo, inclusive equipe e configurações. |
| Gerente | Agenda, reservas, eventos, clientes, serviços, disponibilidade, áreas, financeiro e relatórios. Vê a equipe, mas não a altera. |
| Maquiadora | Só a própria agenda, as próprias reservas e clientes, a própria disponibilidade e as próprias comissões. Pode marcar os próprios atendimentos como concluídos ou não comparecimento. |
| Assistente | Somente leitura da agenda e das reservas (sem valores). |

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
- **Papéis via HTTP**: maquiadora não vê dados de outra (reservas, agenda, clientes, disponibilidade), gerente não mexe em equipe/configurações, dona cadastra equipe, cria evento, registra sinal (confirmação automática), conclui e exporta o CSV.

## Estrutura

```
bootstrap.php            autoload, .env, fuso horário
routes.php               todas as rotas e a permissão exigida por cada uma
public/                  ÚNICA pasta exposta na web (index.php, CSS, JS, PWA)
src/Core/                infraestrutura: Db, Router, View, Session, Csrf, Auth, Migrator
src/Domain/              regras de negócio: AvailabilityCalculator, BookingService,
                         ClientService, TeamService, FinanceService, Permissions, Settings, Clock
src/Controllers/         controladores HTTP (finos; delegam ao domínio)
views/                   templates PHP (layouts público e painel)
database/migrations/     SQL versionado (aplicado por bin/migrate.php)
database/seeds/          dados de exemplo
bin/                     migrate.php, seed.php, make-icons.php
tests/                   unit/, integration/, e2e/ + executor run.php
docs/DECISOES.md         decisões técnicas e regras de negócio
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

## Produção (resumo)

`APP_ENV=production`, `APP_DEBUG=0`, HTTPS obrigatório com `SESSION_SECURE=1`, usuário de banco próprio com senha (não usar `root`), `APP_URL` com o domínio, e o *document root* do servidor apontando para `public/`.

## Roteiro

- **Fase 1 (concluída):** estrutura multi-profissional, login, serviços, disponibilidade e bloqueios, página pública com agendamento, agenda e gestão de reservas, clientes, interface responsiva, PWA básico.
- **Fase 2 (concluída):** equipe e permissões (dona, gerente, maquiadora, assistente) com escopo "somente os meus", agenda por profissional, distribuição equilibrada e sugestão automática, troca/inclusão de profissionais, eventos com várias profissionais, pagamentos (sinal/saldo), despesas, comissões, resultado por atendimento, relatórios e exportação CSV.
- **Fase 3:** WhatsApp Business Platform (API oficial), lembretes automáticos, consentimento para campanhas, avaliações, indicações, pagamentos, portfólio com fotos.
