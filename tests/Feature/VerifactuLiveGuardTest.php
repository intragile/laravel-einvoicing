<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use IntrAgile\EInvoicing\Laravel\Tests\Live\B2BrouterVerifactuLiveTest;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Spanish live test reaches only the B2Brouter sandbox, and the default suite never runs it.
 */
final class VerifactuLiveGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedConfigurations(): iterable
    {
        yield 'production key on the sandbox host' => ['live_'.self::SECRET_MARK, B2BrouterConfig::DEFAULT_BASE_URL];
        yield 'staging key on the staging host' => ['stag_'.self::SECRET_MARK, B2BrouterConfig::STAGING_BASE_URL];
        yield 'sandbox key on the staging host' => ['test_'.self::SECRET_MARK, B2BrouterConfig::STAGING_BASE_URL];
        yield 'sandbox key on another host' => ['test_'.self::SECRET_MARK, 'https://example.com'];
    }

    #[DataProvider('refusedConfigurations')]
    public function test_live_test_refuses_anything_but_a_sandbox_key_on_the_sandbox_host(string $key, string $baseUrl): void
    {
        $refusal = B2BrouterVerifactuLiveTest::refusal(new B2BrouterConfig($key, '999999', $baseUrl));

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('test_', $refusal);
        $this->assertStringNotContainsString(self::SECRET_MARK, $refusal);
        $this->assertStringNotContainsString('999999', $refusal);
    }

    public function test_live_test_accepts_a_sandbox_key_on_the_sandbox_host(): void
    {
        $this->assertNull(B2BrouterVerifactuLiveTest::refusal(new B2BrouterConfig('test_'.self::SECRET_MARK, '999999')));
    }

    public function test_default_suite_excludes_the_live_group(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
        $this->assertNotFalse($xml);

        $excluded = array_map('strval', $xml->xpath('/phpunit/groups/exclude/group') ?: []);

        $this->assertContains('b2brouter-spain-live', $excluded);
    }
}
