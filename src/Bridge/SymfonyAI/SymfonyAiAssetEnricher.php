<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Bridge\SymfonyAI;

use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use LiveUX\FileManagerBundle\Exception\FileManagerException;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Throwable;

/**
 * Optional zero-lock-in bridge for `symfony/ai-bundle` (`symfony/ai-platform`).
 *
 * Supports OpenAI (GPT-4o / GPT-4.1-mini), Google Gemini, Anthropic Claude, Mistral, VertexAI,
 * and local privacy-first Ollama vision/text models through a single interchangeable PlatformInterface.
 *
 * Automatically registered by FileManagerBundle only when `symfony/ai-platform` is installed.
 */
final readonly class SymfonyAiAssetEnricher implements AssetEnricherInterface
{
    private const array VISION_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const array TEXT_MIMES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/yaml',
        'application/json',
        'application/yaml',
        'application/x-yaml',
    ];

    private const int MAX_TEXT_SNIPPET_BYTES = 8192;

    public function __construct(
        private ?object $platform = null,
        private string $defaultModel = 'gpt-4o-mini',
    ) {
    }

    public function supports(string $mimeType): bool
    {
        if ($this->platform === null || !interface_exists(PlatformInterface::class) || !$this->platform instanceof PlatformInterface) {
            return false;
        }

        $normalized = strtolower(trim($mimeType));

        return in_array($normalized, self::VISION_MIMES, true)
            || in_array($normalized, self::TEXT_MIMES, true);
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
        if ($this->platform === null || !interface_exists(PlatformInterface::class) || !$this->platform instanceof PlatformInterface) {
            throw new FileManagerException('Symfony AI platform is not installed or configured.', 'errors.ai_unavailable');
        }

        $normalizedMime = strtolower(trim($mimeType));
        if (!$this->supports($normalizedMime)) {
            throw new FileManagerException(
                sprintf('AI enrichment is not supported for MIME type "%s".', $normalizedMime),
                'errors.ai_unsupported_type',
            );
        }

        $model = is_string($context['model'] ?? null) && trim($context['model']) !== ''
            ? trim($context['model'])
            : $this->defaultModel;
        $maxTags = max(1, min(20, (int) ($context['max_tags'] ?? 6)));
        $filename = is_string($context['filename'] ?? null) ? $context['filename'] : '';

        try {
            $messages = $this->buildMessageBag($localFilePath, $normalizedMime, $locale, $maxTags, $filename);
            $rawText = $this->platform->invoke($model, $messages)->asText();
        } catch (Throwable $e) {
            throw new FileManagerException('AI enrichment request failed.', 'errors.ai_failed', [], 0, $e);
        }

        return self::parseJsonResponse($rawText, $maxTags, 'symfony/ai', $model);
    }

    /**
     * Parses structured JSON output returned by any vision/text LLM (tolerating markdown code fences).
     */
    public static function parseJsonResponse(
        string $rawText,
        int $maxTags = 6,
        ?string $provider = 'symfony/ai',
        ?string $model = null,
    ): AssetEnrichmentResult {
        $trimmed = trim($rawText);
        if ($trimmed === '') {
            return new AssetEnrichmentResult(provider: $provider, model: $model);
        }

        // Strip optional ```json ... ``` fences emitted by some LLMs
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $trimmed, $match) === 1) {
            $candidate = $match[1];
        } else {
            $start = strpos($trimmed, '{');
            $end = strrpos($trimmed, '}');
            $candidate = ($start !== false && $end !== false && $end > $start)
                ? substr($trimmed, $start, $end - $start + 1)
                : $trimmed;
        }

        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            if (
                str_contains($trimmed, '{')
                || str_contains($trimmed, '}')
                || str_contains($trimmed, '```')
                || str_contains($trimmed, "\n")
                || mb_strlen($trimmed, 'UTF-8') > 200
                || preg_match('/^(?:i\s*\'?m\s+sorry|sorry[,! ]|i\s+cannot|i\s+can\'?t|as\s+an\s+ai\b|unable\s+to\b|üzgünüm|bunu\s+yapamam|yardımcı\s+olamam|bir\s+yapay\s+zeka|désolé|je\s+ne\s+peux\s+pas|en\s+tant\s+qu\'?ia|es\s+tut\s+mir\s+leid|ich\s+kann\s+nicht|als\s+ki\b|lo\s+siento|no\s+puedo|como\s+ia\b|آسف|لا\s+أستطيع|لا\s+يمكنني|كذكاء\s+اصطناعي|متأسفم|نمی‌?توانم|извините|я\s+не\s+могу|как\s+ии\b|申し訳ありません|できません|aiとして|抱歉|无法|不能|作为人工智能|죄송합니다|할\s+수\s+없습니다|ai로서)/iu', $trimmed) === 1
            ) {
                return new AssetEnrichmentResult(
                    provider: $provider,
                    model: $model,
                );
            }

            $cleanFallback = \LiveUX\FileManagerBundle\Metadata\FolderMetadata::fromArray(['alt' => $trimmed]);

            return new AssetEnrichmentResult(
                alt: $cleanFallback->alt,
                provider: $provider,
                model: $model,
            );
        }

        $rawTags = $decoded['tags'] ?? [];
        if (is_string($rawTags)) {
            $rawTags = preg_split('/[,،、，]+/u', $rawTags) ?: [];
        }
        $cleanTags = \LiveUX\FileManagerBundle\Metadata\FolderMetadata::sanitizeTags($rawTags);
        $tags = array_slice($cleanTags, 0, max(1, $maxTags));
        $sanitizedMeta = \LiveUX\FileManagerBundle\Metadata\FolderMetadata::fromArray([
            'alt' => is_string($decoded['alt'] ?? null) ? $decoded['alt'] : null,
            'title' => is_string($decoded['title'] ?? null) ? $decoded['title'] : null,
            'caption' => is_string($decoded['caption'] ?? null) ? $decoded['caption'] : null,
            'tags' => $tags,
        ]);

        return new AssetEnrichmentResult(
            alt: $sanitizedMeta->alt,
            title: $sanitizedMeta->title,
            caption: $sanitizedMeta->caption,
            tags: $sanitizedMeta->tags,
            provider: $provider,
            model: $model,
        );
    }

    private function buildMessageBag(
        string $localFilePath,
        string $mimeType,
        string $locale,
        int $maxTags,
        string $filename,
    ): MessageBag {
        $safeLocale = \LiveUX\FileManagerBundle\Service\MessageTranslator::isValidLocaleString($locale)
            ? trim($locale)
            : 'en';
        $systemPrompt = sprintf(
            'You are a Digital Asset Management (DAM) SEO & WCAG accessibility assistant. '
            . 'Respond ONLY with a valid JSON object containing keys: "alt" (concise accessible alt text, max 140 chars), '
            . '"title" (short descriptive title, max 80 chars), "caption" (1-2 sentence summary, max 300 chars), '
            . '"tags" (array of up to %d short lowercase search keywords). '
            . 'Write all values strictly in locale "%s". Do not wrap in markdown or add commentary.',
            $maxTags,
            $safeLocale,
        );

        $safeFilename = '';
        if ($filename !== '') {
            $nfcName = \LiveUX\FileManagerBundle\Util\UnicodeHelper::toNfc(mb_scrub($filename, 'UTF-8'));
            $strippedName = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}"\'`]+/u', ' ', $nfcName) ?? '';
            $safeFilename = mb_substr(\LiveUX\FileManagerBundle\Util\UnicodeHelper::trimSpaces($strippedName), 0, 120, 'UTF-8');
        }
        $hint = $safeFilename !== '' ? sprintf('Original filename hint: "%s". ', $safeFilename) : '';

        if (in_array($mimeType, self::VISION_MIMES, true)) {
            $userPrompt = $hint . 'Analyze this image and return the JSON metadata object.';

            return new MessageBag(
                Message::forSystem($systemPrompt),
                Message::ofUser($userPrompt, Image::fromFile($localFilePath)),
            );
        }

        $snippet = $this->readTextSnippet($localFilePath);
        $userPrompt = $hint . "Analyze this document excerpt and return the JSON metadata object:\n\n" . $snippet;

        return new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userPrompt),
        );
    }

    private function readTextSnippet(string $localFilePath): string
    {
        $handle = @fopen($localFilePath, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            $data = fread($handle, self::MAX_TEXT_SNIPPET_BYTES + 4);
            if (!is_string($data) || $data === '') {
                return '';
            }

            return \LiveUX\FileManagerBundle\Util\UnicodeHelper::decodeTextPreview($data, self::MAX_TEXT_SNIPPET_BYTES) ?? '';
        } finally {
            fclose($handle);
        }
    }
}
