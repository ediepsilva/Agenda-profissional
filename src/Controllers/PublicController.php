<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\BookingService;
use App\Domain\ConsentService;
use App\Domain\OnlinePaymentService;
use App\Domain\RateLimiter;
use App\Domain\ReferralService;
use App\Domain\ReviewService;
use App\Domain\ValidationException;
use DomainException;

final class PublicController extends Controller
{
    public function home(): void
    {
        $this->captureAcquisition();
        $reviews = new ReviewService();
        $this->view('public/home', [
            'services' => $this->services(),
            'areas' => $this->areas(),
            'team' => Db::all('SELECT name, bio, color FROM professionals WHERE active = 1 AND accepts_online_booking = 1 ORDER BY id'),
            'reviews' => $reviews->published(6),
            'reviewStats' => $reviews->stats(),
            'referred' => Session::get('ref') !== null,
        ], 'public');
    }

    public function bookingForm(): void
    {
        $this->captureAcquisition();
        $this->view('public/booking', [
            'services' => $this->services(),
            'areas' => $this->areas(),
            'selected' => (int) ($_GET['servico'] ?? old('service_id', 0)),
            'ref' => Session::get('ref'),
            'origem' => Session::get('origem'),
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
        Session::forget('ref');
        Session::forget('origem');
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
        $pay = new OnlinePaymentService();
        $due = $pay->amountDue($booking);
        $client = ConsentService::ensureTokens((int) $booking['client_id']);
        $this->view('public/booking_status', [
            'b' => $booking,
            'pageTitle' => 'Sua reserva',
            'due' => $due,
            'canPayOnline' => $pay->available() && in_array($booking['status'], ['awaiting_deposit', 'confirmed'], true) && $due['cents'] > 0,
            'showPix' => setting('pix_key') !== '' && in_array($booking['status'], ['awaiting_deposit', 'confirmed'], true) && $due['cents'] > 0,
            'canReview' => (new ReviewService())->canReview($booking),
            'referralLink' => absolute_url('/?ref=' . $client['referral_code']),
            'preferencesLink' => absolute_url('/preferencias/' . $client['preferences_token']),
        ], 'public');
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

    /** Leva a cliente ao checkout do gateway (link criado na hora). */
    public function pay(string $code): void
    {
        $booking = (new BookingService())->findByCode($code);
        if (!$booking) {
            $this->notFound();
            return;
        }
        if (!RateLimiter::hit('checkout', client_ip(), 20, 60)) {
            Session::flash('error', 'Muitas tentativas. Aguarde alguns minutos.');
            Response::redirect('/reserva/' . $code);
            return;
        }
        try {
            $url = (new OnlinePaymentService())->checkoutUrl($booking);
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect('/reserva/' . $code);
            return;
        }
        header('Location: ' . $url, true, 303);
    }

    /** Volta do checkout. A confirmação real chega pela notificação do gateway. */
    public function paymentReturn(string $code): void
    {
        $status = (string) ($_GET['status'] ?? $_GET['collection_status'] ?? '');
        if ($status === 'approved') {
            Session::flash('success', 'Pagamento aprovado! Em instantes ele aparece na sua reserva.');
        } elseif ($status === 'pending' || $status === 'in_process') {
            Session::flash('info', 'Pagamento em processamento. Assim que for aprovado, sua reserva é atualizada.');
        } elseif ($status !== '') {
            Session::flash('error', 'O pagamento não foi concluído. Você pode tentar novamente.');
        }
        Response::redirect('/reserva/' . preg_replace('/[^a-f0-9]/', '', $code));
    }

    public function reviewForm(string $code): void
    {
        $booking = (new BookingService())->findByCode($code);
        if (!$booking) {
            $this->notFound();
            return;
        }
        header('X-Robots-Tag: noindex');
        $this->view('public/review', [
            'b' => $booking,
            'canReview' => (new ReviewService())->canReview($booking),
            'pageTitle' => 'Avaliar atendimento',
        ], 'public');
    }

    public function reviewSubmit(string $code): void
    {
        $booking = (new BookingService())->findByCode($code);
        if (!$booking) {
            $this->notFound();
            return;
        }
        try {
            (new ReviewService())->submit($booking, $_POST);
        } catch (ValidationException $e) {
            Response::back('/avaliar/' . $code, $e->errors, $_POST);
            return;
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect('/avaliar/' . $code);
            return;
        }
        Session::flash('success', 'Obrigada pela avaliação! 💕');
        Response::redirect('/reserva/' . $code);
    }

    /** Preferências de mensagens (link pessoal, sem login). */
    public function preferences(string $token): void
    {
        $client = ConsentService::findByToken($token);
        if (!$client) {
            $this->notFound();
            return;
        }
        header('X-Robots-Tag: noindex');
        $this->view('public/preferences', ['c' => $client, 'token' => $token, 'pageTitle' => 'Preferências de mensagens'], 'public');
    }

    public function preferencesSave(string $token): void
    {
        $client = ConsentService::findByToken($token);
        if (!$client) {
            $this->notFound();
            return;
        }
        $optIn = ($_POST['marketing'] ?? '') === '1';
        ConsentService::set((int) $client['id'], $optIn, 'pagina_preferencias', null, null, client_ip());
        Session::flash('success', $optIn ? 'Pronto! Você vai receber novidades e ofertas.' : 'Pronto! Você não vai mais receber ofertas. Os avisos das suas reservas continuam.');
        Response::redirect('/preferencias/' . $token);
    }

    public function privacy(): void
    {
        $this->view('public/privacy', ['pageTitle' => 'Privacidade'], 'public');
    }

    /** Guarda na sessão o código de indicação (?ref=) e a origem do link (?origem=). */
    private function captureAcquisition(): void
    {
        if (isset($_GET['ref']) && ($code = ReferralService::normalizeCode($_GET['ref'])) && ReferralService::resolve($code)) {
            Session::set('ref', $code);
        }
        $origin = strtolower((string) ($_GET['origem'] ?? ''));
        if ($origin !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', $origin)) {
            Session::set('origem', $origin);
        }
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
