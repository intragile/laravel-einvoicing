<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use IntrAgile\EInvoicing\Poland\KsefEnvironment;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\CertificateCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\ContextIdentifier;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\Credentials;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\TokenCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\Crypto\PublicKeyId;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefCursorStore;
use IntrAgile\EInvoicing\Transport\Ksef\KsefConfig;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use IntrAgile\EInvoicing\Transport\Ksef\Testing\SelfSignedCertificate;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Canned KSeF API 2.0 answers, routed by method and path, behind a real KsefTransport.
 *
 * Every route answers in order with the answers queued for it; a request without a queued answer
 * fails the test. The Ministry of Finance encryption keys are self-signed RSA certificates
 * generated once per run. No request leaves the process.
 */
final class FakeKsefApi implements ClientInterface
{
    public const NIP = '5265877635';

    public const TOKEN = '20251121-EC-0A1B2C3D4E-5F6A7B8C9D-AB|nip-5265877635|feedfacecafebeef0123456789abcdef';

    public const SESSION_REF = '20261121-SO-2C3E6C8000-B675CF5D68-07';

    public const INVOICE_REF = '20261121-EE-319D7EE000-B67F415CDC-2C';

    public const SECOND_INVOICE_REF = '20261121-EE-319D7EE000-B67F415CDC-2D';

    public const ORIGINAL_SESSION_REF = '20261121-SO-2F14610000-242991F8C9-B4';

    public const KSEF_NUMBER = '5265877635-20261121-010080DD2B5E-ED';

    public const SECOND_KSEF_NUMBER = '5265877635-20261121-010080DD2B5F-E4';

    /** @var array<int, CertificateCredentials> */
    private static array $mfKeys = [];

    /** @var list<array{string, string, list<ResponseInterface|Throwable|Closure(RequestInterface): ResponseInterface>}> */
    private array $routes = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * Queues answers for requests whose method matches and whose path below /v2 matches the
     * regular expression.
     */
    public function on(string $method, string $pathPattern, ResponseInterface|Throwable|Closure ...$answers): self
    {
        foreach ($this->routes as $index => [$routeMethod, $routePattern]) {
            if ($routeMethod === $method && $routePattern === $pathPattern) {
                $this->routes[$index][2] = [...$this->routes[$index][2], ...$answers];

                return $this;
            }
        }
        $this->routes[] = [$method, $pathPattern, array_values($answers)];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $path = (string) preg_replace('#\A/v2#', '', $request->getUri()->getPath());
        foreach ($this->routes as $index => [$method, $pattern, $answers]) {
            if ($method !== $request->getMethod() || preg_match($pattern, $path) !== 1) {
                continue;
            }
            $answer = array_shift($answers);
            if ($answer === null) {
                break;
            }
            $this->routes[$index][2] = $answers;
            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return $answer instanceof Closure ? $answer($request) : $answer;
        }

        throw new LogicException(sprintf('No canned KSeF answer for %s %s.', $request->getMethod(), $path));
    }

    /**
     * The recorded requests as "METHOD /path".
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(
            static fn (RequestInterface $request): string => $request->getMethod().' '.preg_replace('#\A/v2#', '', $request->getUri()->getPath()),
            $this->requests,
        );
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * A transport over this client, with the given credentials (the KSeF token by default).
     */
    public function transport(?KsefCursorStore $cursor = null, ?Credentials $credentials = null): KsefTransport
    {
        $factory = new HttpFactory;
        $config = new KsefConfig(
            KsefEnvironment::Test,
            ContextIdentifier::nip(self::NIP),
            $credentials ?? new TokenCredentials(self::TOKEN),
            statusPollAttempts: 3,
            statusPollWaitMs: 1,
            sleep: static function (int $ms): void {},
        );

        return KsefTransport::create($config, new HttpClient($this, $factory, $factory, 67_108_864), $cursor);
    }

