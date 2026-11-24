<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use ErrorException;

/**
 * Error handler that turns a deprecation caused by the package's own code into an exception.
 *
 * Once the test application is bootstrapped, Laravel's error handler replaces PHPUnit's and sends
 * deprecations to the "deprecations" log channel, so failOnDeprecation in phpunit.xml never sees
 * them. This handler sits in front of Laravel's: a deprecation raised in src/, or raised by a
 * library function that src/ called directly, throws; everything else, such as a deprecation
 * between framework classes, is passed on to Laravel unchanged.
 */
final class FirstPartyDeprecations
{
    /** @var (callable(int, string, string, int): mixed)|null */
    private $previous;

    private function __construct(
        ?callable $previous,
        private readonly string $sourceDir,
    ) {
        $this->previous = $previous;
    }

    /**
     * Puts the handler in front of the error handler that is active now.
     */
    public static function install(): void
    {
        $previous = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        set_error_handler(new self($previous, self::sourceDir()));
    }

    /**
     * The handler that is active now, if it is this one.
     */
    public static function active(): ?self
    {
        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $current instanceof self ? $current : null;
    }

    public static function sourceDir(): string
    {
        return (string) realpath(dirname(__DIR__, 2).'/src').DIRECTORY_SEPARATOR;
    }

    /**
     * @param  list<array{file?: string, function?: string}>|null  $trace  the call stack; read from the engine when null
     *
     * @throws ErrorException for a deprecation caused by the package's own code
     */
    public function __invoke(int $level, string $message, string $file = '', int $line = 0, ?array $trace = null): bool
    {
        if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0
            && $this->causedByFirstParty($level, $file, $trace)) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }

        if ($this->previous === null) {
            return false;
        }

        // Only an explicit false lets PHP's own handler run; Laravel's handler returns nothing.
        return ($this->previous)($level, $message, $file, $line) !== false;
    }

    /**
     * @param  list<array{file?: string, function?: string}>|null  $trace
     */
    private function causedByFirstParty(int $level, string $file, ?array $trace): bool
    {
        if ($this->isFirstParty($file)) {
            return true;
        }
        if ($level !== E_USER_DEPRECATED) {
            return false;
        }

        // A library reports its own deprecations with trigger_error(), often through Symfony's
        // trigger_deprecation(); the call site of the library function tells whether src/ used
        // the deprecated API.
        $trace ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($trace as $index => $frame) {
            if (($frame['function'] ?? null) === 'trigger_error') {
                $caller = $index + 1;
                if (($trace[$caller]['function'] ?? null) === 'trigger_deprecation') {
                    $caller++;
                }

                return $this->isFirstParty($trace[$caller]['file'] ?? '');
            }
        }

        return false;
    }

    private function isFirstParty(string $file): bool
    {
        return $file !== '' && str_starts_with((string) realpath($file), $this->sourceDir);
    }
}
