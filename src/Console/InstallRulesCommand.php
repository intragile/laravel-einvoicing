<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Validation\Peppol\RuleInstaller;
use Throwable;

/**
 * Compiles the official Peppol BIS Billing rules into the configured rules folder.
 *
 * OpenPeppol publishes no licence for its rules, so they are not shipped: the pinned sources are
 * downloaded, or read from a local folder for machines without internet access, checked against
 * their checksums and compiled on this machine. The installed rules stay where they are unless
 * every source compiles.
 */
final class InstallRulesCommand extends Command
{
    protected $signature = 'einvoicing:install-rules
        {--from= : Directory with the downloaded Peppol sources; downloads the pinned files when omitted}';

    protected $description = 'Install the Peppol BIS Billing validation rules';

    public function handle(EInvoicingManager $manager): int
    {
        $from = $this->option('from');
        $from = is_string($from) && $from !== '' ? $from : null;
        $target = $manager->rulesDir();

        $this->components->info(sprintf(
            'Installing the Peppol rules into %s from %s.',
            $target,
            $from ?? 'the pinned download',
        ));

        try {
            $written = (new RuleInstaller)->install($target, $from);
        } catch (Throwable $exception) {
            $this->components->error($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($written as $path) {
            $this->line('  '.$path);
        }
        $this->components->info(sprintf('%d rule files written.', count($written)));

        return self::SUCCESS;
    }
}
