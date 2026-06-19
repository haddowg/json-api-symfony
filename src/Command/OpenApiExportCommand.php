<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Command;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports a server's OpenAPI 3.1 document to JSON or YAML — to a file, or to stdout
 * (design §7, D6/D13). The CLI export is **always available** (independent of the
 * HTTP expose gate), so a CI pipeline can spec-diff or publish the document with no
 * web exposure.
 *
 * `--format=yaml` requires `symfony/yaml` (a suggested dependency); without it the
 * command fails with a clear message rather than emitting broken output.
 */
#[AsCommand(
    name: 'json-api:openapi:export',
    description: 'Export a server\'s OpenAPI 3.1 document (JSON or YAML) to a file or stdout.',
)]
final class OpenApiExportCommand extends Command
{
    public function __construct(private readonly DocumentFactory $documents)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'The JSON:API server name to export.', ServerProvider::DEFAULT_SERVER)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: json or yaml.', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverOption = $input->getOption('server');
        $server = \is_string($serverOption) ? $serverOption : ServerProvider::DEFAULT_SERVER;
        $formatOption = $input->getOption('format');
        $format = \strtolower(\is_string($formatOption) ? $formatOption : 'json');
        $outputFile = $input->getOption('output');
        $outputFile = \is_string($outputFile) ? $outputFile : null;

        if (!\in_array($format, ['json', 'yaml', 'yml'], true)) {
            $io->error(\sprintf('Unsupported format "%s" (use json or yaml).', $format));

            return Command::INVALID;
        }

        $isYaml = $format === 'yaml' || $format === 'yml';
        if ($isYaml && !\class_exists(Yaml::class)) {
            $io->error('YAML export requires symfony/yaml; install it (composer require symfony/yaml) or export JSON.');

            return Command::FAILURE;
        }

        try {
            $document = $this->documents->forServer($server);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Could not build the OpenAPI document for server "%s": %s', $server, $e->getMessage()));

            return Command::FAILURE;
        }

        $rendered = $isYaml
            ? Yaml::dump($document->toArray(), 16, 2, Yaml::DUMP_OBJECT_AS_MAP)
            : $document->toJsonString(true) . "\n";

        if ($outputFile === null) {
            // Write straight to the output stream (no SymfonyStyle decoration) so a
            // piped/redirected document is byte-clean.
            $output->write($rendered);

            return Command::SUCCESS;
        }

        if (\file_put_contents($outputFile, $rendered) === false) {
            $io->error(\sprintf('Could not write to "%s".', $outputFile));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote the OpenAPI document for server "%s" to %s.', $server, $outputFile));

        return Command::SUCCESS;
    }
}
