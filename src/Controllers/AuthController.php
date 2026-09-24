<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function form(): void
    {
        if (Auth::user()) {
            Response::redirect('/admin');
            return;
        }
        $this->view('admin/login', ['pageTitle' => 'Entrar'], 'public');
    }

    public function login(): void
    {
        $email = $this->input('email');
        $result = Auth::attempt($email, (string) ($_POST['password'] ?? ''), client_ip());
        if (!$result['ok']) {
            Response::back('/admin/login', ['login' => $result['error']], ['email' => $email]);
            return;
        }
        $intended = Session::get('_intended');
        Session::forget('_intended');
        $base = base_path_url();
        // Só redireciona para caminhos internos do painel.
        if (is_string($intended) && str_starts_with(rawurldecode($intended), $base . '/admin')) {
            header('Location: ' . $intended, true, 303);
            return;
        }
        Response::redirect('/admin');
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/admin/login');
    }
}
