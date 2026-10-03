<?php

namespace Omnifood\UberEats;

use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Omnifood\Model\Token;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Uber Eats Marketplace APIs (api.uber.com), thin: JSON calls with a
 * Bearer token from Uber's OAuth (client credentials, 30 days), held
 * until it dies - Uber allows 100 token requests an hour - and Uber's
 * errors, {"code", "message"}, as Omnifood's; 401 and 403 as
 * UnauthorizedException.
 */
final class Api
{
    public const PLATFORM = 'ubereats';
    public const BASE_URI = 'https://api.uber.com';
    public const TOKEN_URL = 'https://auth.uber.com/oauth/v2/token';
    public const SANDBOX_BASE_URI = 'https://test-api.uber.com';
    public const SANDBOX_TOKEN_URL = 'https://sandbox-login.uber.com/oauth/v2/token';

    /** Orders, the store and the menu, its status (each must be granted to the application by Uber). */
    public const SCOPES = ['eats.store', 'eats.order', 'eats.store.orders.read', 'eats.store.status.write'];

    private readonly HttpClientInterface $http;

    /** @param list<string> $scopes */
    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private ?Token $token = null,
        private readonly array $scopes = self::SCOPES,
        private readonly string $baseUri = self::BASE_URI,
        private readonly string $tokenUrl = self::TOKEN_URL,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    /**
     * @param array<string, scalar|null>       $query
     * @param array<mixed>|object|null $json
     *
     * @return array<mixed> the answer, [] when it has none (204)
     */
    public function request(string $method, string $path, array $query = [], array|object|null $json = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->token()->accessToken, 'Accept' => 'application/json']];
        if ($query = array_filter($query, static fn ($v) => null !== $v && '' !== $v)) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }

        return $this->send($method, rtrim($this->baseUri, '/').'/'.ltrim($path, '/'), $options);
    }

    public function token(): Token
    {
        if (null === $this->token || $this->token->isExpired(new \DateTimeImmutable('+5 minutes'))) {
            $this->refresh();
        }

        return $this->token;
    }

    public function heldToken(): ?Token
    {
        return $this->token;
    }

    public function refresh(): Token
    {
        return $this->token = $this->grant(['grant_type' => 'client_credentials', 'scope' => implode(' ', $this->scopes)]);
    }

    /**
     * A token from the token endpoint, the client's id and secret added.
     *
     * @param array<string, string> $fields
     */
    public function grant(array $fields): Token
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new InvalidConfigException('The "ubereats" platform needs: client_id, client_secret.');
        }
        $data = $this->send('POST', $this->tokenUrl, ['body' => ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret] + $fields]);
        if (!isset($data['access_token'])) {
            throw new ProviderException(self::PLATFORM, 'No token in Uber\'s answer.');
        }

        return new Token(
            (string) $data['access_token'],
            isset($data['expires_in']) ? new \DateTimeImmutable('+'.(int) $data['expires_in'].' seconds') : null,
            ($data['refresh_token'] ?? '') ?: null,
            isset($data['scope']) ? array_values(array_filter(explode(' ', (string) $data['scope']))) : [],
        );
    }

    public function clientId(): ?string
    {
        return $this->clientId;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function send(string $method, string $url, array $options): array
    {
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PLATFORM, 'Uber could not be reached: '.$e->getMessage(), null, null, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        $data = \is_array($data) ? $data : [];
        if ($status >= 400) {
            $code = isset($data['code']) ? (string) $data['code'] : (\is_string($data['error'] ?? null) ? $data['error'] : null);
            $message = (string) ($data['message'] ?? $data['error_description'] ?? $code ?? \sprintf('HTTP %d.', $status));

            throw 401 === $status || 403 === $status
                ? new UnauthorizedException(self::PLATFORM, $message, $status, $code)
                : new ProviderException(self::PLATFORM, 429 === $status ? 'Too many calls, try again later: '.$message : $message, $status, $code);
        }

        return $data;
    }
}
