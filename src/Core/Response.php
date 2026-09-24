<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $path, array $query = []): void
    {
        $target = preg_match('#^https?://#', $path) ? $path : url($path, $query);
        header('Location: ' . $target, true, 303);
    }

    /** Redireciona de volta ao formulário guardando os dados digitados e os erros. */
    public static function back(string $path, array $errors, array $input = []): void
    {
        unset($input['_csrf'], $input['password']);
        Session::set('_old', $input);
        Session::set('_errors', $errors);
        self::redirect($path);
    }

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(int $status, string $title, string $message): void
    {
        http_response_code($status);
        View::render('errors/error', compact('title', 'message', 'status'), Auth::user() ? 'admin' : 'public');
    }
}
