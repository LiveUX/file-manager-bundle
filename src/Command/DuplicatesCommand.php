<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Service\DuplicateDetector;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:duplicates',
    description: 'Finds duplicate files (identical SHA-256 content) and calculates reclaimable space per profile/volume.',
)]
final class DuplicatesCommand extends Command
{
    public function __construct(
        private readonly ProfileFileScanner $scanner,
        private readonly DuplicateDetector $duplicateDetector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name (scans all profiles if omitted)')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Optional volume name filter')
            ->addOption('min-size', null, InputOption::VALUE_REQUIRED, 'Ignore files smaller than this byte count', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileArg = $input->getArgument('profile');
        $volumeOpt = $input->getOption('volume');
        $minSizeOpt = $input->getOption('min-size');
        $asJson = (bool) $input->getOption('json');

        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;
        $volumeFilter = is_string($volumeOpt) && trim($volumeOpt) !== '' ? trim($volumeOpt) : null;
        $rawMinSize = is_string($minSizeOpt) ? trim($minSizeOpt) : '0';
        if (!ctype_digit($rawMinSize)) {
            $io->error(sprintf('Invalid --min-size value "%s". Expected a non-negative integer byte count.', (string) $minSizeOpt));

            return Command::FAILURE;
        }
        $minSize = (int) $rawMinSize;

        try {
            $profiles = $this->scanner->resolveTargetProfiles($profileName);
        } catch (FileManagerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $report = [];
        $rows = [];
        $totalGroups = 0;
        $totalWastedBytes = 0;
        $matchedVolumes = 0;
        $seenPhysicalVolumes = [];

        foreach ($profiles as $profile) {
            try {
                $volumes = $this->scanner->resolveTargetVolumes($profile, $volumeFilter);
            } catch (FileManagerException $e) {
                if ($profileName === null && $volumeFilter !== null) {
                    continue;
                }
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            foreach ($volumes as $volume) {
                ++$matchedVolumes;
                if ($this->scanner->volumeUsesUserIdToken($profile, $volume->name)) {
                    if (!$asJson) {
                        $io->note(sprintf('Skipping volume "%s" on profile "%s" (uses runtime {user_id} token).', $volume->name, $profile->name));
                    }
                    continue;
                }
                if ($this->scanner->volumeUsesDateTokens($profile, $volume->name) && !$asJson) {
                    $io->note(sprintf('Volume "%s" on profile "%s" uses date tokens ({Y}/{m}/{d}): scanning current period only.', $volume->name, $profile->name));
                }
                $canonicalPath = $this->normalizePhysicalPath($volume->path);
                $endpoint = \LiveUX\FileManagerBundle\Security\PathNormalizer::normalizeS3Endpoint((string) ($volume->driverOptions['endpoint'] ?? ''));
                $volKey = $volume->driver . '|' . $canonicalPath . '|' . $endpoint . '|' . (string) ($volume->driverOptions['bucket'] ?? '') . '|' . (string) ($volume->driverOptions['prefix'] ?? '') . '|' . (string) ($volume->driverOptions['service'] ?? '');
                if ($profileName === null && isset($seenPhysicalVolumes[$volKey])) {
                    continue;
                }
                $seenPhysicalVolumes[$volKey] = true;
                $groups = $this->duplicateDetector->findAllDuplicates($profile, $volume->name, $minSize);
                foreach ($groups as $hash => $entries) {
                    ++$totalGroups;
                    $sizeEach = (int) ($entries[0]['size'] ?? 0);
                    $wasted = $sizeEach * (count($entries) - 1);
                    $totalWastedBytes += $wasted;
                    $paths = array_map(static fn (array $e): string => $e['path'], $entries);

                    $report[] = [
                        'profile' => $profile->name,
                        'volume' => $volume->name,
                        'sha256' => $hash,
                        'count' => count($entries),
                        'sizeEach' => $sizeEach,
                        'reclaimableBytes' => $wasted,
                        'files' => $paths,
                    ];

                    $rows[] = [
                        $profile->name . ':' . $volume->name,
                        substr($hash, 0, 12),
                        count($entries),
                        $this->scanner->formatBytes($wasted),
                        implode("\n", $paths),
                    ];
                }
            }
        }

        if ($volumeFilter !== null && $matchedVolumes === 0) {
            $io->error(sprintf('Volume "%s" is not attached to any matched profile.', $volumeFilter));

            return Command::FAILURE;
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'groups' => $totalGroups,
                'reclaimableBytes' => $totalWastedBytes,
                'reclaimableFormatted' => $this->scanner->formatBytes($totalWastedBytes),
                'duplicates' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title('LiveUX FileManager — Duplicate File Report (SHA-256)');
        if ($rows === []) {
            $io->success('No duplicate files found.');

            return Command::SUCCESS;
        }

        $io->table(['Profile:Volume', 'SHA-256', 'Copies', 'Reclaimable', 'Paths'], $rows);
        $io->warning(sprintf(
            'Found %d duplicate group(s) wasting %s of storage.',
            $totalGroups,
            $this->scanner->formatBytes($totalWastedBytes),
        ));

        return Command::SUCCESS;
    }

    private function normalizePhysicalPath(?string $rawPath): string
    {
        if ($rawPath === null || trim($rawPath) === '') {
            return '';
        }
        $real = @realpath($rawPath);
        $resolved = is_string($real) ? $real : $rawPath;
        $forward = rtrim(str_replace('\\', '/', $resolved), '/');

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($forward, 'UTF-8') : $forward;
    }
}
