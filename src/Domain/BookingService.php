<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use DateTimeImmutable;
use DomainException;

/**
 * Regras de reserva: horários disponíveis, criação sem conflito, eventos com várias
 * profissionais, distribuição de atendimentos, mudança de status e reagendamento.
 *
 * Proteção contra conflito: toda escrita que ocupa agenda roda em transação e
 * trava a(s) linha(s) da(s) profissional(is) (SELECT ... FOR UPDATE, sempre em
 * ordem crescente de id para evitar deadlock) antes de conferir a disponibilidade.
 * Assim duas solicitações simultâneas para o mesmo horário são serializadas e a
 * segunda é recusada.
 */
final class BookingService
{
    public const STATUS_LABELS = [
        'requested' => 'Solicitada',
        'awaiting_deposit' => 'Aguardando sinal',
        'confirmed' => 'Confirmada',
        'completed' => 'Concluída',
        'cancelled' => 'Cancelada',
        'no_show' => 'Não compareceu',
    ];

    public const TRANSITIONS = [
        'requested' => ['awaiting_deposit', 'confirmed', 'cancelled'],
        'awaiting_deposit' => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'no_show', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    /** Transições que a própria maquiadora pode fazer nas reservas dela. */
    public const OWN_TRANSITIONS = ['completed', 'no_show'];

    /** Status que exigem agenda livre ao serem atingidos. */
    private const COMMITTED = ['awaiting_deposit', 'confirmed'];

    /** Status em que ainda é possível reagendar/cancelar/alterar a equipe. */
    public const OPEN = ['requested', 'awaiting_deposit', 'confirmed'];

    public const SOURCES = [
        'instagram' => 'Instagram',
        'indicacao' => 'Indicação de amiga/cliente',
        'google' => 'Google',
        'tiktok' => 'TikTok',
        'whatsapp' => 'WhatsApp',
        'evento' => 'Em um evento',
        'outro' => 'Outro',
    ];

    private const TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function label(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /** Status que ocupam a agenda. Com "segurar solicitações" ligado, pedidos pendentes também seguram o horário. */
    public static function blockingStatuses(): array
    {
        return Settings::int('hold_pending_requests', 1) === 1
            ? ['requested', 'awaiting_deposit', 'confirmed', 'completed']
            : ['awaiting_deposit', 'confirmed', 'completed'];
    }

    // ------------------------------------------------------------------ consultas

    /** Profissionais ativas que realizam o serviço. */
    public function professionalsForService(int $serviceId, bool $onlineOnly): array
    {
        return Db::all(
            'SELECT p.* FROM professionals p
             JOIN professional_services ps ON ps.professional_id = p.id AND ps.service_id = ?
             WHERE p.active = 1' . ($onlineOnly ? ' AND p.accepts_online_booking = 1' : '') . '
             ORDER BY p.id',
            [$serviceId]
        );
    }

    /** @return array<int,array{0:string,1:string}> */
    public function windowsFor(int $professionalId, int $weekday): array
    {
        $rows = Db::all(
            'SELECT start_time, end_time FROM availability_rules WHERE professional_id = ? AND weekday = ? ORDER BY start_time',
            [$professionalId, $weekday]
        );
        return array_map(static fn ($r) => [substr($r['start_time'], 0, 5), substr($r['end_time'], 0, 5)], $rows);
    }

    /** @return array<int,array{0:DateTimeImmutable,1:DateTimeImmutable}> */
    public function busyIntervals(int $professionalId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $excludeBookingId = null): array
    {
        $statuses = self::blockingStatuses();
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $f = $from->format('Y-m-d H:i:s');
        $t = $to->format('Y-m-d H:i:s');

        $rows = Db::all(
            "SELECT a.block_start AS s, a.block_end AS e FROM booking_allocations a
             JOIN bookings b ON b.id = a.booking_id
             WHERE a.professional_id = ? AND b.status IN ($in) AND a.block_start < ? AND a.block_end > ? AND b.id <> ?",
            [$professionalId, ...$statuses, $t, $f, $excludeBookingId ?? 0]
        );
        $rows = array_merge($rows, Db::all(
            'SELECT starts_at AS s, ends_at AS e FROM schedule_blocks WHERE professional_id = ? AND starts_at < ? AND ends_at > ?',
            [$professionalId, $t, $f]
        ));

        return array_map(static fn ($r) => [new DateTimeImmutable($r['s']), new DateTimeImmutable($r['e'])], $rows);
    }

    /**
     * Horários livres de uma profissional num dia.
     * $enforceBookingWindow: aplica antecedência mínima e prazo máximo (reservas públicas).
     * @return DateTimeImmutable[]
     */
    public function slotsFor(int $duration, int $professionalId, DateTimeImmutable $day, int $travel, ?int $buffer = null, ?int $excludeBookingId = null, bool $enforceBookingWindow = true): array
    {
        $day = $day->setTime(0, 0);
        $earliest = null;
        $latest = null;
        if ($enforceBookingWindow) {
            $now = Clock::now();
            $earliest = $now->modify('+' . Settings::int('min_advance_hours', 24) . ' hours');
            $latest = $now->setTime(23, 59)->modify('+' . Settings::int('max_advance_days', 90) . ' days');
            if ($day > $latest) {
                return [];
            }
        }

        return AvailabilityCalculator::slots(
            $day,
            $this->windowsFor($professionalId, (int) $day->format('w')),
            $this->busyIntervals($professionalId, $day->modify('-1 day'), $day->modify('+2 days'), $excludeBookingId),
            $duration,
            $travel,
            $buffer ?? Settings::int('buffer_minutes', 0),
            Settings::int('slot_step_minutes', 30),
            $earliest,
            $latest,
        );
    }

    /**
     * Horários disponíveis para a página pública (união entre profissionais).
     * @return array<string,int[]> "HH:MM" => ids das profissionais livres
     */
    public function availableSlots(int $serviceId, string $date, string $locationType, ?int $areaId): array
    {
        $service = $this->activeService($serviceId);
        $day = $this->parseDate($date);
        if (!$service || !$day) {
            return [];
        }
        try {
            $loc = $this->resolveLocation($service['location_mode'], $locationType, $areaId);
        } catch (ValidationException) {
            return [];
        }

        $result = [];
        foreach ($this->professionalsForService($serviceId, true) as $pro) {
            if (!$this->areaServedBy($loc['area'], (int) $pro['id'])) {
                continue;
            }
            foreach ($this->slotsFor((int) $service['duration_minutes'], (int) $pro['id'], $day, $loc['travel']) as $slot) {
                $result[$slot->format('H:i')][] = (int) $pro['id'];
            }
        }
        ksort($result);
        return $result;
    }

    /**
     * Horários livres para lançamento pelo painel (sem regra de antecedência).
     * @return string[] "HH:MM"
     */
    public function adminSlots(int $serviceId, int $professionalId, string $date, string $locationType, ?int $areaId, ?int $duration = null): array
    {
        $service = $this->activeService($serviceId);
        $day = $this->parseDate($date);
        if (!$service || !$day) {
            return [];
        }
        try {
            $loc = $this->resolveLocation($service['location_mode'], $locationType, $areaId);
        } catch (ValidationException) {
            return [];
        }
        $pros = $professionalId > 0
            ? [$professionalId]
            : array_map(static fn ($p) => (int) $p['id'], $this->professionalsForService($serviceId, false));
        $all = [];
        foreach ($pros as $pid) {
            foreach ($this->slotsFor($duration ?? (int) $service['duration_minutes'], $pid, $day, $loc['travel'], null, null, false) as $s) {
                $all[$s->format('H:i')] = true;
            }
        }
        ksort($all);
        return array_keys($all);
    }

    /** @return string[] datas (Y-m-d) do mês com pelo menos um horário livre */
    public function availableDays(int $serviceId, string $month, string $locationType, ?int $areaId): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [];
        }
        $first = new DateTimeImmutable($month . '-01');
        $days = [];
        $today = Clock::now()->setTime(0, 0);
        for ($d = $first; $d->format('Y-m') === $month; $d = $d->modify('+1 day')) {
            if ($d < $today) {
                continue;
            }
            if ($this->availableSlots($serviceId, $d->format('Y-m-d'), $locationType, $areaId)) {
                $days[] = $d->format('Y-m-d');
            }
        }
        return $days;
    }

