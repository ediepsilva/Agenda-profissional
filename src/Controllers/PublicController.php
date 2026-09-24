<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\BookingService;
use App\Domain\RateLimiter;
use App\Domain\ValidationException;
use DomainException;

final class PublicController extends Controller
{
    public function home(): void
    {
        $this->view('public/home', [
            'services' => $this->services(),
            'areas' => $this->areas(),
        ], 'public');
    }

    public function bookingForm(): void
    {
        $this->view('public/booking', [
            'services' => $this->services(),
            'areas' => $this->areas(),
            'selected' => (int) ($_GET['servico'] ?? old('service_id', 0)),
            'pageTitle' => 'Agendar horário',
        ], 'public');
    }

    public function bookingSubmit(): void
    {
        // Honeypot: campo invisível que só robôs preenchem.
        if ($this->input('website') !== '') {
            Response::redirect('/agendar');
            return;
        }
        if (!RateLimiter::hit('public_booking', client_ip(), 8, 60)) {
            Response::back('/agendar', ['form' => 'Muitas solicitações seguidas. Tente novamente mais tarde ou fale pelo WhatsApp.'], $_POST);
            return;
        }

        try {
            $booking = (new BookingService())->create($_POST, 'public');
        } catch (ValidationException $e) {
            Response::back('/agendar', $e->errors, $_POST);
            return;
        }
        Session::flash('success', 'Solicitação enviada! Guarde este link para acompanhar sua reserva.');
        Response::redirect('/reserva/' . $booking['public_code']);
    }

    public function bookingStatus(string $code): void
    {
        $booking = (new BookingService())->findByCode($code);
        if (!$booking) {
            $this->notFound();
            return;
        }
        header('X-Robots-Tag: noindex');
        $this->view('public/booking_status', ['b' => $booking, 'pageTitle' => 'Sua reserva'], 'public');
    }

    public function bookingCancel(string $code): void
    {
        try {
            (new BookingService())->cancelByClient($code);
            Session::flash('success', 'Sua reserva foi cancelada.');
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('/reserva/' . $code);
    }

    public function privacy(): void
    {
        $this->view('public/privacy', ['pageTitle' => 'Privacidade'], 'public');
    }

    private function services(): array
    {
        return Db::all('SELECT * FROM services WHERE active = 1 ORDER BY sort_order, name');
    }

    private function areas(): array
    {
        return Db::all('SELECT * FROM service_areas WHERE active = 1 ORDER BY sort_order, name');
    }
}
