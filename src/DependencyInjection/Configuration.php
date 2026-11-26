<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'liveux_file_manager';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->append($this->addDefaultsNode())
                ->append($this->addVolumesNode())
                ->append($this->addProfilesNode())
            ->end();

        return $treeBuilder;
    }

    private function addDefaultsNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('defaults');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('base_path')->defaultValue('%kernel.project_dir%/public/uploads')->end()
                ->scalarNode('theme')->defaultValue('bootstrap5')->end()
                ->enumNode('color_scheme')->values(['auto', 'light', 'dark'])->defaultValue('auto')->end()
                ->scalarNode('locale')->defaultNull()->end()
                ->integerNode('z_index')->min(1)->defaultValue(1050)->end()
                ->booleanNode('auto_inject_dialog_host')->defaultTrue()->end()
                ->scalarNode('dialog_host_profile')->defaultValue('default')->end()
                ->arrayNode('audit_log')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->enumNode('level')->values(['debug', 'info', 'notice', 'warning'])->defaultValue('info')->end()
                    ->end()
                ->end()
                ->arrayNode('ai')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_model')->defaultValue('gpt-4o-mini')->end()
                        ->scalarNode('platform_service')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('pool')->defaultValue('cache.app')->end()
                        ->integerNode('ttl')->min(0)->defaultValue(3600)->end()
                    ->end()
                ->end()
                ->arrayNode('metadata')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->enumNode('driver')->values(['json_file', 'doctrine', 'none'])->defaultValue('json_file')->end()
                        ->scalarNode('file')->defaultValue('%kernel.project_dir%/var/file-manager/metadata.json')->end()
                        ->scalarNode('connection')->defaultNull()->end()
                        ->scalarNode('entity_manager')->defaultValue('default')->end()
                        ->scalarNode('table')->defaultValue('liveux_fm_metadata')->end()
                        ->booleanNode('auto_create_table')->defaultTrue()->end()
                        ->booleanNode('allow_custom_icon_upload')
                            ->defaultFalse()
                            ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect; use custom icon set directories via ui.icon_set instead.')
                        ->end()
                        ->scalarNode('custom_icon_dir')
                            ->defaultValue('.fm-icons')
                            ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect; use custom icon set directories via ui.icon_set instead.')
                        ->end()
                        ->arrayNode('colors')
                            ->scalarPrototype()->end()
                            ->defaultValue(['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#64748b'])
                            ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect.')
                        ->end()
                        ->arrayNode('icons')
                            ->scalarPrototype()->end()
                            ->defaultValue(['folder', 'bag', 'image', 'video', 'doc', 'pen', 'star', 'lock'])
                            ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function addVolumesNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('volumes');
        $node
            ->normalizeKeys(false)
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('label')->defaultNull()->end()
                    ->scalarNode('icon')->defaultNull()->end()
                    ->enumNode('group')->values([null, 'local', 'network'])->defaultNull()->end()
                    ->enumNode('driver')->values(['LocalFileSystem', 'AwsS3', 'FlysystemService'])->defaultValue('LocalFileSystem')->end()
                    ->scalarNode('path')->defaultNull()->end()
                    ->scalarNode('url_prefix')->defaultNull()->end()
                    ->booleanNode('private')->defaultFalse()->end()
                    ->append($this->addNullableIntNode('cache_ttl', 0))
                    ->arrayNode('driver_options')
                        ->normalizeKeys(false)
                        ->variablePrototype()->end()
                        ->defaultValue([])
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function addProfilesNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('profiles');
        $node
            ->normalizeKeys(false)
            ->useAttributeAsKey('name')
            ->arrayPrototype()
                ->validate()
                    ->always(static function (array $v): array {
                        $allowExtract = is_array($v['zip'] ?? null) && ($v['zip']['allow_extract'] ?? false) === true;
                        if (!is_array($v['permissions'] ?? null)) {
                            $v['permissions'] = [];
                        }
                        $perm = $v['permissions']['extract_zip'] ?? null;
                        if (!$allowExtract && ($perm === null || $perm === true)) {
                            $v['permissions']['extract_zip'] = false;
                        } elseif ($perm === null) {
                            $v['permissions']['extract_zip'] = true;
                        }

                        return $v;
                    })
                ->end()
                ->children()
                    ->scalarNode('locale')->defaultNull()->end()
                    ->arrayNode('volumes')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->enumNode('driver')->values(['LocalFileSystem', 'AwsS3', 'FlysystemService'])->defaultValue('LocalFileSystem')->end()
                    ->scalarNode('path')->defaultNull()->end()
                    ->scalarNode('url_prefix')->defaultNull()->end()
                    ->booleanNode('private')->defaultFalse()->end()
                    ->arrayNode('driver_options')
                        ->normalizeKeys(false)
                        ->variablePrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->booleanNode('ignore_dot_files')->defaultTrue()->end()
                    ->arrayNode('exclude_patterns')
                        ->scalarPrototype()->end()
                        ->defaultValue(['/^_thumbs$/i', '/^\.DS_Store$/i'])
                    ->end()
                    ->enumNode('sort_by')->values(['name', 'date', 'size', 'type'])->defaultValue('name')->end()
                    ->enumNode('sort_direction')->values(['asc', 'desc'])->defaultValue('asc')->end()
                    ->booleanNode('folders_first')->defaultTrue()->end()
                    ->scalarNode('upload_subfolder')->defaultNull()->end()
                    ->arrayNode('upload_allow')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->arrayNode('upload_deny')
                        ->scalarPrototype()->end()
                        ->defaultValue([
                            'application/x-httpd-php',
                            'application/x-httpd-php-source',
                            'application/x-php',
                            'text/x-php',
                            'application/x-sh',
                            'text/x-shellscript',
                            'application/x-bash',
                            'text/x-script.python',
                            'text/x-python',
                            'text/x-perl',
                            'text/x-ruby',
                            'text/x-jsp',
                            'application/x-msdownload',
                            'application/x-dosexec',
                            'application/x-msdos-program',
                            'application/x-executable',
                            'application/x-pie-executable',
                            'application/x-sharedlib',
                            'application/x-mach-binary',
                            'application/wasm',
                            'application/java-archive',
                            'text/html',
                            'application/xhtml+xml',
                            'application/vnd.pwg-xhtml-print+xml',
                            'application/x-mimearchive',
                            'text/vnd.wap.wml',
                            'text/xml',
                            'application/xml',
                            'text/javascript',
                            'application/javascript',
                            'application/x-javascript',
                            'text/ecmascript',
                            'application/ecmascript',
                            'image/svg',
                            'text/xsl',
                            'application/xslt+xml',
                            'application/vnd.wap.xhtml+xml',
                            'multipart/related',
                            'application/rdf+xml',
                            'application/x-shockwave-flash',
                            'application/hta',
                            'text/vbscript',
                            'image/svg+xml-compressed',
                            'application/x-csh',
                            'application/x-perl',
                            'application/x-ruby',
                            'application/x-tcl',
                        ])
                    ->end()
                    ->scalarNode('upload_max_size')->defaultValue('10M')->end()
                    ->scalarNode('storage_quota')->defaultNull()->end()
                    ->enumNode('overwrite_policy')->values(['rename', 'overwrite', 'reject'])->defaultValue('rename')->end()
                    ->append($this->addChunkUploadNode())
                    ->append($this->addDuplicateDetectionNode())
                    ->append($this->addNamingNode())
                    ->append($this->addImageOptimizationNode())
                    ->append($this->addImageConstraintsNode())
                    ->append($this->addThumbnailNode())
                    ->append($this->addTrashNode())
                    ->append($this->addZipNode())
                    ->append($this->addShareNode())
                    ->append($this->addAiNode())
                    ->append($this->addUsageGuardNode())
                    ->append($this->addUiNode())
                    ->append($this->addPermissionsNode())
                    ->append($this->addSecurityNode())
                    ->append($this->addProfileCacheNode())
                ->end()
            ->end();

        return $node;
    }

    private function addChunkUploadNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('chunk_upload');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('chunk_size')->defaultValue('2M')->end()
                ->integerNode('parallel_uploads')->min(1)->max(10)->defaultValue(3)->end()
                ->integerNode('max_retries')->min(0)->max(10)->defaultValue(3)->end()
            ->end();

        return $node;
    }

    private function addDuplicateDetectionNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('duplicate_detection');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->enumNode('policy')->values(['warn', 'reject', 'reuse'])->defaultValue('warn')->end()
                ->integerNode('max_scan_files')->min(100)->max(50000)->defaultValue(5000)->end()
            ->end();

        return $node;
    }

    private function addNamingNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('naming');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('slugger')->defaultTrue()->end()
                ->scalarNode('slugger_locale')->defaultNull()->end()
                ->enumNode('transformer')
                    ->values(['none', 'uppercase', 'lowercase', 'camel', 'snake', 'cameltitle', 'title', 'titleall', 'folder'])
                    ->defaultValue('none')
                ->end()
                ->scalarNode('prepend')->defaultValue('')->end()
                ->scalarNode('append')->defaultValue('')->end()
                ->booleanNode('apply_on_rename')->defaultTrue()->end()
                ->booleanNode('apply_affixes_on_rename')->defaultFalse()->end()
                ->booleanNode('allow_extension_change')->defaultFalse()->end()
            ->end();

        return $node;
    }

    private function addImageOptimizationNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('image_optimization');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->booleanNode('auto_orient')->defaultTrue()->end()
                ->booleanNode('strip_exif')->defaultTrue()->end()
                ->integerNode('max_width')->min(100)->defaultValue(2400)->end()
                ->integerNode('max_height')->min(100)->defaultValue(2400)->end()
                ->booleanNode('convert_to_webp')->defaultFalse()->end()
                ->integerNode('quality')->min(10)->max(100)->defaultValue(85)->end()
            ->end();

        return $node;
    }

    private function addImageConstraintsNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('image_constraints');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->append($this->addNullableIntNode('min_width', 1))
                ->append($this->addNullableIntNode('min_height', 1))
                ->append($this->addNullableIntNode('max_width', 1))
                ->append($this->addNullableIntNode('max_height', 1))
                ->scalarNode('aspect_ratio')->defaultNull()->end()
                ->floatNode('aspect_ratio_tolerance')->min(0.0)->max(0.5)->defaultValue(0.05)->end()
            ->end();

        return $node;
    }

    private function addThumbnailNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('thumbnail');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->enumNode('driver')->values(['auto', 'imagick', 'gd', 'liip_imagine', 'none'])->defaultValue('auto')->end()
                ->enumNode('on_missing_driver')->values(['fallback_original', 'icon', 'exception'])->defaultValue('fallback_original')->end()
                ->integerNode('width')->min(32)->defaultValue(320)->end()
                ->integerNode('height')->min(32)->defaultValue(240)->end()
                ->enumNode('format')->values(['webp', 'jpeg', 'png'])->defaultValue('webp')->end()
                ->integerNode('quality')->min(10)->max(100)->defaultValue(80)->end()
                ->enumNode('fit')->values(['contain', 'cover'])->defaultValue('contain')->end()
                ->booleanNode('lazy_load')->defaultTrue()->end()
                ->scalarNode('cache_dir')->defaultValue('%kernel.project_dir%/public/media-cache')->end()
                ->scalarNode('liip_filter')
                    ->defaultNull()
                    ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect.')
                ->end()
            ->end();

        return $node;
    }

    private function addTrashNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('trash');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('directory')->defaultValue('.trash')->end()
                ->integerNode('retention_days')->min(1)->defaultValue(30)->end()
                ->scalarNode('max_size')->defaultValue('500M')->end()
            ->end();

        return $node;
    }

    private function addZipNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('zip');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->enumNode('fallback')
                    ->values(['disable', 'zipstream'])
                    ->defaultValue('disable')
                    ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect.')
                ->end()
                ->integerNode('max_files')->min(1)->defaultValue(100)->end()
                ->scalarNode('max_total_size')->defaultValue('250M')->end()
                ->booleanNode('allow_extract')->defaultFalse()->end()
                ->integerNode('max_extract_files')->min(1)->max(2000)->defaultValue(200)->end()
                ->scalarNode('max_extract_size')->defaultValue('100M')->end()
                ->integerNode('max_compression_ratio')->min(1)->max(1000)->defaultValue(100)->end()
            ->end();

        return $node;
    }

    private function addShareNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('share');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->integerNode('default_ttl')->min(60)->defaultValue(86400)->end()
                ->integerNode('max_ttl')->min(60)->defaultValue(2592000)->end()
                ->booleanNode('allow_inline_preview')->defaultTrue()->end()
            ->end();

        return $node;
    }

    private function addAiNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('ai');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('model')->defaultNull()->end()
                ->scalarNode('locale')->defaultNull()->end()
                ->integerNode('max_tags')->min(1)->max(20)->defaultValue(6)->end()
                ->integerNode('max_image_dimension')->min(256)->max(2048)->defaultValue(1024)->end()
                ->booleanNode('block_private_volumes')->defaultTrue()->end()
                ->booleanNode('auto_enrich_on_upload')->defaultFalse()->end()
            ->end();

        return $node;
    }

    private function addUsageGuardNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('usage_guard');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->enumNode('policy')->values(['warn', 'block'])->defaultValue('warn')->end()
                ->integerNode('max_references')->min(1)->max(200)->defaultValue(20)->end()
                ->booleanNode('enforce_server_side')->defaultTrue()->end()
            ->end();

        return $node;
    }

    private function addUiNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('ui');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('theme')->defaultNull()->end()
                ->enumNode('color_scheme')->values([null, 'auto', 'light', 'dark'])->defaultNull()->end()
                ->scalarNode('icon_set')->defaultValue('classic')->end()
                ->enumNode('mobile_dialog_mode')->values(['auto', 'fullscreen', 'sheet'])->defaultValue('auto')->end()
                ->append($this->addNullableIntNode('z_index', 1))
                ->booleanNode('show_folder_tree')->defaultTrue()->end()
                ->booleanNode('show_folder_item_count')->defaultTrue()->end()
                ->enumNode('folder_count_mode')->values(['recursive_files', 'files', 'items'])->defaultValue('recursive_files')->end()
                ->booleanNode('show_quota')->defaultTrue()->end()
                ->booleanNode('show_quota_details')->defaultTrue()->end()
                ->booleanNode('tree_lines')->defaultTrue()->end()
                ->enumNode('inspector_mode')->values(['on_select', 'always', 'hidden'])->defaultValue('on_select')->end()
                ->enumNode('volume_layout')
                    ->values(['grouped_tree', 'switcher', 'both'])
                    ->defaultValue('both')
                    ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect.')
                ->end()
                ->enumNode('volume_order')->values(['local_first', 'network_first', 'configured'])->defaultValue('local_first')->end()
                ->enumNode('default_view')->values(['grid', 'list'])->defaultValue('grid')->end()
                ->scalarNode('item_size')->defaultValue('md')->end()
                ->enumNode('card_aspect_ratio')->values(['1/1', '4/3', '16/9', '3/4'])->defaultValue('4/3')->end()
                ->enumNode('folder_style')->values(['tile', 'card'])->defaultValue('tile')->end()
                ->booleanNode('show_size_slider')->defaultTrue()->end()
                ->enumNode('folder_open_on')->values(['dblclick', 'click'])->defaultValue('dblclick')->end()
                ->enumNode('pagination_mode')
                    ->values(['pages', 'loadmore'])
                    ->defaultValue('pages')
                    ->setDeprecated('liveux/file-manager-bundle', '0.1', 'Option "%node%" at "%path%" is deprecated and has no effect.')
                ->end()
                ->integerNode('per_page')->min(6)->max(200)->defaultValue(24)->end()
                ->arrayNode('per_page_options')
                    ->integerPrototype()->min(1)->max(500)->end()
                    ->defaultValue([24, 48, 96])
                ->end()
                ->booleanNode('show_perf_badge')->defaultFalse()->end()
                ->booleanNode('show_nav_buttons')->defaultTrue()->end()
                ->booleanNode('show_history_buttons')->defaultTrue()->end()
                ->booleanNode('show_up_button')->defaultTrue()->end()
                ->booleanNode('show_refresh_button')->defaultTrue()->end()
                ->booleanNode('show_breadcrumbs')->defaultTrue()->end()
                ->booleanNode('show_search')->defaultTrue()->end()
                ->booleanNode('show_type_filter')->defaultTrue()->end()
                ->booleanNode('show_view_toggle')->defaultTrue()->end()
                ->booleanNode('show_pagination')->defaultTrue()->end()
                ->booleanNode('show_per_page_selector')->defaultTrue()->end()
                ->booleanNode('show_sort')->defaultTrue()->end()
                ->booleanNode('show_fullscreen_button')->defaultTrue()->end()
                ->booleanNode('show_settings_button')->defaultTrue()->end()
                ->booleanNode('show_footer')->defaultTrue()->end()
                ->booleanNode('show_context_nav')->defaultTrue()->end()
                ->booleanNode('allow_dropzone_upload')->defaultTrue()->end()
                ->booleanNode('remember_last_dir')->defaultTrue()->end()
                ->booleanNode('confirm_delete')->defaultTrue()->end()
                ->booleanNode('confirm_move')->defaultTrue()->end()
                ->booleanNode('double_click_to_select')->defaultTrue()->end()
                ->booleanNode('allow_context_menu')->defaultTrue()->end()
                ->booleanNode('allow_pdf_preview')->defaultTrue()->end()
                ->booleanNode('allow_video_preview')->defaultTrue()->end()
                ->booleanNode('keyboard_shortcuts')->defaultTrue()->end()
                ->booleanNode('allow_drag_move')->defaultTrue()->end()
            ->end();

        return $node;
    }

    private function addPermissionsNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('permissions');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->variableNode('view')->defaultTrue()->end()
                ->variableNode('upload')->defaultTrue()->end()
                ->variableNode('create_folder')->defaultTrue()->end()
                ->variableNode('rename')->defaultTrue()->end()
                ->variableNode('clone')->defaultTrue()->end()
                ->variableNode('move')->defaultTrue()->end()
                ->variableNode('copy')->defaultTrue()->end()
                ->variableNode('delete')->defaultTrue()->end()
                ->variableNode('trash_restore')->defaultTrue()->end()
                ->variableNode('trash_purge')->defaultTrue()->end()
                ->variableNode('download')->defaultTrue()->end()
                ->variableNode('download_zip')->defaultTrue()->end()
                ->variableNode('extract_zip')->defaultNull()->end()
                ->variableNode('share')->defaultTrue()->end()
                ->variableNode('clear_cache')->defaultTrue()->end()
                ->variableNode('edit_metadata')->defaultTrue()->end()
                ->variableNode('ai_enrich')->defaultTrue()->end()
            ->end();

        return $node;
    }

    private function addSecurityNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('security');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('voter_enabled')->defaultTrue()->end()
                ->booleanNode('csrf_protection')->defaultTrue()->end()
                ->booleanNode('sanitize_svg')->defaultTrue()->end()
                ->booleanNode('allow_remote_url_import')->defaultFalse()->end()
                ->append($this->addNullableBoolNode('audit_log'))
            ->end();

        return $node;
    }

    private function addProfileCacheNode(): NodeDefinition
    {
        $node = new ArrayNodeDefinition('cache');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->append($this->addNullableBoolNode('enabled'))
                ->append($this->addNullableIntNode('ttl', 0))
            ->end();

        return $node;
    }

    private function addNullableIntNode(string $name, int $min = 0): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node
            ->defaultNull()
            ->validate()
                ->ifTrue(static function (mixed $v) use ($min): bool {
                    if ($v === null) {
                        return false;
                    }
                    if (is_string($v) && self::isEnvPlaceholder($v)) {
                        return false;
                    }

                    return !is_int($v) || $v < $min;
                })
                ->thenInvalid(sprintf('Expected null or an integer >= %d for "%s", got %%s.', $min, $name))
            ->end();

        return $node;
    }

    private function addNullableBoolNode(string $name): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node
            ->defaultNull()
            ->validate()
                ->ifTrue(static function (mixed $v): bool {
                    if ($v === null) {
                        return false;
                    }
                    if (is_string($v) && self::isEnvPlaceholder($v)) {
                        return false;
                    }

                    return !is_bool($v);
                })
                ->thenInvalid(sprintf('Expected null or a boolean for "%s", got %%s.', $name))
            ->end();

        return $node;
    }

    private static function isEnvPlaceholder(string $value): bool
    {
        return preg_match('/^env_[a-f0-9]+_/i', $value) === 1
            || preg_match('/^%env\([^%]+\)%$/', trim($value)) === 1;
    }
}
