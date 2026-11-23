<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Validation\Exception\RuleSourceMismatch;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use Throwable;

/**
 * Installs the AEAT VERI*FACTU schemas, WSDL and error catalogue into the configured folder.
 *
 * AEAT publishes no licence that allows redistribution, so the files are not shipped: the pinned
 * files are downloaded, or read from a local folder for machines without internet access, and
 * checked against their checksums. The installed files stay where they are unless every file
 * matches.
 */
final class VerifactuInstallSchemasCommand extends Command
{
    protected $signature = 'einvoicing:verifactu-install-schemas
        {--from= : Folder holding the eight schema files by name; downloads the pinned files when omitted}';

    protected $description = 'Install the AEAT VERI*FACTU schemas, WSDL and error catalogue';

    public function handle(VerifactuManager $manager): int
    {
        $from = $this->option('from');
        $from = is_string($from) && $from !== '' ? $from : null;

        try {
            $target = $manager->settings()->schemasDir;
        } catch (ConfigurationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Installing the AEAT VERI*FACTU schemas into %s from %s.',
            $target,
            $from ?? 'the pinned download',
        ));

        try {
            $written = VerifactuSchemas::install($target, $from);
        } catch (RuleSourceMismatch $exception) {
            $this->components->error('Nothing was installed: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($written as $path) {
            $this->line('  '.$path);
        }
        $this->components->info(sprintf('%d files written.', count($written)));
        $this->line('Downloaded for this machine only: AEAT publishes no licence for these files, do not redistribute them.');

        return self::SUCCESS;
    }
}
