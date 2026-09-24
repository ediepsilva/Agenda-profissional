<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Domain\Settings;

final class SettingsController extends Controller
{
    public function index(): void
    {
        $this->view('admin/settings/index', [
            'pageTitle' => 'Configurações',
            's' => Settings::all(),
        ]);
    }

    public function save(): void
    {
        $errors = [];
        $values = [];
        foreach (Settings::NUMERIC as $key => [$min, $max]) {
            $raw = $key === 'hold_pending_requests' ? (!empty($_POST[$key]) ? '1' : '0') : $this->input($key);
            if (!preg_match('/^\d+$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[$key] = "Valor entre $min e $max.";
                continue;
            }
            $values[$key] = (string) (int) $raw;
        }
        foreach (Settings::TEXT as $key) {
            $value = mb_substr($this->input($key), 0, 2000);
            if ($key === 'business_name' && mb_strlen($value) < 2) {
                $errors[$key] = 'Informe o nome do negócio.';
                continue;
            }
            if ($key === 'whatsapp') {
                $value = preg_replace('/\D/', '', $value);
            }
            if ($key === 'instagram') {
                $value = ltrim($value, '@');
            }
            $values[$key] = $value;
        }
        if ($errors) {
            Response::back('/admin/configuracoes', $errors, $_POST);
            return;
        }
        foreach ($values as $key => $value) {
            Settings::set($key, $value);
        }
        Session::flash('success', 'Configurações salvas.');
        Response::redirect('/admin/configuracoes');
    }
}