    /**
     * The key list and a KSeF-token authentication.
     */
    public function withTokenAuthentication(): self
    {
        return $this->withKeys()
            ->on('POST', '#\A/auth/challenge\z#', self::challenge())
            ->on('POST', '#\A/auth/ksef-token\z#', self::json(202, self::authInit()))
            ->on('GET', '#\A/auth/[A-Za-z0-9-]+\z#', self::authSuccess())
            ->on('POST', '#\A/auth/token/redeem\z#', self::redeem());
    }

    /**
     * A XAdES authentication with the configured certificate.
     */
    public function withCertificateAuthentication(): self
    {
        return $this->on('POST', '#\A/auth/challenge\z#', self::challenge())
            ->on('POST', '#\A/auth/xades-signature\z#', self::json(202, self::authInit()))
            ->on('GET', '#\A/auth/[A-Za-z0-9-]+\z#', self::authSuccess())
            ->on('POST', '#\A/auth/token/redeem\z#', self::redeem());
    }

    public function withKeys(): self
    {
        return $this->on('GET', '#\A/security/public-key-certificates\z#', self::json(200, [
            self::keyEntry(0, ['KsefTokenEncryption']),
            self::keyEntry(1, ['SymmetricKeyEncryption']),
        ]));
    }

    /**
     * The opened online session.
     */
    public function withSession(): self
    {
        return $this->on('POST', '#\A/sessions/online\z#', self::json(201, [
            'referenceNumber' => self::SESSION_REF,
            'validUntil' => gmdate('Y-m-d\TH:i:s.0000000+00:00', time() + 43_200),
        ]));
    }

    /**
     * Answers for invoice sends: a response, or an exception such as a lost connection.
     */
    public function onSend(ResponseInterface|Throwable ...$answers): self
    {
        return $this->on('POST', '#\A/sessions/online/[A-Za-z0-9-]+/invoices\z#', ...$answers);
    }

    public static function accepted(string $invoiceRef = self::INVOICE_REF): ResponseInterface
    {
        return self::json(202, ['referenceNumber' => $invoiceRef]);
    }

    public static function connectionLost(string $message = 'Connection reset by peer'): ConnectException
    {
        $factory = new HttpFactory;

        return new ConnectException($message, $factory->createRequest('POST', 'https://api-test.ksef.mf.gov.pl/v2/sessions/online'));
    }

    /**
     * Answers for invoice status reads.
     */
    public function onStatus(ResponseInterface ...$answers): self
    {
        return $this->on('GET', '#\A/sessions/[A-Za-z0-9-]+/invoices/[A-Za-z0-9-]+\z#', ...$answers);
    }

    public function onUpo(ResponseInterface ...$answers): self
    {
        return $this->on('GET', '#\A/sessions/[A-Za-z0-9-]+/invoices/ksef/[A-Za-z0-9-]+/upo\z#', ...$answers);
    }

