<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use DateTimeImmutable;
use DomainException;

/**
 * Regras de reserva: horários disponíveis, criação sem conflito, mudança de status e reagendamento.
 *
 * Proteção contra conflito: toda escrita que ocupa agenda roda em transação e
 * trava a linha da profissional (SELECT ... FOR UPDATE) antes de conferir a
 * disponibilidade. Assim duas solicitações simultâneas para o mesmo horário
 * são serializadas e a segunda é recusada.
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

    /** Status que exigem agenda livre ao serem atingidos. */
    private const COMMITTED = ['awaiting_deposit', 'confirmed'];

    /** Status em que ainda é possível reagendar/cancelar. */
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
    public function slotsFor(array $service, int $professionalId, DateTimeImmutable $day, int $travel, ?int $excludeBookingId = null, bool $enforceBookingWindow = true): array
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
            (int) $service['duration_minutes'],
            $travel,
            Settings::int('buffer_minutes', 0),
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
            $loc = $this->resolveLocation($service, $locationType, $areaId);
        } catch (ValidationException) {
            return [];
        }

        $result = [];
        foreach ($this->professionalsForService($serviceId, true) as $pro) {
            if ($loc['area'] && $loc['area']['professional_id'] !== null && (int) $loc['area']['professional_id'] !== (int) $pro['id']) {
                continue;
            }
            foreach ($this->slotsFor($service, (int) $pro['id'], $day, $loc['travel']) as $slot) {
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
    public function adminSlots(int $serviceId, int $professionalId, string $date, string $locationType, ?int $areaId): array
    {
        $service = $this->activeService($serviceId);
        $day = $this->parseDate($date);
        if (!$service || !$day) {
            return [];
        }
        try {
            $loc = $this->resolveLocation($service, $locationType, $areaId);
        } catch (ValidationException) {
            return [];
        }
        return array_map(
            static fn ($s) => $s->format('H:i'),
            $this->slotsFor($service, $professionalId, $day, $loc['travel'], null, false)
        );
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

    // ------------------------------------------------------------------ escrita

    /**
     * Cria uma reserva.
     * $channel: 'public' (cliente pela página) ou 'admin' (lançada no painel).
     * $allowOutsideHours (só admin): ignora janelas de trabalho, mas nunca conflitos.
     */
    public function create(array $in, string $channel = 'public', ?int $userId = null, bool $allowOutsideHours = false): array
    {
        $errors = [];
        $isPublic = $channel === 'public';

        $service = $this->activeService((int) ($in['service_id'] ?? 0));
        if (!$service) {
            $errors['service_id'] = 'Escolha um serviço.';
        }
        $day = $this->parseDate((string) ($in['date'] ?? ''));
        if (!$day) {
            $errors['date'] = 'Escolha uma data válida.';
        }
        $time = (string) ($in['time'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors['time'] = 'Escolha um horário.';
        }

        $locationType = ($in['location_type'] ?? 'studio') === 'client' ? 'client' : 'studio';
        $areaId = isset($in['service_area_id']) && $in['service_area_id'] !== '' ? (int) $in['service_area_id'] : null;
        $address = trim((string) ($in['address'] ?? ''));
        $loc = ['travel' => 0, 'fee' => 0, 'area' => null];
        if ($service) {
            try {
                $loc = $this->resolveLocation($service, $locationType, $areaId);
            } catch (ValidationException $e) {
                $errors += $e->errors;
            }
        }
        if ($locationType === 'client' && mb_strlen($address) < 5) {
            $errors['address'] = 'Informe o endereço do atendimento.';
        }

        $clientData = null;
        if (!empty($in['client_id']) && !$isPublic) {
            $clientData = Db::one('SELECT * FROM clients WHERE id = ?', [(int) $in['client_id']]);
            if (!$clientData) {
                $errors['client_id'] = 'Cliente não encontrada.';
            }
        } else {
            $errors += ClientService::validate($in, requirePrivacy: $isPublic);
        }

        $notes = trim((string) ($in['client_notes'] ?? ''));
        if (mb_strlen($notes) > 1000) {
            $errors['client_notes'] = 'Use no máximo 1000 caracteres.';
        }

        $status = 'requested';
        if (!$isPublic) {
            $status = in_array($in['status'] ?? '', ['requested', 'awaiting_deposit', 'confirmed'], true) ? $in['status'] : 'confirmed';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        $start = $day->modify($time);
        $end = $start->modify('+' . (int) $service['duration_minutes'] . ' minutes');
        $buffer = Settings::int('buffer_minutes', 0);

        $candidates = $this->professionalsForService((int) $service['id'], $isPublic);
        if (!$isPublic && !empty($in['professional_id'])) {
            $candidates = array_values(array_filter($candidates, static fn ($p) => (int) $p['id'] === (int) $in['professional_id']));
        }
        if ($loc['area'] && $loc['area']['professional_id'] !== null) {
            $candidates = array_values(array_filter($candidates, static fn ($p) => (int) $p['id'] === (int) $loc['area']['professional_id']));
        }
        if (!$candidates) {
            throw new ValidationException(['service_id' => 'Nenhuma profissional disponível para este serviço.']);
        }

        return Db::transaction(function () use ($in, $isPublic, $service, $day, $start, $end, $loc, $address, $locationType, $candidates, $buffer, $status, $channel, $userId, $allowOutsideHours, $notes, $clientData) {
            $chosen = null;
            foreach ($candidates as $pro) {
                $proId = (int) $pro['id'];
                Db::one('SELECT id FROM professionals WHERE id = ? FOR UPDATE', [$proId]);
                if ($this->isFree($service, $proId, $day, $start, $loc['travel'], $buffer, null, $isPublic, !$isPublic && $allowOutsideHours)) {
                    $chosen = $proId;
                    break;
                }
            }
            if ($chosen === null) {
                throw new ValidationException(['time' => 'Este horário não está mais disponível. Por favor, escolha outro.']);
            }

            $clientId = $clientData ? (int) $clientData['id'] : ClientService::findOrCreate($in, $isPublic);

            $price = (int) $service['price_cents'] + $loc['fee'];
            $deposit = $service['deposit_cents'] !== null
                ? (int) $service['deposit_cents']
                : (int) round($price * Settings::int('deposit_percent', 0) / 100);

            $now = Clock::now()->format('Y-m-d H:i:s');
            $bookingId = Db::insert('bookings', [
                'public_code' => bin2hex(random_bytes(12)),
                'client_id' => $clientId,
                'service_id' => (int) $service['id'],
                'professional_id' => $chosen,
                'status' => $status,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
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
                'confirmed_at' => $status === 'confirmed' ? $now : null,
                'created_by' => $userId,
            ]);

            [$b0, $b1] = AvailabilityCalculator::occupation($start, (int) $service['duration_minutes'], $loc['travel'], $buffer);
            Db::insert('booking_allocations', [
                'booking_id' => $bookingId,
                'professional_id' => $chosen,
                'role' => 'lead',
                'block_start' => $b0->format('Y-m-d H:i:s'),
                'block_end' => $b1->format('Y-m-d H:i:s'),
            ]);
            $this->history($bookingId, null, $status, $userId, $isPublic ? 'Solicitação pela página pública' : 'Lançada no painel');

            return $this->find($bookingId);
        });
    }

    public function changeStatus(int $bookingId, string $to, ?int $userId, ?string $note = null): void
    {
        Db::transaction(function () use ($bookingId, $to, $userId, $note) {
            $b = Db::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if (!$b) {
                throw new DomainException('Reserva não encontrada.');
            }
            $from = $b['status'];
            if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException(sprintf('Não é possível passar de "%s" para "%s".', self::label($from), self::label($to)));
            }

            if (in_array($to, self::COMMITTED, true)) {
                foreach (Db::all('SELECT * FROM booking_allocations WHERE booking_id = ?', [$bookingId]) as $a) {
                    Db::one('SELECT id FROM professionals WHERE id = ? FOR UPDATE', [$a['professional_id']]);
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
        });
    }

    public function reschedule(int $bookingId, string $date, string $time, ?int $userId, bool $allowOutsideHours = false): void
    {
        $day = $this->parseDate($date);
        if (!$day || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ValidationException(['time' => 'Informe data e horário válidos.']);
        }

        Db::transaction(function () use ($bookingId, $day, $time, $userId, $allowOutsideHours) {
            $b = Db::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if (!$b || !in_array($b['status'], self::OPEN, true)) {
                throw new DomainException('Só é possível reagendar reservas em aberto.');
            }
            $service = Db::one('SELECT * FROM services WHERE id = ?', [$b['service_id']]);
            $proId = (int) $b['professional_id'];
            Db::one('SELECT id FROM professionals WHERE id = ? FOR UPDATE', [$proId]);

            $start = $day->modify($time);
            $travel = (int) $b['travel_minutes'];
            $buffer = (int) $b['buffer_minutes'];
            if (!$this->isFree($service, $proId, $day, $start, $travel, $buffer, $bookingId, false, $allowOutsideHours)) {
                throw new ValidationException(['time' => 'O novo horário não está disponível.']);
            }

            $end = $start->modify('+' . (int) $service['duration_minutes'] . ' minutes');
            [$b0, $b1] = AvailabilityCalculator::occupation($start, (int) $service['duration_minutes'], $travel, $buffer);
            Db::update('bookings', [
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
            ], 'id = ?', [$bookingId]);
            Db::update('booking_allocations', [
                'block_start' => $b0->format('Y-m-d H:i:s'),
                'block_end' => $b1->format('Y-m-d H:i:s'),
            ], 'booking_id = ?', [$bookingId]);
            $this->history($bookingId, $b['status'], $b['status'], $userId, 'Reagendada de ' . datetime_br($b['starts_at']) . ' para ' . datetime_br($start));
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

    // ------------------------------------------------------------------ apoio

    public function find(int $id): ?array
    {
        return Db::one(
            'SELECT b.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
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

    private function isFree(array $service, int $proId, DateTimeImmutable $day, DateTimeImmutable $start, int $travel, int $buffer, ?int $excludeId, bool $enforceWindow, bool $ignoreHours): bool
    {
        if ($ignoreHours) {
            [$o0, $o1] = AvailabilityCalculator::occupation($start, (int) $service['duration_minutes'], $travel, $buffer);
            return !AvailabilityCalculator::conflicts($o0, $o1, $this->busyIntervals($proId, $o0, $o1, $excludeId));
        }
        $wanted = $start->format('H:i');
        foreach ($this->slotsFor($service, $proId, $day, $travel, $excludeId, $enforceWindow) as $slot) {
            if ($slot->format('H:i') === $wanted) {
                return true;
            }
        }
        return false;
    }

    /** @return array{travel:int,fee:int,area:?array} */
    private function resolveLocation(array $service, string $locationType, ?int $areaId): array
    {
        $mode = $service['location_mode'];
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
