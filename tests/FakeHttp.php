<?php
declare(strict_types=1);

namespace Tests;

use App\Integrations\HttpClient;

/** Cliente HTTP falso: devolve respostas programadas e registra as requisições feitas. */
final class FakeHttp implements HttpClient
{
    /** @var array<int,array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = [];
    /** @var array<int,array{status:int,body:string}|callable> */
    private array $responses = [];

    public function push(int $status, array|string $body): self
    {
        $this->responses[] = ['status' => $status, 'body' => is_array($body) ? json_encode($body) : $body];
        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if (!$this->responses) {
            throw new AssertionFailed("Requisição inesperada: $method $url");
        }
        return array_shift($this->responses);
    }

    public function last(): array
    {
        return end($this->requests) ?: [];
    }

    public function lastJson(): array
    {
        return json_decode((string) ($this->last()['body'] ?? ''), true) ?? [];
    }
}
