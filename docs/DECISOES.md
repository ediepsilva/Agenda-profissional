# Decisões técnicas e regras de negócio

## Arquitetura

**PHP 8.2 sem framework + MariaDB (PDO), páginas renderizadas no servidor, JavaScript simples só onde agrega (calendário de horários).**

Motivos:
- O ambiente já tem XAMPP (PHP + MariaDB + Apache). Roda sem instalar nada e hospeda em qualquer plano PHP barato.
- Não havia Composer nem Node; evitar dependências reduz manutenção e superfície de ataque.
- As regras críticas ficam isoladas em `src/Domain`, testáveis sem HTTP. Uma migração futura para um framework (ex.: Laravel) reaproveita essa lógica.

Trade-offs aceitos: roteador, sessão, CSRF e executor de testes próprios (pequenos e testados) em vez de bibliotecas prontas.

## Modelo pensado para equipe desde o início

- `users` (login + papel) é separado de `professionals` (quem atende). Uma profissional pode existir sem login, e uma usuária pode não atender (gerente).
- Disponibilidade (`availability_rules`), bloqueios (`schedule_blocks`), serviços oferecidos (`professional_services`) e áreas (`service_areas.professional_id`) são **por profissional**.
- O tempo ocupado de cada reserva fica em `booking_allocations` (uma linha por profissional). Hoje cada reserva tem 1 alocação; na Fase 2, um evento (casamento) terá várias, e a checagem de conflito já funciona por alocação.
- Papéis e permissões em `Permissions.php`: dona (tudo), gerente (sem configurações), maquiadora (a própria agenda — `.own`, ativado na Fase 2), assistente (somente leitura).

## Regra de disponibilidade

Um atendimento que começa em **S** e dura **D** ocupa a agenda em:

```
[ S − deslocamento ,  S + D + deslocamento + intervalo ]
```

- **Deslocamento** vem da área escolhida (ida e volta; zero no estúdio) e precisa caber no expediente.
- **Intervalo entre atendimentos** (configurável) só precisa estar livre de outros compromissos; pode passar do fim do expediente.
- Horários oferecidos seguem uma grade (padrão 30 min: 09:00, 09:30…).
- Respeita antecedência mínima (padrão 24 h) e prazo máximo (padrão 90 dias) nas reservas públicas. No painel, a dona pode lançar sem essas restrições e, marcando uma opção, até fora do expediente — **mas nunca em conflito**.

## Prevenção de conflito (inclusive simultâneo)

Toda operação que ocupa agenda (criar, confirmar, reagendar) roda numa transação que:
1. trava a linha da profissional (`SELECT … FOR UPDATE`);
2. recalcula a disponibilidade já dentro da trava;
3. grava a reserva e a alocação.

O banco usa `READ COMMITTED`, para que, depois da trava, a transação enxergue o que a anterior acabou de gravar. Um teste de concorrência com 4 processos comprova isso: sem a trava, os 4 conseguiam reservar; com ela, apenas 1.

## Status da reserva

```
solicitada ──► aguardando sinal ──► confirmada ──► concluída
     │                 │                 ├──► não compareceu
     └──► cancelada ◄──┴─────────────────┘
```

- Configuração **“solicitações seguram o horário”** (padrão: ligada): um pedido pendente já bloqueia o horário, evitando dois pedidos iguais. Desligada, vários pedidos podem disputar o horário; ao confirmar um, os outros ficam impedidos de ser confirmados.
- Ao passar para *aguardando sinal* ou *confirmada*, o conflito é rechecado (cobre bloqueios criados depois do pedido).
- Todo passo fica em `booking_status_history`, com quem mudou e observação.
- A cliente pode cancelar pela página da reserva: sempre, enquanto *solicitada*; depois de aprovada, só até N horas antes (padrão 48 h).

## Dinheiro, datas e textos

- Valores em **centavos** (`INT`), exibidos como `R$ 1.234,56`.
- Datas/horas em horário local `America/Sao_Paulo` (`DATETIME`), exibidas como `dd/mm/aaaa`.
- Interface inteira em pt-BR.

## Mensagens (Fases 1 e 3)

Na Fase 1 há apenas links oficiais “clique para conversar” (`wa.me`), acionados manualmente. Os disparos automáticos (confirmação, lembrete, etc.) serão feitos na Fase 3 pela **WhatsApp Business Platform (Cloud API) oficial**, separando mensagens transacionais de campanhas e respeitando o consentimento da cliente. Nenhuma automação não oficial será usada.

## Limitações conhecidas da Fase 1

- Regra de reagendamento pela cliente (`reschedule_min_hours`) é configurável, mas o reagendamento ainda é feito pela profissional no painel.
- Portfólio: por enquanto, link para o Instagram; o upload de fotos virá depois.
- Sem envio automático de mensagens/e-mails (Fase 3).
- Cadastro de novas profissionais/usuárias pela interface: Fase 2 (a estrutura já existe).