    /**
     * Sugestão de profissionais para um horário: livres primeiro, depois a de menor carga no dia/semana.
     * @return array<int,array{id:int,name:string,color:string,free:bool,day_load:int,week_load:int,offers_service:bool}>
     */
    public function suggestProfessionals(int $serviceId, string $date, string $time, ?int $duration = null, string $locationType = 'studio', ?int $areaId = null, ?int $excludeBookingId = null, bool $allowOutsideHours = false): array
    {
        $day = $this->parseDate($date);
        $service = Db::one('SELECT * FROM services WHERE id = ?', [$serviceId]);
        if (!$day || !$service || !preg_match(self::TIME_RE, $time)) {
            return [];
        }
        $travel = 0;
        if ($locationType === 'client' && $areaId) {
            $travel = (int) (Db::value('SELECT travel_minutes FROM service_areas WHERE id = ?', [$areaId]) ?? 0);
        }
        $offering = array_map(static fn ($p) => (int) $p['id'], $this->professionalsForService($serviceId, false));
        $start = $day->modify($time);
        $duration ??= (int) $service['duration_minutes'];
        $buffer = Settings::int('buffer_minutes', 0);

        $list = [];
        foreach (Db::all('SELECT id, name, color FROM professionals WHERE active = 1 ORDER BY id') as $p) {
            $pid = (int) $p['id'];
            [$dayLoad, $weekLoad] = $this->load($pid, $day);
            $list[] = [
                'id' => $pid,
                'name' => $p['name'],
                'color' => $p['color'],
                'free' => $this->isFree($duration, $pid, $day, $start, $travel, $buffer, $excludeBookingId, false, $allowOutsideHours),
                'day_load' => $dayLoad,
                'week_load' => $weekLoad,
                'offers_service' => in_array($pid, $offering, true),
            ];
        }
        usort($list, static fn ($a, $b) => [!$a['free'], !$a['offers_service'], $a['day_load'], $a['week_load'], $a['id']]
            <=> [!$b['free'], !$b['offers_service'], $b['day_load'], $b['week_load'], $b['id']]);
        return $list;
    }

    // ------------------------------------------------------------------ escrita

