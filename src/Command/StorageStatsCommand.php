<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use LiveUX\FileManagerBundle\Service\ProfileFileScanner;
use LiveUX\FileManagerBundle\Service\StorageQuotaChecker;
use LiveUX\FileManagerBundle\Service\TrashManager;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:stats',
    description: 'Displays storage usage, file/folder counts, quota status, thumbnail cache size, and trash stats per profile.',
)]
final class StorageStatsCommand extends Command
{
    public function __construct(
        private readonly ProfileFileScanner $scanner,
        private readonly StorageQuotaChecker $quotaChecker,
        private readonly ThumbnailManager $thumbnailManager,
        private readonly TrashManager $trashManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::OPTIONAL, 'Optional profile name (shows all profiles if omitted)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output statistics as machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profileArg = $input->getArgument('profile');
        $asJson = (bool) $input->getOption('json');
        $profileName = is_string($profileArg) && trim($profileArg) !== '' ? trim($profileArg) : null;

        try {
            $profiles = $this->scanner->resolveTargetProfiles($profileName);
        } catch (FileManagerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $report = [];
        $tableRows = [];

        foreach ($profiles as $profile) {
            foreach ($this->scanner->resolveTargetVolumes($profile, null) as $volume) {
                if ($this->scanner->volumeUsesUserIdToken($profile, $volume->name)) {
                    if (!$asJson) {
                        $io->note(sprintf('Skipping volume "%s" on profile "%s" (uses runtime {user_id} token).', $volume->name, $profile->name));
                    }
                    continue;
                }
                if ($this->scanner->volumeUsesDateTokens($profile, $volume->name) && !$asJson) {
                    $io->note(sprintf('Volume "%s" on profile "%s" uses date tokens ({Y}/{m}/{d}): scanning current period only.', $volume->name, $profile->name));
                }
                $scan = $this->scanner->scanStorageSummaryWithImages($profile, $volume);
                $storage = ['files' => $scan['files'], 'folders' => $scan['folders'], 'bytes' => $scan['bytes']];
                $thumbStats = $this->collectProfileThumbStatsFromImages($profile, $scan['images']);
                $trashEntries = $this->trashManager->listTrash($profile, $volume->name);
                $trashBytes = 0;
                foreach ($trashEntries as $entry) {
                    $trashBytes += $entry->size;
                }
                // Reuse already-scanned active + trash bytes to avoid a second full recursive storage scan on cold cache.
                $quota = $this->quotaChecker->buildSummaryFromUsedBytes($profile, $storage['bytes'] + $trashBytes);

                $report[] = [
                    'profile' => $profile->name,
                    'volume' => $volume->name,
                    'files' => $storage['files'],
                    'folders' => $storage['folders'],
                    'bytes' => $storage['bytes'],
                    'sizeFormatted' => $this->scanner->formatBytes($storage['bytes']),
                    'quota' => $quota,
                    'thumbnails' => [
                        'files' => $thumbStats['files'],
                        'bytes' => $thumbStats['bytes'],
                        'sizeFormatted' => $this->scanner->formatBytes($thumbStats['bytes']),
                    ],
                    'trash' => [
                        'items' => count($trashEntries),
                        'bytes' => $trashBytes,
                        'sizeFormatted' => $this->scanner->formatBytes($trashBytes),
                    ],
                ];

                $quotaLabel = $quota['enabled']
                    ? sprintf('%s / %s (%.1f%%)', $quota['usedFormatted'], $quota['maxFormatted'], $quota['percent'])
                    : 'Unlimited';

                $tableRows[] = [
                    $profile->name,
                    $volume->name,
                    $storage['files'],
                    $storage['folders'],
                    $this->scanner->formatBytes($storage['bytes']),
                    $quotaLabel,
                    sprintf('%d (%s)', $thumbStats['files'], $this->scanner->formatBytes($thumbStats['bytes'])),
                    sprintf('%d (%s)', count($trashEntries), $this->scanner->formatBytes($trashBytes)),
                ];
            }
        }

        if ($asJson) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title('LiveUX FileManager — Storage & Cache Statistics');
        $io->table(
            ['Profile', 'Volume', 'Files', 'Folders', 'Active Size', 'Quota (incl. Trash)', 'Thumbnails Cached', 'Trash'],
            $tableRows,
        );

        return Command::SUCCESS;
    }

    /**
     * Counts cached thumbnails belonging specifically to this profile & volume (accurate even on shared cache_dir).
     *
     * @param list<array{path: string, canonical: string, extension: string, size: int}> $images
     *
     * @return array{files: int, bytes: int}
     */
    private function collectProfileThumbStatsFromImages(ProfileConfig $profile, array $images): array
    {
        $files = 0;
        $bytes = 0;

        foreach ($images as $img) {
            foreach ($this->thumbnailManager->getCandidateCachedPaths($profile, $img['canonical']) as $cachedPath) {
                if (is_file($cachedPath)) {
                    ++$files;
                    $bytes += (int) @filesize($cachedPath);
                    break;
                }
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }
}
