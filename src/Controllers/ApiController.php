<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Domain\BookingService;

/** Endpoints públicos usados pelo calendário da página de agendamento. */
final class ApiController extends Controller
{
    public function slots(): void
    {
        $slots = (new BookingService())->availableSlots(
            (int) $this->input('servico'),
            $this->input('data'),
            $this->input('local', 'studio'),
            $this->input('area') !== '' ? (int) $this->input('area') : null,
        );
        // Só os horários: a página pública não precisa saber quais profissionais estão livres.
        Response::json(['slots' => array_keys($slots)]);
    }

    public function days(): void
    {
        $days = (new BookingService())->availableDays(
            (int) $this->input('servico'),
            $this->input('mes'),
            $this->input('local', 'studio'),
            $this->input('area') !== '' ? (int) $this->input('area') : null,
        );
        Response::json(['days' => $days]);
    }
}