    /**
     * Cria uma reserva de serviço.
     * $channel: 'public' (cliente pela página) ou 'admin' (lançada no painel).
     * $allowOutsideHours (só admin): ignora janelas de trabalho, mas nunca conflitos.
     * Sem profissional definida, escolhe a livre com menor carga no dia (distribuição equilibrada).
     */
    public function create(array $in, string $channel = 'public', ?int $userId = null, bool $allowOutsideHours = false): array
    {
        $errors = [];
        $isPublic = $channel === 'public';

        $service = $this->activeService((int) ($in['service_id'] ?? 0));
        if (!$service) {
            $errors['service_id'] = 'Escolha um serviço.';
        }
        [$day, $time, $dtErrors] = $this->parseDateTime($in);
        $errors += $dtErrors;

        [$locationType, $areaId, $address] = $this->locationInput($in);
        $loc = ['travel' => 0, 'fee' => 0, 'area' => null];
        if ($service) {
            try {
                $loc = $this->resolveLocation($service['location_mode'], $locationType, $areaId);
            } catch (ValidationException $e) {
                $errors += $e->errors;
            }
        }
        if ($locationType === 'client' && mb_strlen($address) < 5) {
            $errors['address'] = 'Informe o endereço do atendimento.';
        }

        [$clientData, $clientErrors] = $this->clientInput($in, $isPublic);
        $errors += $clientErrors;

        $notes = trim((string) ($in['client_notes'] ?? ''));
        if (mb_strlen($notes) > 1000) {
            $errors['client_notes'] = 'Use no máximo 1000 caracteres.';
        }

        $status = $isPublic ? 'requested' : $this->initialStatus($in);

        if ($errors) {
            throw new ValidationException($errors);
        }

        $duration = (int) $service['duration_minutes'];
        $start = $day->modify($time);
        $buffer = Settings::int('buffer_minutes', 0);

        $candidates = $this->professionalsForService((int) $service['id'], $isPublic);
        if (!$isPublic && !empty($in['professional_id'])) {
            $candidates = array_values(array_filter($candidates, static fn ($p) => (int) $p['id'] === (int) $in['professional_id']));
        }
        $candidates = array_values(array_filter($candidates, fn ($p) => $this->areaServedBy($loc['area'], (int) $p['id'])));
        if (!$candidates) {
            throw new ValidationException(['service_id' => 'Nenhuma profissional disponível para este serviço.']);
        }
        $candidates = $this->rankByLoad($candidates, $day);

        return Db::transaction(function () use ($in, $isPublic, $service, $day, $start, $duration, $loc, $address, $locationType, $candidates, $buffer, $status, $channel, $userId, $allowOutsideHours, $notes, $clientData) {
            $chosen = null;
            foreach ($candidates as $pro) {
                $proId = (int) $pro['id'];
                $this->lockProfessionals([$proId]);
                if ($this->isFree($duration, $proId, $day, $start, $loc['travel'], $buffer, null, $isPublic, !$isPublic && $allowOutsideHours)) {
                    $chosen = $pro;
                    break;
                }
            }
            if ($chosen === null) {
                throw new ValidationException(['time' => 'Este horário não está mais disponível. Por favor, escolha outro.']);
            }

            $clientId = $clientData ? (int) $clientData['id'] : ClientService::findOrCreate($in, $isPublic);
            [$referrerId, $origin] = $this->acquisition($in, $clientId, $isPublic);
            $price = (int) $service['price_cents'] + $loc['fee'];
            $deposit = $service['deposit_cents'] !== null
                ? (int) $service['deposit_cents']
                : (int) round($price * Settings::int('deposit_percent', 0) / 100);

            $bookingId = $this->insertBooking([
                'kind' => 'service',
                'client_id' => $clientId,
                'service_id' => (int) $service['id'],
                'professional_id' => (int) $chosen['id'],
                'status' => $status,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $start->modify("+{$duration} minutes")->format('Y-m-d H:i:s'),
                'location_type' => $locationType,
                'service_area_id' => $loc['area']['id'] ?? null,
                'address' => $locationType === 'client' ? mb_substr($address, 0, 255) : null,
                'travel_minutes' => $loc['travel'],
                'buffer_minutes' => $buffer,
                'price_cents' => $price,
                'travel_fee_cents' => $loc['fee'],
                'deposit_cents' => $deposit,
                'client_notes' => $notes !== '' ? $notes : null,
                'channel' => $channel,
                'referred_by_client_id' => $referrerId,
                'origin' => $origin,
                'created_by' => $userId,
            ]);
            $this->insertAllocations($bookingId, [(int) $chosen['id']], $start, $duration, $loc['travel'], $buffer);
            $this->history($bookingId, null, $status, $userId, $isPublic ? 'Solicitação pela página pública' : 'Lançada no painel');
            if ($status === 'confirmed') {
                (new MessageService())->onStatusChanged($bookingId, 'requested', 'confirmed');
            }

            return $this->find($bookingId);
        });
    }

