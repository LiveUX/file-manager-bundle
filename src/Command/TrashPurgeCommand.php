<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\TrashManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:trash:purge',
    description: 'Permanently purges expired items from FileManager trash bins globally or for a specific profile/volume.',
)]
final class TrashPurgeCommand extends Command
{
    public function __construct(
        private readonly TrashManager $trashManager,
        private readonly ProfileFileScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name (processes all profiles if omitted)')
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Optional volume name filter')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override retention days (e.g. 0 to purge all)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview how many expired trash items would be purged without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileArg = $input->getArgument('profile');
        $volumeOpt = $input->getOption('volume');
        $daysOpt = $input->getOption('days');
        $dryRun = (bool) $input->getOption('dry-run');

        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;
        $volumeFilter = is_string($volumeOpt) && trim($volumeOpt) !== '' ? trim($volumeOpt) : null;
        $overrideDays = null;
        if ($daysOpt !== null) {
            $rawDays = is_string($daysOpt) ? trim($daysOpt) : '';
            if (!ctype_digit($rawDays)) {
                $io->error(sprintf('Invalid --days value "%s". Expected a non-negative integer.', (string) $daysOpt));

                return Command::FAILURE;
            }
            $overrideDays = (int) $rawDays;
        }

        try {
            $profiles = $this->scanner->resolveTargetProfiles($profileName);
        } catch (FileManagerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $totalPurged = 0;
        $matchedVolumes = 0;
        /** @var array<string, array{profile: \LiveUX\FileManagerBundle\Config\ProfileConfig, volumeName: string, retentionDays: int}> $targets */
        $targets = [];

        foreach ($profiles as $profile) {
            if (!$this->trashManager->isEnabled($profile)) {
                continue;
            }

            try {
                $volumes = $this->scanner->resolveTargetVolumes($profile, $volumeFilter);
            } catch (FileManagerException $e) {
                if ($profileName === null && $volumeFilter !== null) {
                    continue;
                }
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $trashDir = trim((string) ($profile->trash['directory'] ?? '.trash'), '/\\');
            $retentionDays = max(1, (int) ($profile->trash['retention_days'] ?? 30));
            foreach ($volumes as $volume) {
                ++$matchedVolumes;
                if ($this->scanner->volumeUsesUserIdToken($profile, $volume->name)) {
                    $io->note(sprintf('Skipping volume "%s" on profile "%s" (uses runtime {user_id} token).', $volume->name, $profile->name));
                    continue;
                }
                if ($this->scanner->volumeUsesDateTokens($profile, $volume->name)) {
                    $io->warning(sprintf(
                        'Volume "%s" on profile "%s" uses date tokens ({Y}/{m}/{d}): only the current period directory is scanned; historical periods must be purged separately.',
                        $volume->name,
                        $profile->name,
                    ));
                }
                $canonicalPath = $this->normalizePhysicalPath($volume->path);
                $endpoint = \LiveUX\FileManagerBundle\Security\PathNormalizer::normalizeS3Endpoint((string) ($volume->driverOptions['endpoint'] ?? ''));
                $physicalKey = $volume->driver . '|' . $canonicalPath . '|' . $endpoint . '|' . (string) ($volume->driverOptions['bucket'] ?? '') . '|' . (string) ($volume->driverOptions['prefix'] ?? '') . '|' . (string) ($volume->driverOptions['service'] ?? '') . '|' . mb_strtolower($trashDir, 'UTF-8');
                if ($profileName !== null) {
                    $physicalKey .= '|' . $profile->name . '|' . $volume->name;
                }
                // When multiple profiles share the same physical trash directory, preserve the longest retention_days.
                if (!isset($targets[$physicalKey]) || $retentionDays > $targets[$physicalKey]['retentionDays']) {
                    $targets[$physicalKey] = [
                        'profile' => $profile,
                        'volumeName' => $volume->name,
                        'retentionDays' => $retentionDays,
                    ];
                }
            }
        }

        foreach ($targets as $target) {
            if ($dryRun) {
                $totalPurged += $this->countExpiredEntries($target['profile'], $target['volumeName'], $overrideDays);
                continue;
            }
            $totalPurged += $this->trashManager->purgeExpired($target['profile'], $target['volumeName'], $overrideDays);
        }

        if ($volumeFilter !== null && $matchedVolumes === 0) {
            $io->error(sprintf('Volume "%s" is not attached to any matched trash-enabled profile.', $volumeFilter));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note(sprintf('[Dry-Run] Would permanently purge %d expired item(s) from trash.', $totalPurged));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Purged %d expired item(s) from trash.', $totalPurged));

        return Command::SUCCESS;
    }

    private function countExpiredEntries(\LiveUX\FileManagerBundle\Config\ProfileConfig $profile, string $volumeName, ?int $overrideDays): int
    {
        $days = $overrideDays ?? (int) ($profile->trash['retention_days'] ?? 30);
        $cutoff = time() - ($days * 86400);
        $count = 0;

        foreach ($this->trashManager->listTrash($profile, $volumeName) as $entry) {
            if ($days <= 0 || $entry->deletedAt <= $cutoff) {
                ++$count;
            }
        }

        return $count;
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