    /**
     * A SessionInvoiceStatusResponse.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function status(int $code, string $description, array $extra = [], array $statusExtra = []): ResponseInterface
    {
        return self::json(200, [
            'ordinalNumber' => 1,
            'referenceNumber' => self::INVOICE_REF,
            'invoicingDate' => '2026-11-21T10:00:01.0154302+00:00',
            'status' => ['code' => $code, 'description' => $description] + $statusExtra,
        ] + $extra);
    }

    public static function delivered(string $ksefNumber = self::KSEF_NUMBER, string $mode = 'Online'): ResponseInterface
    {
        return self::status(200, 'Sukces', [
            'ksefNumber' => $ksefNumber,
            'acquisitionDate' => '2026-11-21T10:00:02.0154302+00:00',
            'invoicingMode' => $mode,
        ]);
    }

    public static function duplicate(string $originalKsefNumber): ResponseInterface
    {
        return self::status(440, 'Duplikat faktury', [], [
            'details' => ['Duplikat faktury. Faktura o numerze KSeF: '.$originalKsefNumber.' została już prawidłowo przesłana do systemu.'],
            'extensions' => [
                'originalSessionReferenceNumber' => self::ORIGINAL_SESSION_REF,
                'originalKsefNumber' => $originalKsefNumber,
            ],
        ]);
    }

    /**
     * A UPO 4-3 receipt for one invoice, with the document digest of the given file.
     */
    public static function upo(string $ksefNumber = self::KSEF_NUMBER, string $invoiceXml = 'hash'): ResponseInterface
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><Potwierdzenie xmlns="http://upo.schematy.mf.gov.pl/KSeF/v4-3">'
            .'<NazwaPodmiotuPrzyjmujacego>Ministerstwo Finansów - środowisko testowe (TE)</NazwaPodmiotuPrzyjmujacego>'
            .'<NumerReferencyjnySesji>'.self::SESSION_REF.'</NumerReferencyjnySesji>'
            .'<Uwierzytelnienie><IdKontekstu><Nip>'.self::NIP.'</Nip></IdKontekstu></Uwierzytelnienie>'
            .'<Dokument><NipSprzedawcy>'.self::NIP.'</NipSprzedawcy><NumerKSeFDokumentu>'.$ksefNumber.'</NumerKSeFDokumentu>'
            .'<DataNadaniaNumeruKSeF>2026-11-21T10:00:02.014+01:00</DataNadaniaNumeruKSeF><SkrotDokumentu>'.base64_encode(hash('sha256', $invoiceXml, true)).'</SkrotDokumentu></Dokument>'
            .'</Potwierdzenie>';

        return new Response(200, ['Content-Type' => 'application/xml'], $xml);
    }

    /**
     * @param  array<mixed>  $body
     */
    public static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * A Problem Details answer with one KSeF error.
     */
    public static function problem(int $status, int $code, string $description): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/problem+json'], json_encode([
            'title' => 'Error',
            'status' => $status,
            'errors' => [['code' => $code, 'description' => $description, 'details' => []]],
        ], JSON_THROW_ON_ERROR));
    }

    private static function challenge(): ResponseInterface
    {
        return self::json(200, [
            'challenge' => '20261121-CR-226FB7B000-3ACF9BE4C0-10',
            'timestamp' => '2026-11-21T09:59:56.0154302+00:00',
            'timestampMs' => 1_795_255_196_015,
            'clientIp' => '127.0.0.1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function authInit(): array
    {
        return [
            'referenceNumber' => '20261121-AU-2DFC46C000-3AC6D5877F-D4',
            'authenticationToken' => ['token' => 'authentication-token-value', 'validUntil' => gmdate('Y-m-d\TH:i:s+00:00', time() + 900)],
        ];
    }

    private static function authSuccess(): ResponseInterface
    {
        return self::json(200, [
            'startDate' => gmdate('Y-m-d\TH:i:s+00:00'),
            'authenticationMethod' => 'Token',
            'status' => ['code' => 200, 'description' => 'Uwierzytelnianie zakończone sukcesem'],
        ]);
    }

    private static function redeem(): ResponseInterface
    {
        return self::json(200, [
            'accessToken' => ['token' => 'access-token-value-0123456789', 'validUntil' => gmdate('Y-m-d\TH:i:s+00:00', time() + 3_600)],
            'refreshToken' => ['token' => 'refresh-token-value-0123456789', 'validUntil' => gmdate('Y-m-d\TH:i:s+00:00', time() + 86_400)],
        ]);
    }

    /**
     * @param  list<string>  $usage
     * @return array<string, mixed>
     */
    private static function keyEntry(int $index, array $usage): array
    {
        $pem = (self::$mfKeys[$index] ??= SelfSignedCertificate::seal('5265877635', 'Ministry Test Key '.$index))->certificatePem;
        $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        return [
            'certificate' => base64_encode($der),
            'certificateId' => base64_encode(hash('sha256', $der, true)),
            'publicKeyId' => PublicKeyId::of($pem),
            'validFrom' => gmdate('Y-m-d\TH:i:s+00:00', time() - 86_400),
            'validTo' => gmdate('Y-m-d\TH:i:s+00:00', time() + 86_400 * 365),
            'usage' => $usage,
        ];
    }
}