    /**
     * Evento: produção com várias pessoas e uma ou mais profissionais, com duração e valor próprios.
     * A primeira profissional da lista é a responsável; a agenda de todas é ocupada no mesmo período.
     */
    public function createEvent(array $in, ?int $userId, bool $allowOutsideHours = false): array
    {
        $errors = [];
        $service = Db::one('SELECT * FROM services WHERE id = ?', [(int) ($in['service_id'] ?? 0)]);
        if (!$service) {
            $errors['service_id'] = 'Escolha o serviço principal do evento.';
        }
        $name = trim((string) ($in['event_name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) {
            $errors['event_name'] = 'Informe o nome do evento (ex.: Casamento Ana & Pedro).';
        }
        [$day, $time, $dtErrors] = $this->parseDateTime($in);
        $errors += $dtErrors;

        $duration = (int) ($in['duration_minutes'] ?? 0);
        if ($duration < 30 || $duration > 1440) {
            $errors['duration_minutes'] = 'Duração entre 30 minutos e 24 horas.';
        }
        $people = (int) ($in['people_count'] ?? 1);
        if ($people < 1 || $people > 200) {
            $errors['people_count'] = 'Número de pessoas entre 1 e 200.';
        }

        [$locationType, $areaId, $address] = $this->locationInput($in);
        $loc = ['travel' => 0, 'fee' => 0, 'area' => null];
        try {
            $loc = $this->resolveLocation('both', $locationType, $areaId);
        } catch (ValidationException $e) {
            $errors += $e->errors;
        }
        if ($locationType === 'client' && mb_strlen($address) < 5) {
            $errors['address'] = 'Informe o endereço do evento.';
        }

        $proIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['professional_ids'] ?? [])))));
        $active = array_map('intval', array_column(Db::all('SELECT id FROM professionals WHERE active = 1'), 'id'));
        if (!$proIds || array_diff($proIds, $active)) {
            $errors['professional_ids'] = 'Selecione ao menos uma profissional ativa.';
        }

        $price = parse_money((string) ($in['price'] ?? ''));
        if ($price === null) {
            $errors['price'] = 'Informe o valor total do evento.';
        }
        $depositRaw = trim((string) ($in['deposit'] ?? ''));
        $deposit = $depositRaw === '' ? null : parse_money($depositRaw);
        if ($depositRaw !== '' && $deposit === null) {
            $errors['deposit'] = 'Valor de sinal inválido.';
        }

        [$clientData, $clientErrors] = $this->clientInput($in, false);
        $errors += $clientErrors;
        $notes = trim((string) ($in['client_notes'] ?? ''));
        $status = $this->initialStatus($in);

        if ($errors) {
            throw new ValidationException($errors);
        }

        $start = $day->modify($time);
        $buffer = Settings::int('buffer_minutes', 0);
        $total = $price + $loc['fee'];
        $deposit ??= (int) round($total * Settings::int('deposit_percent', 0) / 100);

        return Db::transaction(function () use ($in, $service, $name, $people, $day, $start, $duration, $loc, $locationType, $address, $proIds, $buffer, $total, $deposit, $clientData, $notes, $status, $userId, $allowOutsideHours) {
            $this->lockProfessionals($proIds);
            $busy = [];
            foreach ($proIds as $pid) {
                if (!$this->isFree($duration, $pid, $day, $start, $loc['travel'], $buffer, null, false, $allowOutsideHours)) {
                    $busy[] = Db::value('SELECT name FROM professionals WHERE id = ?', [$pid]);
                }
            }
            if ($busy) {
                throw new ValidationException(['professional_ids' => 'Sem disponibilidade neste horário: ' . implode(', ', $busy) . '.']);
            }

            $clientId = $clientData ? (int) $clientData['id'] : ClientService::findOrCreate($in, false);
            $bookingId = $this->insertBooking([
                'kind' => 'event',
                'event_name' => $name,
                'people_count' => $people,
                'client_id' => $clientId,
                'service_id' => (int) $service['id'],
                'professional_id' => $proIds[0],
                'status' => $status,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $start->modify("+{$duration} minutes")->format('Y-m-d H:i:s'),
                'location_type' => $locationType,
                'service_area_id' => $loc['area']['id'] ?? null,
                'address' => $locationType === 'client' ? mb_substr($address, 0, 255) : null,
                'travel_minutes' => $loc['travel'],
                'buffer_minutes' => $buffer,
                'price_cents' => $total,
                'travel_fee_cents' => $loc['fee'],
                'deposit_cents' => $deposit,
                'client_notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null,
                'channel' => 'admin',
                'created_by' => $userId,
            ]);
            $this->insertAllocations($bookingId, $proIds, $start, $duration, $loc['travel'], $buffer);
            $this->history($bookingId, null, $status, $userId, 'Evento criado com ' . count($proIds) . ' profissional(is)');
            if ($status === 'confirmed') {
                (new MessageService())->onStatusChanged($bookingId, 'requested', 'confirmed');
            }
            return $this->find($bookingId);
        });
    }

    public function changeStatus(int $bookingId, string $to, ?int $userId, ?string $note = null): void
    {
        Db::transaction(function () use ($bookingId, $to, $userId, $note) {
            $b = $this->lockBooking($bookingId);
            $from = $b['status'];
            if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException(sprintf('Não é possível passar de "%s" para "%s".', self::label($from), self::label($to)));
            }

            if (in_array($to, self::COMMITTED, true)) {
                $allocs = Db::all('SELECT * FROM booking_allocations WHERE booking_id = ? ORDER BY professional_id', [$bookingId]);
                $this->lockProfessionals(array_map(static fn ($a) => (int) $a['professional_id'], $allocs));
                foreach ($allocs as $a) {
                    $busy = $this->busyIntervals(
                        (int) $a['professional_id'],
                        new DateTimeImmutable($a['block_start']),
                        new DateTimeImmutable($a['block_end']),
                        $bookingId
                    );
                    if ($busy) {
                        throw new DomainException('Conflito de agenda: já existe outro atendimento ou bloqueio neste horário. Reagende ou cancele esta reserva.');
                    }
                }
            }

            $data = ['status' => $to];
            $now = Clock::now()->format('Y-m-d H:i:s');
            if ($to === 'confirmed') {
                $data['confirmed_at'] = $now;
            }
            if ($to === 'cancelled') {
                $data['cancelled_at'] = $now;
                $data['cancel_reason'] = $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null;
            }
            Db::update('bookings', $data, 'id = ?', [$bookingId]);
            $this->history($bookingId, $from, $to, $userId, $note);
            // Outbox: a mensagem é gravada na mesma transação da mudança de status.
            (new MessageService())->onStatusChanged($bookingId, $from, $to);
        });
    }

    /** Move a reserva (todas as profissionais alocadas) para outra data/horário. */
    public function reschedule(int $bookingId, string $date, string $time, ?int $userId, bool $allowOutsideHours = false): void
    {
        $day = $this->parseDate($date);
        if (!$day || !preg_match(self::TIME_RE, $time)) {
            throw new ValidationException(['time' => 'Informe data e horário válidos.']);
        }

        Db::transaction(function () use ($bookingId, $day, $time, $userId, $allowOutsideHours) {
            $b = $this->lockBooking($bookingId);
            if (!in_array($b['status'], self::OPEN, true)) {
                throw new DomainException('Só é possível reagendar reservas em aberto.');
            }
            $duration = $this->duration($b);
            $travel = (int) $b['travel_minutes'];
            $buffer = (int) $b['buffer_minutes'];
            $start = $day->modify($time);
            $proIds = $this->allocatedIds($bookingId);
            $this->lockProfessionals($proIds);

            foreach ($proIds as $pid) {
                if (!$this->isFree($duration, $pid, $day, $start, $travel, $buffer, $bookingId, false, $allowOutsideHours)) {
                    throw new ValidationException(['time' => 'O novo horário não está disponível' . (count($proIds) > 1 ? ' para ' . Db::value('SELECT name FROM professionals WHERE id = ?', [$pid]) : '') . '.']);
                }
            }

            [$b0, $b1] = AvailabilityCalculator::occupation($start, $duration, $travel, $buffer);
            Db::update('bookings', [
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $start->modify("+{$duration} minutes")->format('Y-m-d H:i:s'),
            ], 'id = ?', [$bookingId]);
            Db::update('booking_allocations', [
                'block_start' => $b0->format('Y-m-d H:i:s'),
                'block_end' => $b1->format('Y-m-d H:i:s'),
            ], 'booking_id = ?', [$bookingId]);
            (new MessageService())->onRescheduled($bookingId);
            $this->history($bookingId, $b['status'], $b['status'], $userId, 'Reagendada de ' . datetime_br($b['starts_at']) . ' para ' . datetime_br($start));
        });
    }

    /** Troca uma profissional por outra (atribuição manual), conferindo a agenda da nova. */
    public function reassign(int $bookingId, int $fromProId, int $toProId, ?int $userId, bool $allowOutsideHours = false): void
    {
        Db::transaction(function () use ($bookingId, $fromProId, $toProId, $userId, $allowOutsideHours) {
            $b = $this->lockBooking($bookingId);
            if (!in_array($b['status'], self::OPEN, true)) {
                throw new DomainException('Só é possível trocar a profissional de reservas em aberto.');
            }
            $alloc = Db::one('SELECT * FROM booking_allocations WHERE booking_id = ? AND professional_id = ?', [$bookingId, $fromProId]);
            $to = Db::one('SELECT * FROM professionals WHERE id = ? AND active = 1', [$toProId]);
            if (!$alloc || !$to) {
                throw new DomainException('Profissional inválida.');
            }
            if (in_array($toProId, $this->allocatedIds($bookingId), true)) {
                throw new DomainException($to['name'] . ' já está neste atendimento.');
            }
            $this->lockProfessionals([$toProId]);
            $this->assertFreeForBooking($b, $toProId, $allowOutsideHours);

            Db::update('booking_allocations', [
                'professional_id' => $toProId,
                'commission_percent' => $to['commission_percent'],
            ], 'id = ?', [$alloc['id']]);
            if ($alloc['role'] === 'lead') {
                Db::update('bookings', ['professional_id' => $toProId], 'id = ?', [$bookingId]);
            }
            $from = Db::value('SELECT name FROM professionals WHERE id = ?', [$fromProId]);
            $this->history($bookingId, $b['status'], $b['status'], $userId, "Profissional trocada: $from → {$to['name']}");
        });
    }

    /** Inclui mais uma profissional no atendimento (ex.: produção de noiva + madrinhas). */
    public function addProfessional(int $bookingId, int $proId, ?int $userId, bool $allowOutsideHours = false): void
    {
        Db::transaction(function () use ($bookingId, $proId, $userId, $allowOutsideHours) {
            $b = $this->lockBooking($bookingId);
            if (!in_array($b['status'], self::OPEN, true)) {
                throw new DomainException('Só é possível alterar a equipe de reservas em aberto.');
            }
            $pro = Db::one('SELECT * FROM professionals WHERE id = ? AND active = 1', [$proId]);
            if (!$pro) {
                throw new DomainException('Profissional inválida.');
            }
            if (in_array($proId, $this->allocatedIds($bookingId), true)) {
                throw new DomainException($pro['name'] . ' já está neste atendimento.');
            }
            $this->lockProfessionals([$proId]);
            $this->assertFreeForBooking($b, $proId, $allowOutsideHours);

            $lead = Db::one("SELECT block_start, block_end FROM booking_allocations WHERE booking_id = ? ORDER BY role = 'lead' DESC LIMIT 1", [$bookingId]);
            Db::insert('booking_allocations', [
                'booking_id' => $bookingId,
                'professional_id' => $proId,
                'role' => 'support',
                'block_start' => $lead['block_start'],
                'block_end' => $lead['block_end'],
                'commission_percent' => $pro['commission_percent'],
            ]);
            $this->splitSharesEqually($bookingId);
            $this->history($bookingId, $b['status'], $b['status'], $userId, 'Profissional incluída: ' . $pro['name']);
        });
    }

    public function removeProfessional(int $bookingId, int $proId, ?int $userId): void
    {
        Db::transaction(function () use ($bookingId, $proId, $userId) {
            $b = $this->lockBooking($bookingId);
            if (!in_array($b['status'], self::OPEN, true)) {
                throw new DomainException('Só é possível alterar a equipe de reservas em aberto.');
            }
            $allocs = Db::all('SELECT * FROM booking_allocations WHERE booking_id = ? ORDER BY id', [$bookingId]);
            $target = null;
            foreach ($allocs as $a) {
                if ((int) $a['professional_id'] === $proId) {
                    $target = $a;
                }
            }
            if (!$target) {
                throw new DomainException('Esta profissional não está no atendimento.');
            }
            if (count($allocs) === 1) {
                throw new DomainException('O atendimento precisa de ao menos uma profissional. Use "trocar" em vez de remover.');
            }
            Db::exec('DELETE FROM booking_allocations WHERE id = ?', [$target['id']]);
            if ($target['role'] === 'lead') {
                $next = Db::one('SELECT * FROM booking_allocations WHERE booking_id = ? ORDER BY id LIMIT 1', [$bookingId]);
                Db::update('booking_allocations', ['role' => 'lead'], 'id = ?', [$next['id']]);
                Db::update('bookings', ['professional_id' => $next['professional_id']], 'id = ?', [$bookingId]);
            }
            $this->splitSharesEqually($bookingId);
            $this->history($bookingId, $b['status'], $b['status'], $userId, 'Profissional removida: ' . Db::value('SELECT name FROM professionals WHERE id = ?', [$proId]));
        });
    }

    /** Ajuste manual da divisão do valor e da comissão por profissional. */
    public function updateAllocationTerms(int $bookingId, array $terms, ?int $userId): void
    {
        Db::transaction(function () use ($bookingId, $terms, $userId) {
            $this->lockBooking($bookingId);
            $allocs = Db::all('SELECT * FROM booking_allocations WHERE booking_id = ?', [$bookingId]);
            $sum = 0.0;
            $updates = [];
            foreach ($allocs as $a) {
                $t = $terms[$a['professional_id']] ?? null;
                $share = self::parsePercent((string) ($t['share'] ?? ''));
                $commission = self::parsePercent((string) ($t['commission'] ?? ''));
                if ($share === null || $commission === null) {
                    throw new ValidationException(['terms' => 'Percentuais devem estar entre 0 e 100.']);
                }
                $sum += $share;
                $updates[$a['id']] = ['share_percent' => $share, 'commission_percent' => $commission];
            }
            if (abs($sum - 100) > 0.05) {
                throw new ValidationException(['terms' => sprintf('A divisão do valor precisa somar 100%% (soma atual: %s%%).', number_format($sum, 2, ',', ''))]);
            }
            foreach ($updates as $id => $data) {
                Db::update('booking_allocations', $data, 'id = ?', [$id]);
            }
            $b = Db::one('SELECT status FROM bookings WHERE id = ?', [$bookingId]);
            $this->history($bookingId, $b['status'], $b['status'], $userId, 'Divisão de valores/comissões ajustada');
        });
    }

    /** Cancelamento feito pela própria cliente, respeitando a antecedência configurada. */
    public function cancelByClient(string $code): void
    {
        $b = $this->findByCode($code);
        if (!$b || !in_array($b['status'], self::OPEN, true)) {
            throw new DomainException('Esta reserva não pode mais ser cancelada pela página.');
        }
        if ($b['status'] !== 'requested') {
            $limit = (new DateTimeImmutable($b['starts_at']))->modify('-' . Settings::int('cancellation_min_hours', 48) . ' hours');
            if (Clock::now() > $limit) {
                throw new DomainException('O prazo para cancelar pela página terminou. Fale diretamente com a profissional.');
            }
        }
        $this->changeStatus((int) $b['id'], 'cancelled', null, 'Cancelada pela cliente');
    }

    // ------------------------------------------------------------------ leitura

    public function find(int $id): ?array
    {
        return Db::one(
            'SELECT b.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email, c.preferences AS client_preferences,
                    s.name AS service_name, s.duration_minutes, p.name AS professional_name, p.color AS professional_color,
                    a.name AS area_name
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN services s ON s.id = b.service_id
             JOIN professionals p ON p.id = b.professional_id
             LEFT JOIN service_areas a ON a.id = b.service_area_id
             WHERE b.id = ?',
            [$id]
        );
    }

    public function findByCode(string $code): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $code)) {
            return null;
        }
        $id = Db::value('SELECT id FROM bookings WHERE public_code = ?', [$code]);
        return $id ? $this->find((int) $id) : null;
    }

    /** Profissionais alocadas (responsável primeiro). */
    public function allocations(int $bookingId): array
    {
        return Db::all(
            "SELECT a.*, p.name, p.color FROM booking_allocations a JOIN professionals p ON p.id = a.professional_id
             WHERE a.booking_id = ? ORDER BY a.role = 'lead' DESC, a.id",
            [$bookingId]
        );
    }

    /** @return int[] */
    public function allocatedIds(int $bookingId): array
    {
        return array_map('intval', array_column(Db::all('SELECT professional_id FROM booking_allocations WHERE booking_id = ? ORDER BY professional_id', [$bookingId]), 'professional_id'));
    }

    public function isAllocated(int $bookingId, int $proId): bool
    {
        return $proId > 0 && in_array($proId, $this->allocatedIds($bookingId), true);
    }

    // ------------------------------------------------------------------ apoio

    private function isFree(int $duration, int $proId, DateTimeImmutable $day, DateTimeImmutable $start, int $travel, int $buffer, ?int $excludeId, bool $enforceWindow, bool $ignoreHours): bool
    {
        if ($ignoreHours) {
            [$o0, $o1] = AvailabilityCalculator::occupation($start, $duration, $travel, $buffer);
            return !AvailabilityCalculator::conflicts($o0, $o1, $this->busyIntervals($proId, $o0, $o1, $excludeId));
        }
        $wanted = $start->format('H:i');
        foreach ($this->slotsFor($duration, $proId, $day, $travel, $buffer, $excludeId, $enforceWindow) as $slot) {
            if ($slot->format('H:i') === $wanted) {
                return true;
            }
        }
        return false;
    }

    private function assertFreeForBooking(array $b, int $proId, bool $allowOutsideHours): void
    {
        $start = new DateTimeImmutable($b['starts_at']);
        $ok = $this->isFree($this->duration($b), $proId, $start->setTime(0, 0), $start, (int) $b['travel_minutes'], (int) $b['buffer_minutes'], (int) $b['id'], false, $allowOutsideHours);
        if (!$ok) {
            $name = Db::value('SELECT name FROM professionals WHERE id = ?', [$proId]);
            throw new DomainException("$name não está disponível neste horário" . ($allowOutsideHours ? '.' : ' (conflito ou fora do expediente).'));
        }
    }

    /** Ordena por menor carga: atendimentos no dia, depois na semana, depois id. */
    private function rankByLoad(array $pros, DateTimeImmutable $day): array
    {
        foreach ($pros as &$p) {
            [$p['_day'], $p['_week']] = $this->load((int) $p['id'], $day);
        }
        unset($p);
        usort($pros, static fn ($a, $b) => [$a['_day'], $a['_week'], (int) $a['id']] <=> [$b['_day'], $b['_week'], (int) $b['id']]);
        return $pros;
    }

    /** @return array{0:int,1:int} atendimentos ativos no dia e na semana (domingo a sábado) */
    private function load(int $proId, DateTimeImmutable $day): array
    {
        $statuses = self::blockingStatuses();
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT COUNT(*) FROM booking_allocations a JOIN bookings b ON b.id = a.booking_id
                WHERE a.professional_id = ? AND b.status IN ($in) AND b.starts_at >= ? AND b.starts_at < ?";
        $d0 = $day->setTime(0, 0);
        $w0 = $d0->modify('-' . $d0->format('w') . ' days');
        return [
            (int) Db::value($sql, [$proId, ...$statuses, $d0->format('Y-m-d H:i:s'), $d0->modify('+1 day')->format('Y-m-d H:i:s')]),
            (int) Db::value($sql, [$proId, ...$statuses, $w0->format('Y-m-d H:i:s'), $w0->modify('+7 days')->format('Y-m-d H:i:s')]),
        ];
    }

    /** @param int[] $ids */
    private function lockProfessionals(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        foreach ($ids as $id) {
            Db::one('SELECT id FROM professionals WHERE id = ? FOR UPDATE', [$id]);
        }
    }

    private function lockBooking(int $id): array
    {
        $b = Db::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$id]);
        if (!$b) {
            throw new DomainException('Reserva não encontrada.');
        }
        return $b;
    }

    private function duration(array $b): int
    {
        return (int) round(((new DateTimeImmutable($b['ends_at']))->getTimestamp() - (new DateTimeImmutable($b['starts_at']))->getTimestamp()) / 60);
    }

    private function insertBooking(array $data): int
    {
        $data['public_code'] = bin2hex(random_bytes(12));
        if ($data['status'] === 'confirmed') {
            $data['confirmed_at'] = Clock::now()->format('Y-m-d H:i:s');
        }
        return Db::insert('bookings', $data);
    }

    /** @param int[] $proIds a primeira é a responsável */
    private function insertAllocations(int $bookingId, array $proIds, DateTimeImmutable $start, int $duration, int $travel, int $buffer): void
    {
        [$b0, $b1] = AvailabilityCalculator::occupation($start, $duration, $travel, $buffer);
        foreach ($proIds as $i => $pid) {
            Db::insert('booking_allocations', [
                'booking_id' => $bookingId,
                'professional_id' => $pid,
                'role' => $i === 0 ? 'lead' : 'support',
                'block_start' => $b0->format('Y-m-d H:i:s'),
                'block_end' => $b1->format('Y-m-d H:i:s'),
                'commission_percent' => Db::value('SELECT commission_percent FROM professionals WHERE id = ?', [$pid]) ?? 0,
            ]);
        }
        $this->splitSharesEqually($bookingId);
    }

    /** Divide o valor igualmente entre as profissionais; a diferença de arredondamento fica com a responsável. */
    private function splitSharesEqually(int $bookingId): void
    {
        $allocs = Db::all("SELECT id FROM booking_allocations WHERE booking_id = ? ORDER BY role = 'lead' DESC, id", [$bookingId]);
        $n = count($allocs);
        if ($n === 0) {
            return;
        }
        $each = floor(10000 / $n) / 100;
        foreach ($allocs as $i => $a) {
            $share = $i === 0 ? round(100 - $each * ($n - 1), 2) : $each;
            Db::update('booking_allocations', ['share_percent' => $share], 'id = ?', [$a['id']]);
        }
    }

    /** @return array{0:?DateTimeImmutable,1:string,2:array} */
    private function parseDateTime(array $in): array
    {
        $errors = [];
        $day = $this->parseDate((string) ($in['date'] ?? ''));
        if (!$day) {
            $errors['date'] = 'Escolha uma data válida.';
        }
        $time = (string) ($in['time'] ?? '');
        if (!preg_match(self::TIME_RE, $time)) {
            $errors['time'] = 'Escolha um horário.';
        }
        return [$day, $time, $errors];
    }

    /** @return array{0:string,1:?int,2:string} */
    private function locationInput(array $in): array
    {
        return [
            ($in['location_type'] ?? 'studio') === 'client' ? 'client' : 'studio',
            isset($in['service_area_id']) && $in['service_area_id'] !== '' ? (int) $in['service_area_id'] : null,
            trim((string) ($in['address'] ?? '')),
        ];
    }

    /** @return array{0:?array,1:array} cliente existente (painel) ou erros de validação da nova */
    private function clientInput(array $in, bool $isPublic): array
    {
        if (!$isPublic && !empty($in['client_id'])) {
            $c = Db::one('SELECT * FROM clients WHERE id = ?', [(int) $in['client_id']]);
            return [$c, $c ? [] : ['client_id' => 'Cliente não encontrada.']];
        }
        return [null, ClientService::validate($in, requirePrivacy: $isPublic)];
    }

    /**
     * Captação: indicação (código ?ref=), origem do link (?origem=) e consentimento para campanhas.
     * @return array{0:?int,1:?string} [id da cliente que indicou, origem]
     */
    private function acquisition(array $in, int $clientId, bool $isPublic): array
    {
        $referrerId = ReferralService::resolve($in['ref'] ?? null);
        if ($referrerId === $clientId) {
            $referrerId = null; // ninguém indica a si mesma
        }
        if ($referrerId) {
            Db::exec('UPDATE clients SET referred_by_client_id = ? WHERE id = ? AND referred_by_client_id IS NULL', [$referrerId, $clientId]);
            Db::exec("UPDATE clients SET source = 'indicacao' WHERE id = ? AND source IS NULL", [$clientId]);
        }
        $origin = strtolower(trim((string) ($in['origem'] ?? '')));
        $origin = preg_match('/^[a-z0-9_-]{1,40}$/', $origin) ? $origin : null;
        if ($isPublic && !empty($in['marketing'])) {
            ConsentService::set($clientId, true, 'formulario_publico', 'Marcou a opção ao agendar', null, client_ip());
        }
        return [$referrerId, $origin];
    }

    private function initialStatus(array $in): string
    {
        return in_array($in['status'] ?? '', ['requested', 'awaiting_deposit', 'confirmed'], true) ? $in['status'] : 'confirmed';
    }

    private function areaServedBy(?array $area, int $proId): bool
    {
        return !$area || $area['professional_id'] === null || (int) $area['professional_id'] === $proId;
    }

    /** @return array{travel:int,fee:int,area:?array} */
    private function resolveLocation(string $mode, string $locationType, ?int $areaId): array
    {
        if ($locationType === 'studio') {
            if ($mode === 'client') {
                throw new ValidationException(['location_type' => 'Este serviço é realizado apenas no local da cliente.']);
            }
            return ['travel' => 0, 'fee' => 0, 'area' => null];
        }
        if ($mode === 'studio') {
            throw new ValidationException(['location_type' => 'Este serviço é realizado apenas no estúdio.']);
        }
        $area = $areaId ? Db::one('SELECT * FROM service_areas WHERE id = ? AND active = 1', [$areaId]) : null;
        if (!$area) {
            throw new ValidationException(['service_area_id' => 'Escolha a região do atendimento.']);
        }
        return ['travel' => (int) $area['travel_minutes'], 'fee' => (int) $area['travel_fee_cents'], 'area' => $area];
    }

    private function activeService(int $id): ?array
    {
        return $id > 0 ? Db::one('SELECT * FROM services WHERE id = ? AND active = 1', [$id]) : null;
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date ? $d : null;
    }

    public static function parsePercent(string $v): ?float
    {
        $v = str_replace(',', '.', trim(str_replace('%', '', $v)));
        if ($v === '' || !is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
            return null;
        }
        return round((float) $v, 2);
    }

    private function history(int $bookingId, ?string $from, string $to, ?int $userId, ?string $note): void
    {
        Db::insert('booking_status_history', [
            'booking_id' => $bookingId,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $userId,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }
}
