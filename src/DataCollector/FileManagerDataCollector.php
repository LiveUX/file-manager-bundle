<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\DataCollector;

use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\FileManagerBundle;
use LiveUX\FileManagerBundle\Icon\IconSetRegistry;
use LiveUX\FileManagerBundle\Security\PathNormalizer;
use LiveUX\FileManagerBundle\Service\ZipArchiveService;
use LiveUX\FileManagerBundle\Thumbnail\ThumbnailManager;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class FileManagerDataCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly ConfigProviderInterface $configProvider,
        private readonly ThumbnailManager $thumbnailManager,
        private readonly ZipArchiveService $zipArchiveService,
        private readonly IconSetRegistry $iconSetRegistry,
    ) {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $profiles = [];
        $volumes = [];

        try {
            foreach ($this->configProvider->getProfileNames() as $name) {
                $cfg = $this->configProvider->getProfile($name);
                $rawIconSet = (string) ($cfg->ui['icon_set'] ?? 'classic');
                $safeIconSet = (str_contains($rawIconSet, '/') || str_contains($rawIconSet, '\\'))
                    ? 'custom:' . PathNormalizer::basenameUtf8(str_replace('\\', '/', rtrim($rawIconSet, '/\\')))
                    : $rawIconSet;
                $profiles[] = [
                    'name' => $cfg->name,
                    'theme' => $cfg->theme,
                    'locale' => $cfg->locale ?? 'en',
                    'iconSet' => $safeIconSet,
                    'csrf' => ($cfg->security['csrf_protection'] ?? true) === true,
                    'voter' => $cfg->isVoterEnabled(),
                    'remoteImport' => ($cfg->security['allow_remote_url_import'] ?? false) === true,
                    'cacheEnabled' => ($cfg->cache['enabled'] ?? true) === true,
                    'cacheTtl' => (int) ($cfg->cache['ttl'] ?? 3600),
                ];
            }

            foreach ($this->configProvider->getVolumes() as $vol) {
                $volumes[] = [
                    'name' => $vol->name,
                    'label' => $vol->label,
                    'group' => $vol->group,
                    'driver' => $vol->driver,
                    'private' => $vol->private,
                ];
            }
        } catch (Throwable) {
            // Never crash the Symfony Web Profiler or error page if a custom ConfigProvider fails.
        }

        $this->data = [
            'profiles' => $profiles,
            'volumes' => $volumes,
            'diagnostics' => [
                ...$this->thumbnailManager->getDiagnostics(),
                'zip' => $this->zipArchiveService->isZipArchiveAvailable(),
            ],
            'iconSets' => $this->iconSetRegistry->getAvailableSets(),
        ];
    }

    public function getName(): string
    {
        return 'liveux_file_manager';
    }

    public static function getTemplate(): string
    {
        return '@LiveUXFileManager/data_collector/file_manager.html.twig';
    }

    public function getVersion(): string
    {
        return FileManagerBundle::VERSION;
    }

    /** @return list<array<string, mixed>> */
    public function getProfiles(): array
    {
        return is_array($this->data['profiles'] ?? null) ? array_values($this->data['profiles']) : [];
    }

    /** @return list<array<string, mixed>> */
    public function getVolumes(): array
    {
        return is_array($this->data['volumes'] ?? null) ? array_values($this->data['volumes']) : [];
    }

    /** @return array<string, mixed> */
    public function getDiagnostics(): array
    {
        return is_array($this->data['diagnostics'] ?? null) ? $this->data['diagnostics'] : [];
    }

    /** @return list<string> */
    public function getIconSets(): array
    {
        return is_array($this->data['iconSets'] ?? null) ? array_values($this->data['iconSets']) : [];
    }
}
