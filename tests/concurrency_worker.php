<?php
declare(strict_types=1);

/*
 * Processo auxiliar do teste de concorrência: aguarda o instante combinado e
 * tenta criar a mesma reserva que os outros processos. Imprime OK ou FAIL.
 * Args: service_id date time phone go_timestamp
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\BookingService;
use App\Domain\Clock;
use App\Domain\ValidationException;

[, $serviceId, $date, $time, $phone, $go] = $argv;
Clock::freeze(new DateTimeImmutable('2030-01-01 08:00'));
App\Core\Db::pdo(); // conecta antes da largada

while (microtime(true) < (float) $go) {
    usleep(1000);
}

try {
    (new BookingService())->create([
        'service_id' => $serviceId, 'date' => $date, 'time' => $time, 'location_type' => 'studio',
        'name' => 'Concorrente', 'phone' => $phone, 'privacy' => '1',
    ], 'public');
    echo 'OK';
} catch (ValidationException) {
    echo 'FAIL';
}
