<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\AI;

/**
 * Zero-cost no-op enricher used when no AI backend is installed or configured.
 */
final readonly class NullAssetEnricher implements AssetEnricherInterface
{
    public function supports(string $mimeType): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function enrich(
        string $localFilePath,
        string $mimeType,
        string $locale = 'en',
        array $context = [],
    ): AssetEnrichmentResult {
        return new AssetEnrichmentResult();
    }
}
