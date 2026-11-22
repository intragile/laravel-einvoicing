<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use GuzzleHttp\Client;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The PSR-18 client of the bridge's KSeF live test: a Guzzle client with fixed timeouts that
 * refuses every KSeF host except the KSeF TE API and every scheme but https, and counts the
 * requests it sends, by host and by method and path.
 *
 * It keeps no bodies, so no token or key material is held beyond the request itself.
 */
final class KsefLiveTap implements ClientInterface
{
    /** The only KSeF host the live test reaches: the KSeF TE API. */
    public const HOST = 'api-test.ksef.mf.gov.pl';

    public const BASE_URL = 'https://api-test.ksef.mf.gov.pl/v2';

    /** The method and path (under /v2) of a metadata query, which KSeF limits to 20 per hour. */
    public const METADATA_QUERY = 'POST /invoices/query/metadata';

    private readonly Client $client;

    /** @var array<string, int> requests sent, by host */
    private array $hosts = [];

    /** @var list<string> "METHOD /path" of every request to the TE API, in order */
    private array $calls = [];

    public function __construct(float $timeoutSeconds = 30.0, float $connectTimeoutSeconds = 10.0)
    {
        $this->client = new Client([
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $connectTimeoutSeconds,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $refusal = self::refusal($uri->getScheme(), $uri->getHost());
        if ($refusal !== null) {
            throw new LogicException($refusal);
        }
        $host = strtolower($uri->getHost());
        $this->hosts[$host] = ($this->hosts[$host] ?? 0) + 1;
        if ($host === self::HOST) {
            $this->calls[] = $request->getMethod().' '.substr($uri->getPath(), strlen('/v2'));
        }

        return $this->client->sendRequest($request);
    }

    /**
     * Why a request to the scheme and host is refused, or null when it may be sent: every
     * *.ksef.mf.gov.pl host but the TE API (DEMO, PROD, the QR pages and Latarnia included) and
     * every scheme but https. Other hosts, such as storage URLs the TE API returns, are allowed.
     */
    public static function refusal(string $scheme, string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        if (str_ends_with($host, 'ksef.mf.gov.pl') && $host !== self::HOST) {
            return sprintf('The KSeF live test reaches only the KSeF TE API; a request to %s was refused.', $host);
        }
        if (strtolower($scheme) !== 'https') {
            return 'The KSeF live test uses https only.';
        }

        return null;
    }

    /**
     * The number of requests sent to the TE API with the method and path, such as METADATA_QUERY.
     */
    public function countOf(string $call): int
    {
        return count(array_keys($this->calls, $call, true));
    }

    /**
     * The number of requests sent to the TE API.
     */
    public function count(): int
    {
        return count($this->calls);
    }

    /**
     * The number of requests sent to hosts other than the TE API.
     */
    public function otherHosts(): int
    {
        return array_sum($this->hosts) - ($this->hosts[self::HOST] ?? 0);
    }
}
