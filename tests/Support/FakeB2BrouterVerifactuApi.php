<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuQr;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordReader;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\VerifactuPolling;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\Ksef\Qr\ChillerlanQrRenderer;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A PSR-18 stand-in for the B2Brouter Spanish invoicing API, behind a real B2BrouterVerifactu.
 *
 * It serves the invoice import, GET /invoices/{id}, GET /tax_reports/{id} and the issued invoice
 * list from the copied sandbox shapes in tests/fixtures/verifactu/b2brouter/api/. A tax report
 * carries a record fixture as xml_base64 (in B2Brouter's 60-character Base64 layout), the record's
 * Huella as fingerprint and, as qr, a PNG of VerifactuQr::url() with the production host, the QR
 * B2Brouter prints. Each read of a report answers its next state; the last state repeats.
 * Every request is recorded. No request leaves the process.
 */
final class FakeB2BrouterVerifactuApi implements ClientInterface
{
    public const DIR = __DIR__.'/../fixtures/verifactu/b2brouter/api';

    public const ACCOUNT = 999999;

    public const BASE_URL = 'https://api.b2brouter.net';

    /** @var list<ResponseInterface|Throwable|string> invoice ids, or answers, for the next imports */
    private array $imports = [];

    /** @var array<string, array{number: string, reports: list<string>, listed: bool}> */
    private array $invoices = [];

    /** @var array<string, array{states: list<string>, reads: int, xml: string, fingerprint: ?string, qr: ?string, errors: list<string>}> */
    private array $reports = [];

    /** @var list<string> "METHOD path?query" of every request */
    private array $requests = [];

    /** @var list<string> bodies of the import requests */
    private array $imported = [];

    /**
     * A real client that sends through this fake, polling without waiting.
     */
    public function client(?VerifactuPolling $polling = null, string $apiKey = 'test_key'): B2BrouterVerifactu
    {
        $factory = new HttpFactory;

        return new B2BrouterVerifactu(
            new B2BrouterConfig($apiKey, self::ACCOUNT, self::BASE_URL),
            new HttpClient($this, $factory, $factory),
            $polling ?? self::polling(3),
        );
    }

    /** A polling bound of the given reads that never sleeps. */
    public static function polling(int $attempts): VerifactuPolling
    {
        return new VerifactuPolling($attempts, 1, static function (int $ms): void {});
    }

    /**
     * Registers an invoice B2Brouter holds, with its tax report ids.
     */
    public function invoice(string $id, string $number, string ...$reportIds): self
    {
        $this->invoices[$id] = ['number' => $number, 'reports' => array_values($reportIds), 'listed' => true];

        return $this;
    }

    /**
     * Registers an invoice that B2Brouter holds only once an import answered with its id: until
     * then the invoice list does not show it.
     */
    public function importable(string $id, string $number, string ...$reportIds): self
    {
        $this->invoices[$id] = ['number' => $number, 'reports' => array_values($reportIds), 'listed' => false];

        return $this;
    }

    /**
     * Registers a tax report carrying the given record XML, answering the given states in turn.
     *
     * @param  list<string>  $states
     * @param  string|null  $fingerprint  the report's fingerprint; the record's Huella when null
     * @param  bool  $qr  whether the report carries the QR image
     * @param  list<string>  $errors  the report's error messages
     */
    public function report(string $id, string $xml, array $states = ['registered'], ?string $fingerprint = null, bool $qr = true, array $errors = []): self
    {
        $this->reports[$id] = [
            'states' => $states,
            'reads' => 0,
            'xml' => $xml,
            'fingerprint' => $fingerprint ?? self::huella($xml),
            'qr' => $qr ? self::qr($xml) : null,
            'errors' => $errors,
        ];

        return $this;
    }

    /**
     * Queues the answers to the next imports: an invoice id registered with invoice() for a 201
     * answer, or a response or an exception as given.
     */
    public function onImport(ResponseInterface|Throwable|string ...$answers): self
    {
        array_push($this->imports, ...$answers);

        return $this;
    }

    /**
     * @return list<string> the recorded requests whose "METHOD path" starts with the prefix
     */
    public function requests(string $prefix = ''): array
    {
        return array_values(array_filter($this->requests, static fn (string $request): bool => str_starts_with($request, $prefix)));
    }

    /**
     * @return list<string> the bodies of the import requests
     */
    public function imported(): array
    {
        return $this->imported;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $this->requests[] = $request->getMethod().' '.$path.($uri->getQuery() === '' ? '' : '?'.$uri->getQuery());

        if ($request->getMethod() === 'POST' && $path === '/accounts/'.self::ACCOUNT.'/invoices/import') {
            $this->imported[] = (string) $request->getBody();
            $answer = array_shift($this->imports);
            Assert::assertNotNull($answer, 'Unexpected B2Brouter import: no answer queued.');
            if ($answer instanceof Throwable) {
                throw $answer;
            }

            if ($answer instanceof ResponseInterface) {
                return $answer;
            }
            $body = $this->invoiceBody('import-201.json', $answer);
            $this->invoices[$answer]['listed'] = true;

            return self::json(201, $body);
        }
        if ($request->getMethod() !== 'GET') {
            Assert::fail('Unexpected B2Brouter request: '.$request->getMethod().' '.$path);
        }
        if (preg_match('#^/invoices/([0-9]+)$#', $path, $match) === 1) {
            return isset($this->invoices[$match[1]])
                ? self::json(200, $this->invoiceBody('invoice.json', $match[1]))
                : self::json(404, ['error' => ['code' => 'resource_missing', 'message' => 'Not found']]);
        }
        if (preg_match('#^/tax_reports/([0-9]+)$#', $path, $match) === 1) {
            return isset($this->reports[$match[1]])
                ? self::json(200, $this->reportBody($match[1]))
                : self::json(404, ['error' => ['code' => 'resource_missing', 'message' => 'Not found']]);
        }
        if ($path === '/accounts/'.self::ACCOUNT.'/invoices') {
            parse_str($uri->getQuery(), $query);
            $number = is_string($query['number'] ?? null) ? $query['number'] : '';
            $listed = [];
            foreach ($this->invoices as $id => $invoice) {
                if ($invoice['listed'] && str_starts_with($invoice['number'], $number)) {
                    $listed[] = ['id' => (int) $id, 'number' => $invoice['number'], 'state' => 'sent'];
                }
            }

            return self::json(200, ['invoices' => $listed, 'meta' => ['total_count' => count($listed)]]);
        }

        Assert::fail('Unexpected B2Brouter request: GET '.$path);
    }

    /**
     * @return array<mixed>
     */
    private function invoiceBody(string $file, string $id): array
    {
        Assert::assertArrayHasKey($id, $this->invoices, 'Unknown fake B2Brouter invoice '.$id);
        $body = self::template($file);
        Assert::assertIsArray($body['invoice'] ?? null);
        $body['invoice']['id'] = (int) $id;
        $body['invoice']['number'] = $this->invoices[$id]['number'];
        $body['invoice']['tax_report_ids'] = array_map(intval(...), $this->invoices[$id]['reports']);

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function reportBody(string $id): array
    {
        $report = &$this->reports[$id];
        $state = $report['states'][min($report['reads'], count($report['states']) - 1)];
        $report['reads']++;

        $body = self::template('tax-report.json');
        Assert::assertIsArray($body['tax_report'] ?? null);
        $body['tax_report']['id'] = (int) $id;
        $body['tax_report']['state'] = $state;
        $body['tax_report']['has_errors'] = $report['errors'] !== [];
        $body['tax_report']['errors'] = array_map(static fn (string $message): array => ['message' => $message], $report['errors']);
        $body['tax_report']['fingerprint'] = $report['fingerprint'];
        // An opaque id of another id space, as B2Brouter sends it.
        $body['tax_report']['previous_id'] = 290000 + ((int) $id % 1000);
        $body['tax_report']['xml_base64'] = rtrim(chunk_split(base64_encode($report['xml']), 60, "\n"), "\n");
        $body['tax_report']['qr'] = $report['qr'] === null ? null : base64_encode($report['qr']);

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private static function template(string $file): array
    {
        $contents = file_get_contents(self::DIR.'/'.$file);
        Assert::assertIsString($contents);
        $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($body);

        return $body;
    }

    /**
     * @param  array<mixed>  $body
     */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** The Huella of the record (the last one in the document), or null when it has none. */
    private static function huella(string $xml): ?string
    {
        return preg_match_all('#<sum1:Huella>([0-9A-F]{64})</sum1:Huella>#', $xml, $match) > 0 ? end($match[1]) : null;
    }

    /** A PNG of the record's ValidarQR URL with the production host, or null for an unreadable record. */
    private static function qr(string $xml): ?string
    {
        try {
            $record = (new RecordReader)->read($xml);
        } catch (Throwable) {
            return null;
        }

        return $record instanceof RegistrationRecord
            ? (new ChillerlanQrRenderer)->png(VerifactuQr::code($record, VerifactuEnvironment::Production))
            : null;
    }
}
