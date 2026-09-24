<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

/** Criação rápida de registros para os testes. */
final class Fixtures
{
    public static function user(string $role = 'owner', string $email = 'dona@teste.com', string $password = 'senha-segura-123'): int
    {
        return Db::insert('users', [
            'name' => ucfirst($role), 'email' => $email, 'role' => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    public static function professional(?int $userId = null, string $name = 'Maquiadora'): int
    {
        return Db::insert('professionals', ['user_id' => $userId, 'name' => $name]);
    }

    public static function service(int $proId, int $duration = 60, string $mode = 'both', int $price = 20000): int
    {
        $id = Db::insert('services', [
            'name' => 'Serviço ' . $duration, 'duration_minutes' => $duration, 'price_cents' => $price, 'location_mode' => $mode,
        ]);
        Db::insert('professional_services', ['professional_id' => $proId, 'service_id' => $id]);
        return $id;
    }

    /** @param array<int,array{0:string,1:string}> $windows */
    public static function availability(int $proId, int $weekday, array $windows): void
    {
        foreach ($windows as [$s, $e]) {
            Db::insert('availability_rules', ['professional_id' => $proId, 'weekday' => $weekday, 'start_time' => "$s:00", 'end_time' => "$e:00"]);
        }
    }

    public static function area(int $travel, int $fee = 0): int
    {
        return Db::insert('service_areas', ['name' => "Área $travel", 'travel_minutes' => $travel, 'travel_fee_cents' => $fee]);
    }

    public static function bookingInput(int $serviceId, string $date, string $time, string $phone = '11987654321', array $extra = []): array
    {
        return $extra + [
            'service_id' => $serviceId, 'date' => $date, 'time' => $time, 'location_type' => 'studio',
            'name' => 'Cliente Teste', 'phone' => $phone, 'privacy' => '1',
        ];
    }
}
