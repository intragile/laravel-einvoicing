<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use IntrAgile\EInvoicing\Spain\CertificateKind;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\Verifactu\VerifactuFormat;
use IntrAgile\EInvoicing\Transport\Aeat\AeatClient;
use IntrAgile\EInvoicing\Transport\Aeat\AeatConfig;
use IntrAgile\EInvoicing\Transport\Aeat\AeatFormat;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use LogicException;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A PSR-18 stand-in for the AEAT VERI*FACTU SOAP service, behind a real AeatClient.
 *
 * Answers are given in order from a queue; a request without a queued answer fails the test. Each
 * answer body is built from the copied core fixtures in tests/fixtures/verifactu/aeat/, with the
 * IDFactura, the operation and the error code of every line substituted. Request bodies are
 * recorded. No request leaves the process.
 */
final class FakeAeatApi implements ClientInterface
{
    public const DIR = __DIR__.'/../fixtures/verifactu/aeat';

    public const CSV = 'A1B2C3D4E5F6G7H8';

    /** Line kinds and the fixture line each is built from: [file, index of the RespuestaLinea]. */
    private const TEMPLATES = [
        'correcto' => ['correcto.xml', 0],
        'aceptado_con_errores' => ['parcialmente-correcto.xml', 0],
        'incorrecto' => ['parcialmente-correcto.xml', 1],
        'duplicado_correcta' => ['duplicado-correcta.xml', 0],
        // The Correcta duplicate line with EstadoRegistroDuplicado AceptadaConErrores.
        'duplicado_aceptada_con_errores' => ['duplicado-correcta.xml', 0],
        'duplicado_anulada' => ['duplicado-anulada.xml', 0],
    ];

    /** @var list<ResponseInterface|Throwable|Closure(RequestInterface): (ResponseInterface|Throwable)> */
    private array $answers = [];

    /** @var list<string> */
    private array $bodies = [];

    /**
     * A real AeatClient that sends through this fake.
     */
    public function client(VerifactuSchemas $schemas, VerifactuEnvironment $environment = VerifactuEnvironment::Test): AeatClient
    {
        $factory = new HttpFactory;

        return new AeatClient(new AeatConfig($environment, CertificateKind::Personal, $schemas), new HttpClient($this, $factory, $factory));
    }

    /**
     * @param  ResponseInterface|Throwable|Closure(RequestInterface): (ResponseInterface|Throwable)  ...$answers
     */
    public function queue(ResponseInterface|Throwable|Closure ...$answers): self
    {
        array_push($this->answers, ...$answers);

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->bodies[] = (string) $request->getBody();
        $answer = array_shift($this->answers);
        if ($answer === null) {
            Assert::fail('The fake AEAT service got a request without a queued answer.');
        }
        if ($answer instanceof Closure) {
            $answer = $answer($request);
        }
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function requestCount(): int
    {
        return count($this->bodies);
    }

    public function pending(): int
    {
        return count($this->answers);
    }

    public function requestBody(int $index): string
    {
        return $this->bodies[$index] ?? throw new LogicException(sprintf('No request %d was recorded.', $index));
    }

    /**
     * The RegistroAlta and RegistroAnulacion elements a recorded request carried, in order.
     *
     * @return list<DOMElement>
     */
    public function sentRecords(int $index): array
    {
        $document = new DOMDocument;
        $document->loadXML($this->requestBody($index));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('sf', VerifactuFormat::SF_NS);
        $records = [];
        foreach ($xpath->query('//sf:RegistroAlta | //sf:RegistroAnulacion') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $records[] = $node;
            }
        }

        return $records;
    }

    /**
     * The NumSerieFactura of each record a recorded request carried, in order.
     *
     * @return list<string>
     */
    public function sentNumbers(int $index): array
    {
        return array_map(static function (DOMElement $record): string {
            $xpath = new DOMXPath($record->ownerDocument ?? new DOMDocument);
            $xpath->registerNamespace('sf', VerifactuFormat::SF_NS);

            return trim((string) $xpath->evaluate('string(sf:IDFactura/*[local-name()="NumSerieFactura" or local-name()="NumSerieFacturaAnulada"])', $record));
        }, $this->sentRecords($index));
    }

    /** The ObligadoEmision NIF of the header of a recorded request. */
    public function sentIssuer(int $index): string
    {
        $document = new DOMDocument;
        $document->loadXML($this->requestBody($index));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('sf', VerifactuFormat::SF_NS);

        return trim((string) $xpath->evaluate('string(//sf:ObligadoEmision/sf:NIF)'));
    }

    /**
     * One answer line: the kind names the fixture line it is built from ("correcto",
     * "aceptado_con_errores", "incorrecto", "duplicado_correcta", "duplicado_aceptada_con_errores"
     * or "duplicado_anulada").
     *
     * @return array{InvoiceId, string, string, ?int}
     */
    public static function line(InvoiceId $invoice, string $kind = 'correcto', string $operation = 'Alta', ?int $code = null): array
    {
        if (! isset(self::TEMPLATES[$kind])) {
            throw new LogicException(sprintf('Unknown line kind "%s".', $kind));
        }

        return [$invoice, $kind, $operation, $code];
    }

