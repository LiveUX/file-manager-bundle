<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\DependencyInjection;

use LiveUX\FileManagerBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

final class FullConfigReferenceDocTest extends TestCase
{
    public function testAllFullConfigAndRecipeYamlBlocksInTurkishDocsAreSchemaValid(): void
    {
        $docPath = \dirname(__DIR__, 3) . '/docs/tr/02-yapilandirma-ve-profiller.md';
        self::assertFileExists($docPath);

        $markdown = (string) file_get_contents($docPath);
        preg_match_all('/```yaml\s*\R(.*?)```/s', $markdown, $matches);
        self::assertNotEmpty($matches[1], 'Expected YAML examples in 02-yapilandirma-ve-profiller.md.');

        $processor = new Processor();
        $configuration = new Configuration();
        $validatedRootConfigs = 0;

        foreach ($matches[1] as $idx => $yamlSnippet) {
            $parsed = Yaml::parse($yamlSnippet);
            if (!is_array($parsed) || !array_key_exists('liveux_file_manager', $parsed)) {
                continue;
            }

            $processed = $processor->processConfiguration($configuration, [$parsed['liveux_file_manager']]);
            self::assertIsArray($processed, sprintf('YAML block #%d failed Configuration validation.', $idx));
            ++$validatedRootConfigs;

            if (isset($processed['profiles']['full_reference'])) {
                $full = $processed['profiles']['full_reference'];
                self::assertSame('md', $full['ui']['item_size']);
                self::assertSame('4/3', $full['ui']['card_aspect_ratio']);
                self::assertSame('tile', $full['ui']['folder_style']);
                self::assertSame(0.05, $full['image_constraints']['aspect_ratio_tolerance']);
                self::assertTrue($full['ui']['show_size_slider']);
            }
        }

        // 5 real-world recipes + 1 full all-parameters reference block = at least 6 valid root configs
        self::assertGreaterThanOrEqual(6, $validatedRootConfigs);
    }

    public function testAboutAndGithubLinkCannotBeDisabledViaConfiguration(): void
    {
        $processor = new Processor();
        $configuration = new Configuration();

        foreach (['show_about', 'show_github_link'] as $forbiddenKey) {
            $rejected = false;
            try {
                $processor->processConfiguration($configuration, [[
                    'profiles' => [
                        'default' => [
                            'ui' => [$forbiddenKey => false],
                        ],
                    ],
                ]]);
            } catch (InvalidConfigurationException) {
                $rejected = true;
            }

            self::assertTrue($rejected, sprintf('Option "ui.%s" must not be configurable.', $forbiddenKey));
        }
    }
}
