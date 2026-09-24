<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Env;
use App\Domain\Clock;

/**
 * Dados de exemplo para desenvolvimento.
 * A usuária dona é criada com ADMIN_EMAIL / ADMIN_PASSWORD do .env
 * (se a senha não estiver definida, uma senha aleatória é gerada e exibida).
 */
final class DevSeeder
{
    /** @return array{email:string,password:string,generated:bool} */
    public static function run(bool $withDemoData = true): array
    {
        $email = mb_strtolower(Env::get('ADMIN_EMAIL', 'dona@exemplo.com'));
        $password = (string) Env::get('ADMIN_PASSWORD', '');
        $generated = false;
        if (strlen($password) < 8) {
            $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
            $generated = true;
        }

        $userId = Db::insert('users', [
            'name' => Env::get('ADMIN_NAME', 'Dona do Studio'),
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'owner',
        ]);
        $proId = Db::insert('professionals', [
            'user_id' => $userId,
            'name' => Env::get('ADMIN_NAME', 'Dona do Studio'),
            'color' => '#b0677a',
        ]);

        // Terça a sábado 09–12 e 13–19; domingo 07–13 (noivas).
        foreach ([2, 3, 4, 5, 6] as $wd) {
            Db::insert('availability_rules', ['professional_id' => $proId, 'weekday' => $wd, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
            Db::insert('availability_rules', ['professional_id' => $proId, 'weekday' => $wd, 'start_time' => '13:00:00', 'end_time' => '19:00:00']);
        }
        Db::insert('availability_rules', ['professional_id' => $proId, 'weekday' => 0, 'start_time' => '07:00:00', 'end_time' => '13:00:00']);

        if (!$withDemoData) {
            return compact('email', 'password', 'generated');
        }

        $services = [
            ['Maquiagem social', 'Social', 'Para festas, formaturas e eventos. Pele com acabamento natural ou glam.', 60, 18000, null, 'both', 1],
            ['Maquiagem de noiva', 'Noivas', 'Inclui teste prévio agendado à parte e kit de retoque.', 120, 65000, 20000, 'both', 2],
            ['Maquiagem + penteado', 'Social', 'Produção completa com penteado simples.', 150, 38000, null, 'both', 3],
            ['Maquiagem artística', 'Artística', 'Editorial, fotos, teatro e caracterização.', 90, 25000, null, 'studio', 4],
        ];
        $serviceIds = [];
        foreach ($services as [$name, $cat, $desc, $dur, $price, $dep, $mode, $order]) {
            $id = Db::insert('services', [
                'name' => $name, 'category' => $cat, 'description' => $desc, 'duration_minutes' => $dur,
                'price_cents' => $price, 'deposit_cents' => $dep, 'location_mode' => $mode, 'sort_order' => $order,
            ]);
            Db::insert('professional_services', ['professional_id' => $proId, 'service_id' => $id]);
            $serviceIds[] = $id;
        }

        // Segunda profissional (sem login) para demonstrar a equipe, com comissão de 40%.
        $pro2 = Db::insert('professionals', ['name' => 'Júlia (maquiadora parceira)', 'color' => '#5b7fa6', 'commission_percent' => 40]);
        foreach ([2, 3, 4, 5, 6] as $wd) {
            Db::insert('availability_rules', ['professional_id' => $pro2, 'weekday' => $wd, 'start_time' => '10:00:00', 'end_time' => '19:00:00']);
        }
        foreach ([$serviceIds[0], $serviceIds[2]] as $sid) {
            Db::insert('professional_services', ['professional_id' => $pro2, 'service_id' => $sid]);
        }

        Db::insert('service_areas', ['name' => 'Centro e bairros próximos', 'travel_minutes' => 20, 'travel_fee_cents' => 0, 'sort_order' => 1]);
        Db::insert('service_areas', ['name' => 'Zona Sul', 'travel_minutes' => 40, 'travel_fee_cents' => 4000, 'sort_order' => 2]);
        Db::insert('service_areas', ['name' => 'Região metropolitana', 'travel_minutes' => 60, 'travel_fee_cents' => 8000, 'sort_order' => 3]);

        $clients = [
            ['Ana Souza', '11987650001', 'instagram', 'Pele oleosa; prefere acabamento matte.'],
            ['Beatriz Lima', '11987650002', 'indicacao', null],
            ['Carla Mendes', '11987650003', 'google', 'Alergia a produtos com fragrância.'],
        ];
        $clientIds = [];
        $now = Clock::now()->format('Y-m-d H:i:s');
        foreach ($clients as [$name, $phone, $source, $prefs]) {
            $clientIds[] = Db::insert('clients', ['name' => $name, 'phone' => $phone, 'source' => $source, 'preferences' => $prefs, 'privacy_accepted_at' => $now]);
        }

        // Algumas reservas nos próximos dias úteis (terça a sábado) para popular a agenda.
        $day = Clock::now()->setTime(0, 0)->modify('+3 days');
        $made = 0;
        $plan = [['10:00', 0, 0, 'confirmed'], ['14:00', 1, 2, 'awaiting_deposit'], ['16:30', 2, 0, 'requested']];
        while ($made < count($plan)) {
            if (in_array((int) $day->format('w'), [2, 3, 4, 5, 6], true)) {
                [$time, $ci, $si, $status] = $plan[$made];
                $svc = Db::one('SELECT * FROM services WHERE id = ?', [$serviceIds[$si]]);
                $start = $day->modify($time);
                $end = $start->modify('+' . $svc['duration_minutes'] . ' minutes');
                $bid = Db::insert('bookings', [
                    'public_code' => bin2hex(random_bytes(12)),
                    'client_id' => $clientIds[$ci], 'service_id' => $svc['id'], 'professional_id' => $proId,
                    'status' => $status, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'),
                    'location_type' => 'studio', 'buffer_minutes' => 30, 'price_cents' => $svc['price_cents'],
                    'deposit_cents' => $svc['deposit_cents'] ?? (int) round($svc['price_cents'] * 0.3), 'channel' => 'admin',
                    'confirmed_at' => $status === 'confirmed' ? $now : null, 'created_by' => $userId,
                ]);
                Db::insert('booking_allocations', [
                    'booking_id' => $bid, 'professional_id' => $proId, 'role' => 'lead',
                    'block_start' => $start->format('Y-m-d H:i:s'), 'block_end' => $end->modify('+30 minutes')->format('Y-m-d H:i:s'),
                ]);
                Db::insert('booking_status_history', ['booking_id' => $bid, 'to_status' => $status, 'changed_by' => $userId, 'note' => 'Dado de exemplo']);
                $made++;
            }
            $day = $day->modify('+1 day');
        }

        return compact('email', 'password', 'generated');
    }
}