    /**
     * An HTTP 200 submission response with the given lines.
     *
     * @param  list<array{InvoiceId, string, string, ?int}>  $lines
     */
    public static function answer(array $lines, int $wait = 60, ?string $csv = self::CSV): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], self::body($lines, $wait, $csv));
    }

    /**
     * The SOAP body of a submission response with the given lines. The envelope follows the
     * fixture of the overall state: correcto.xml when every line is Correcto, incorrecto.xml when
     * none is registered, parcialmente-correcto.xml otherwise.
     *
     * @param  list<array{InvoiceId, string, string, ?int}>  $lines
     */
    public static function body(array $lines, int $wait = 60, ?string $csv = self::CSV): string
    {
        $kinds = array_map(static fn (array $line): string => $line[1], $lines);
        $registered = array_intersect($kinds, ['correcto', 'aceptado_con_errores']);
        $base = match (true) {
            $kinds !== [] && count(array_intersect($kinds, ['correcto'])) === count($kinds) => 'correcto.xml',
            $registered === [] => 'incorrecto.xml',
            default => 'parcialmente-correcto.xml',
        };

        $document = self::load($base);
        $xpath = self::xpath($document);
        $payload = self::element($xpath, '//r:RespuestaRegFactuSistemaFacturacion');
        foreach (self::elements($xpath, 'r:RespuestaLinea', $payload) as $old) {
            $payload->removeChild($old);
        }
        self::element($xpath, 'r:TiempoEsperaEnvio', $payload)->textContent = (string) $wait;
        $csvElement = self::elements($xpath, 'r:CSV', $payload)[0] ?? null;
        if ($csvElement !== null) {
            if ($csv === null) {
                $payload->removeChild($csvElement);
                foreach (self::elements($xpath, 'r:DatosPresentacion', $payload) as $presentation) {
                    $payload->removeChild($presentation);
                }
            } else {
                $csvElement->textContent = $csv;
            }
        }
        if ($lines !== []) {
            self::element($xpath, 'r:Cabecera/sf:ObligadoEmision/sf:NIF', $payload)->textContent = $lines[0][0]->issuer->value;
        }

        foreach ($lines as [$invoice, $kind, $operation, $code]) {
            [$file, $index] = self::TEMPLATES[$kind];
            $template = self::load($file);
            $templateXpath = self::xpath($template);
            $line = self::elements($templateXpath, '//r:RespuestaLinea')[$index];
            self::element($templateXpath, 'r:IDFactura/sf:IDEmisorFactura', $line)->textContent = $invoice->issuer->value;
            self::element($templateXpath, 'r:IDFactura/sf:NumSerieFactura', $line)->textContent = $invoice->number;
            self::element($templateXpath, 'r:IDFactura/sf:FechaExpedicionFactura', $line)->textContent = $invoice->dateText();
            self::element($templateXpath, 'r:Operacion/sf:TipoOperacion', $line)->textContent = $operation;
            if ($code !== null) {
                self::element($templateXpath, 'r:CodigoErrorRegistro', $line)->textContent = (string) $code;
            }
            if ($kind === 'duplicado_aceptada_con_errores') {
                self::element($templateXpath, 'r:RegistroDuplicado/sf:EstadoRegistroDuplicado', $line)->textContent = 'AceptadaConErrores';
            }
            $imported = $document->importNode($line, true);
            $payload->appendChild($imported);
        }

        return (string) $document->saveXML();
    }

    /**
     * An HTTP 500 SOAP Client fault with the given AEAT code, built from fault-client-4104.xml.
     */
    public static function clientFault(int $code): ResponseInterface
    {
        $body = str_replace('Codigo[4104]', sprintf('Codigo[%d]', $code), (string) file_get_contents(self::DIR.'/fault-client-4104.xml'));

        return new Response(500, ['Content-Type' => 'text/xml; charset=utf-8'], $body);
    }

    /** The fixture fault-client-4112.xml (certificate holder not authorised), HTTP 500. */
    public static function authenticationFault(): ResponseInterface
    {
        return new Response(500, ['Content-Type' => 'text/xml; charset=utf-8'], (string) file_get_contents(self::DIR.'/fault-client-4112.xml'));
    }

    /** The fixture fault-server.xml, HTTP 500. */
    public static function serverFault(): ResponseInterface
    {
        return new Response(500, ['Content-Type' => 'text/xml; charset=utf-8'], (string) file_get_contents(self::DIR.'/fault-server.xml'));
    }

    /** HTTP 429 with the proxy page html-502.html as the body. */
    public static function rateLimited(?int $retryAfter): ResponseInterface
    {
        $headers = ['Content-Type' => 'text/html'];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return new Response(429, $headers, (string) file_get_contents(self::DIR.'/html-502.html'));
    }

    /** A request that got no answer: the outcome of a submission is unknown. */
    public static function noAnswer(RequestInterface $request): Throwable
    {
        return new ConnectException('Connection timed out', $request);
    }

    private static function load(string $file): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        Assert::assertTrue($document->loadXML((string) file_get_contents(self::DIR.'/'.$file)), $file);

        return $document;
    }

    private static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', AeatFormat::RESPONSE_NS);
        $xpath->registerNamespace('sf', VerifactuFormat::SF_NS);

        return $xpath;
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $expression, ?DOMElement $context = null): array
    {
        $found = [];
        foreach ($xpath->query($expression, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private static function element(DOMXPath $xpath, string $expression, ?DOMElement $context = null): DOMElement
    {
        return self::elements($xpath, $expression, $context)[0] ?? throw new LogicException(sprintf('No element %s in the fixture.', $expression));
    }
}
