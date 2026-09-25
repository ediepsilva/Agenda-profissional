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

## Fase 2 — equipe e permissões

- **Membro da equipe** = agenda (`professionals`) e/ou login (`users`), ligados por `professionals.user_id`. Assim existe maquiadora sem login (parceira eventual) e gerente sem agenda.
- **Escopo "próprio"**: permissões terminadas em `.own` (maquiadora). O controlador calcula `Auth::professionalScope()` e restringe toda consulta às reservas em que ela está alocada; filtros vindos da URL são ignorados. Rotas aceitam listas de permissões (`[agenda.view, agenda.view.own]`).
- **Salvaguardas do cadastro**: sempre sobra uma dona ativa; ninguém muda o próprio papel ou desativa o próprio acesso; profissional desativada não é apagada (preserva histórico) e o sistema avisa se ela tinha atendimentos futuros.

## Distribuição de atendimentos

- Sem profissional escolhida (página pública ou "distribuir automaticamente" no painel), o sistema tenta as profissionais que fazem o serviço **em ordem de menor carga** (atendimentos no dia, depois na semana) e fica com a primeira livre — dentro da mesma transação com trava, então continua sem conflitos.
- **Área exclusiva**: uma área atendida pode pertencer a uma profissional; só ela recebe atendimentos naquela região.
- **Sugestão**: na página da reserva, a equipe aparece ordenada por (livre, faz o serviço, menor carga). Dali é possível **trocar a responsável** ou **incluir** alguém; ambas as ações conferem a agenda da nova profissional sob trava.

## Eventos

- `bookings.kind = event` com nome, número de pessoas, duração e valor próprios. Cada profissional recebe uma alocação no mesmo período (com deslocamento e intervalo). Se qualquer uma estiver ocupada, o evento é recusado e a mensagem diz quem.
- Reagendar, confirmar ou incluir/remover profissional sempre revalida **todas** as alocações. Remover a responsável promove a próxima.

## Financeiro

- **Base da comissão** = valor − taxa de deslocamento (a taxa cobre o transporte).
- **Divisão** (`share_percent`): parte do valor atribuída a cada profissional; começa igual entre elas e pode ser ajustada (precisa somar 100%).
- **Comissão** (`commission_percent`): copiada do cadastro da profissional no momento da alocação, preservando o histórico se a comissão mudar depois. Dona normalmente usa 0%.
- **Resultado do atendimento** = valor − comissões − despesas ligadas a ele.
- **Relatórios**: faturamento = atendimentos concluídos com data no período (competência); recebido = pagamentos com data no período (caixa); resultado = faturamento − comissões − despesas do período. Cliente recorrente = já tinha atendimento concluído antes do período.
- Registrar o sinal de uma reserva "aguardando sinal" pode confirmá-la automaticamente (com checagem de conflito).
- Exportação CSV no padrão do Excel brasileiro (`;` e vírgula decimal), com proteção contra injeção de fórmulas.

## Fase 3 — mensagens, captação e pagamentos

- **Fila de saída (outbox)**: toda mensagem é gravada em `messages` na mesma transação da mudança que a originou (ex.: confirmação). O envio acontece depois, pelo worker. Se a API cair, nada se perde; tentativas com espera crescente (até 3) para erros temporários; erros definitivos (modelo inexistente, número inválido) falham na hora.
- **Sem duplicidade**: `dedupe_key` única por aviso (ex.: lembrete da reserva 10 para o horário X). Reagendar cancela os lembretes pendentes; os novos têm outra chave.
- **Checagem no envio**: antes de enviar, o sistema confere de novo o status da reserva e o consentimento da cliente — uma mudança entre o enfileiramento e o envio impede a mensagem ("Não enviada").
- **Transacional × marketing**: modelos e categorias separados. Campanha só é enfileirada para quem tem `marketing_opt_in = 1` e é reconferida no envio. O consentimento nunca vem pré-marcado e desmarcar a opção num novo agendamento não revoga (só ações explícitas revogam: página de preferências, "SAIR", painel).
- **Provedor plugável**: `Integrations` escolhe o provedor pelo `.env` (Cloud API real ou simulado). Os testes injetam um cliente HTTP falso e verificam o formato exato das chamadas.
- **Pagamento online**: disponível só depois que a profissional aceita a reserva (o pagamento nunca pula a aprovação). A notificação do gateway precisa de assinatura válida; o valor e o status vêm da consulta à API; `booking_payments.external_id` único garante registro único mesmo com notificações repetidas.
- **Avaliações**: pelo link secreto da própria reserva, uma por atendimento concluído; publicadas com primeiro nome + inicial; moderação opcional.
- **Indicação**: código curto por cliente (sem caracteres ambíguos); a nova cliente fica ligada a quem indicou; auto-indicação é ignorada. Origem dos links (`?origem=`) é guardada na reserva para medir cada canal.

## Limitações conhecidas

- Regra de reagendamento pela cliente (`reschedule_min_hours`) é configurável, mas o reagendamento ainda é feito pela profissional no painel.
- Portfólio: por enquanto, link para o Instagram; o upload de fotos virá depois.
- Todas as profissionais de um evento ocupam o mesmo período (não há horários individuais por pessoa dentro do evento).
- Pagamento de comissões às profissionais é controlado fora do sistema (o relatório mostra quanto é devido).
- Envio real de WhatsApp e pagamento online dependem das contas externas (ver `docs/INTEGRACOES.md`); sem elas, o sistema opera em modo simulado / Pix manual.
- A recompensa por indicação é informativa: o desconto é aplicado manualmente (ajustando o valor da reserva).
- O worker precisa ser agendado no sistema operacional (Agendador de Tarefas/cron).
