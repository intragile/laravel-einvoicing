<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Every fenced php block of the READMEs must compile (php -l). The samples need a Laravel
 * application, so they are checked for syntax only, not run.
 */
final class CodeSamplesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private const BUILD_DIR = self::ROOT.'/build/docs-samples';

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function samples(): iterable
    {
        foreach (glob(self::ROOT.'/README*.md') ?: [] as $path) {
            $document = basename($path);
            foreach (self::extract($path) as [$line, $code]) {
                yield "{$document}:{$line}" => [$document, $line, $code];
            }
        }
    }

    #[DataProvider('samples')]
    public function test_sample_compiles(string $document, int $line, string $code): void
    {
        if (! is_dir(self::BUILD_DIR) && ! mkdir(self::BUILD_DIR, 0777, true) && ! is_dir(self::BUILD_DIR)) {
            throw new RuntimeException('Cannot create '.self::BUILD_DIR.'.');
        }
        $file = self::BUILD_DIR.'/'.preg_replace('/[^A-Za-z0-9]+/', '-', $document).'-'.$line.'.php';
        file_put_contents($file, str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n".$code);

        $process = new Process([PHP_BINARY, '-l', $file], timeout: 60);
        $process->run();

        self::assertSame(0, $process->getExitCode(), "{$document}:{$line} does not compile:\n".$process->getOutput().$process->getErrorOutput());
    }

    public function test_the_readmes_have_php_samples(): void
    {
        self::assertNotEmpty(iterator_to_array(self::samples()));
    }

    /**
     * The fenced php blocks of a document: the line number of the opening fence and the code.
     *
     * @return list<array{int, string}>
     */
    private static function extract(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }
        $lines = explode("\n", $content);
        $count = count($lines);
        $samples = [];
        for ($index = 0; $index < $count; $index++) {
            if (trim($lines[$index]) !== '```php') {
                continue;
            }
            $end = $index + 1;
            while ($end < $count && trim($lines[$end]) !== '```') {
                $end++;
            }
            if ($end === $count) {
                throw new RuntimeException(basename($path).':'.($index + 1).' has an unterminated php block.');
            }
            $samples[] = [$index + 1, implode("\n", array_slice($lines, $index + 1, $end - $index - 1))."\n"];
            $index = $end;
        }

        return $samples;
    }
}
