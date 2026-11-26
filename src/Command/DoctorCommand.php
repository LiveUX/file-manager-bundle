<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Command;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Config\VolumeConfig;
use LiveUX\FileManagerBundle\Service\ImageOptimizer;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Storage\VolumeRegistry;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'file-manager:doctor',
    description: 'Diagnoses server capabilities, storage volumes, thumbnail drivers, ZIP support, PHP upload limits, and optional live cloud connectivity.',
)]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly ConfigProviderInterface $configProvider,
        private readonly VolumeRegistry $volumeRegistry,
        private readonly ThumbnailManager $thumbnailManager,
        private readonly ZipArchiveService $zipArchiveService,
        private readonly ImageOptimizer $imageOptimizer = new ImageOptimizer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'check-remote',
            null,
            InputOption::VALUE_NONE,
            'Tests live connectivity (ListObjectsV2) against all configured network/S3 volumes and diagnoses SSL/credential issues.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('LiveUX FileManagerBundle — System Diagnostics');

        $thumb = $this->thumbnailManager->getDiagnostics();
        $opt = $this->imageOptimizer->getDiagnostics();
        $checkRemote = (bool) $input->getOption('check-remote');

        $io->table(
            ['Check', 'Status', 'Details'],
            [
                ['PHP Version', 'OK', PHP_VERSION],
                ['upload_max_filesize', 'INFO', (string) ini_get('upload_max_filesize')],
                ['post_max_size', 'INFO', (string) ini_get('post_max_size')],
                ['cURL CA Bundle', $this->hasConfiguredCaBundle() ? 'OK' : 'INFO', $this->describeCaBundle()],
                ['Thumbnail Driver', $thumb['activeDriver'] !== 'none' ? 'OK' : 'WARN', sprintf('active=%s (imagick=%s, gd=%s)', $thumb['activeDriver'], $thumb['imagick'] ? 'yes' : 'no', $thumb['gd'] ? 'yes' : 'no')],
                ['Image Optimizer', $opt['available'] ? 'OK' : 'WARN', $opt['available'] ? sprintf('driver=%s (webp=%s)', $opt['driver'], $opt['webp'] ? 'yes' : 'no') : 'Unavailable (uploads saved untouched)'],
                ['EXIF Auto-Orient (ext-exif)', $opt['exif'] ? 'OK' : 'INFO', $opt['exif'] ? 'Available' : 'Skipped gracefully when GD is used'],
                ['WebP Support', $thumb['webp'] ? 'OK' : 'WARN', $thumb['webp'] ? 'Available' : 'Fallback to JPEG/PNG'],
                ['ZIP Extension (ext-zip)', $this->zipArchiveService->isZipArchiveAvailable() ? 'OK' : 'WARN', $this->zipArchiveService->isZipArchiveAvailable() ? 'ZipArchive enabled' : 'Unavailable (ZIP download hidden)'],
            ],
        );

        $rows = [];
        $hasErrors = false;

        foreach ($this->configProvider->getProfileNames() as $profileName) {
            $profile = $this->configProvider->getProfile($profileName);
            try {
                $volumes = $this->volumeRegistry->getVolumesForProfile($profile);
            } catch (\Throwable $e) {
                $hasErrors = true;
                $rows[] = [$profileName, '-', '-', '-', 'ERROR', $e->getMessage()];
                continue;
            }
            foreach ($volumes as $volume) {
                [$status, $detail] = $this->inspectVolume($profile, $volume, $checkRemote);
                if ($status === 'ERROR') {
                    $hasErrors = true;
                }
                $rows[] = [$profileName, $volume->name, $volume->group, $volume->driver, $status, $detail];
            }
        }

        if ($rows !== []) {
            $io->section('Configured Profiles & Volumes');
            $io->table(['Profile', 'Volume', 'Group', 'Driver', 'Status', 'Target / Diagnostics'], $rows);
        } else {
            $io->warning('No profiles configured under liveux_file_manager.profiles.');
        }

        if ($hasErrors) {
            $io->error('Diagnostics found one or more volume errors. See the table above for remediation.');

            return Command::FAILURE;
        }

        $io->success($checkRemote ? 'Diagnostics & live cloud connectivity checks passed.' : 'Diagnostics completed.');

        return Command::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function inspectVolume(ProfileConfig $profile, VolumeConfig $volume, bool $checkRemote): array
    {
        $rawConfig = $this->volumeRegistry->getRawVolumeForProfile($profile, $volume->name);
        $isDynamicUser = $this->volumeUsesDynamicUserIdToken($profile, $rawConfig);
        $targetSource = $isDynamicUser ? $rawConfig : $volume;
        $target = (string) ($targetSource->path ?? ($targetSource->driverOptions['bucket'] ?? ($targetSource->driverOptions['service'] ?? $targetSource->driver)));

        if ($isDynamicUser) {
            return ['INFO', sprintf('%s (uses runtime {user_id} token — skipped live probe in CLI)', $target)];
        }

        if ($volume->driver === 'LocalFileSystem') {
            if ($volume->path === null || trim($volume->path) === '') {
                return ['ERROR', 'LocalFileSystem volume is missing a configured "path" option.'];
            }
            if (is_file($volume->path)) {
                return ['ERROR', sprintf('%s (points to an existing file, not a directory)', $target)];
            }
            $probeDir = $this->findNearestExistingAncestor($volume->path);
            $writable = $probeDir !== null && is_writable($probeDir);

            return [$writable ? 'OK' : 'ERROR', $writable ? $target : sprintf('%s (not writable)', $target)];
        }

        if (!$checkRemote) {
            return ['OK', sprintf('%s (pass --check-remote to probe live)', $target)];
        }

        return $this->probeRemoteVolume($profile, $volume, $target);
    }

    private function volumeUsesDynamicUserIdToken(ProfileConfig $profile, VolumeConfig $rawConfig): bool
    {
        $candidates = [
            $rawConfig->path,
            $rawConfig->urlPrefix,
            $rawConfig->driverOptions['prefix'] ?? null,
        ];
        if ($profile->volumes === []) {
            $candidates[] = $profile->path;
            $candidates[] = $profile->urlPrefix;
            $candidates[] = $profile->driverOptions['prefix'] ?? null;
        }

        foreach ($candidates as $val) {
            if (is_string($val) && str_contains($val, '{user_id}')) {
                return true;
            }
        }

        return false;
    }

    private function findNearestExistingAncestor(string $path): ?string
    {
        $cursor = $path;
        for ($i = 0; $i < 16; ++$i) {
            if (is_dir($cursor)) {
                return $cursor;
            }
            $parent = \dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function probeRemoteVolume(ProfileConfig $profile, VolumeConfig $volume, string $target): array
    {
        $start = microtime(true);
        try {
            $filesystem = $this->volumeRegistry->getFilesystem($profile, $volume->name);
            foreach ($filesystem->listContents('', false) as $_) {
                break;
            }
            $ms = (int) round((microtime(true) - $start) * 1000);

            return ['OK', sprintf('%s — Connected (%dms)', $target, $ms)];
        } catch (\Throwable $e) {
            return ['ERROR', sprintf('%s — %s', $target, $this->diagnoseRemoteError($e))];
        }
    }

    private function diagnoseRemoteError(\Throwable $exception): string
    {
        $combined = $exception->getMessage();
        $prev = $exception->getPrevious();
        while ($prev !== null) {
            $combined .= ' | ' . $prev->getMessage();
            $prev = $prev->getPrevious();
        }

        if (str_contains($combined, 'cURL error 60') || str_contains($combined, 'unable to get local issuer certificate')) {
            return 'SSL CA missing (cURL 60) — set curl.cainfo & openssl.cafile in php.ini or driver_options.ca_bundle.';
        }
        if (str_contains($combined, 'InvalidAccessKeyId') || str_contains($combined, 'SignatureDoesNotMatch')) {
            return 'Invalid S3 credentials — verify key, secret, and region.';
        }
        if (str_contains($combined, 'NoSuchBucket')) {
            return 'Bucket not found — verify driver_options.bucket and endpoint.';
        }
        if (str_contains($combined, 'XAmzContentSHA256Mismatch')) {
            return 'Checksum mismatch — set driver_options.request_checksum_calculation: when_required.';
        }
        if (str_contains($combined, 'AccessDenied') || str_contains($combined, '403 Forbidden')) {
            return 'Access denied (HTTP 403) — verify bucket permissions or S3 access key scope.';
        }

        $short = trim((string) preg_replace('/\s+/u', ' ', $exception->getMessage()));

        return mb_strlen($short, 'UTF-8') > 140 ? mb_substr($short, 0, 137, 'UTF-8') . '...' : $short;
    }

    private function hasConfiguredCaBundle(): bool
    {
        $curlCa = trim((string) ini_get('curl.cainfo'));
        $sslCa = trim((string) ini_get('openssl.cafile'));

        return ($curlCa !== '' && is_file($curlCa)) || ($sslCa !== '' && is_file($sslCa));
    }

    private function describeCaBundle(): string
    {
        $curlCa = trim((string) ini_get('curl.cainfo'));
        if ($curlCa !== '') {
            return is_file($curlCa) ? $curlCa : sprintf('%s (file missing!)', $curlCa);
        }

        $sslCa = trim((string) ini_get('openssl.cafile'));
        if ($sslCa !== '') {
            return is_file($sslCa) ? $sslCa : sprintf('%s (file missing!)', $sslCa);
        }

        return 'System default (on Windows/WAMP set curl.cainfo if S3/HTTPS fails with cURL error 60)';
    }
}
