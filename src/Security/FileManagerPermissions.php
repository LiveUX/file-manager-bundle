<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

final class FileManagerPermissions
{
    public const string VIEW = 'FILE_MANAGER_VIEW';
    public const string UPLOAD = 'FILE_MANAGER_UPLOAD';
    public const string CREATE_FOLDER = 'FILE_MANAGER_CREATE_FOLDER';
    public const string RENAME = 'FILE_MANAGER_RENAME';
    public const string CLONE = 'FILE_MANAGER_CLONE';
    public const string MOVE = 'FILE_MANAGER_MOVE';
    public const string COPY = 'FILE_MANAGER_COPY';
    public const string DELETE = 'FILE_MANAGER_DELETE';
    public const string TRASH_RESTORE = 'FILE_MANAGER_TRASH_RESTORE';
    public const string TRASH_PURGE = 'FILE_MANAGER_TRASH_PURGE';
    public const string DOWNLOAD = 'FILE_MANAGER_DOWNLOAD';
    public const string DOWNLOAD_ZIP = 'FILE_MANAGER_DOWNLOAD_ZIP';
    public const string EXTRACT_ZIP = 'FILE_MANAGER_EXTRACT_ZIP';
    public const string SHARE = 'FILE_MANAGER_SHARE';
    public const string CLEAR_CACHE = 'FILE_MANAGER_CLEAR_CACHE';
    public const string EDIT_METADATA = 'FILE_MANAGER_EDIT_METADATA';
    public const string AI_ENRICH = 'FILE_MANAGER_AI_ENRICH';

    /**
     * @var array<string, string>
     */
    private const array ATTRIBUTE_TO_CONFIG_KEY = [
        self::VIEW => 'view',
        self::UPLOAD => 'upload',
        self::CREATE_FOLDER => 'create_folder',
        self::RENAME => 'rename',
        self::CLONE => 'clone',
        self::MOVE => 'move',
        self::COPY => 'copy',
        self::DELETE => 'delete',
        self::TRASH_RESTORE => 'trash_restore',
        self::TRASH_PURGE => 'trash_purge',
        self::DOWNLOAD => 'download',
        self::DOWNLOAD_ZIP => 'download_zip',
        self::EXTRACT_ZIP => 'extract_zip',
        self::SHARE => 'share',
        self::CLEAR_CACHE => 'clear_cache',
        self::EDIT_METADATA => 'edit_metadata',
        self::AI_ENRICH => 'ai_enrich',
    ];

    public static function supports(string $attribute): bool
    {
        return isset(self::ATTRIBUTE_TO_CONFIG_KEY[$attribute]);
    }

    public static function toConfigKey(string $attribute): ?string
    {
        return self::ATTRIBUTE_TO_CONFIG_KEY[$attribute] ?? null;
    }
}
