<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\AI;

/**
 * Extensible contract for AI-powered asset enrichment (alt text, title, caption, tags).
 *
 * Implement this interface in your Symfony application to plug in any custom AI provider,
 * or install `symfony/ai-bundle` to activate the built-in SymfonyAiAssetEnricher bridge automatically.
 */
interface AssetEnricherInterface
{
    public function supports(string $mimeType): bool;

    /**
     * @param array<string, mixed> $context Optional hints (e.g. filename, profile, model, max_tags)
     */
    public function enrich(
        string $localFilePath,
        string $mimeType,
        string $locale = 'en',
        array $context = [],
    ): AssetEnrichmentResult;
}
