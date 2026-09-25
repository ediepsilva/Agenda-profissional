<?php
declare(strict_types=1);

namespace App\Integrations;

/** Cliente HTTP mínimo (substituível nos testes por um falso). */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
