<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Security;

use LiveUX\FileManagerBundle\Config\ProfileConfig;
use LiveUX\FileManagerBundle\Exception\InvalidPathException;
use LiveUX\FileManagerBundle\Exception\UploadValidationException;
use LiveUX\FileManagerBundle\Util\UnicodeHelper;
use Symfony\Component\Mime\MimeTypes;

/**
 * Centralized security guard blocking dangerous filenames, executable/double extensions,
 * Windows reserved device names, and unauthorized access to hidden/excluded paths (e.g. .trash, .env).
 */
final readonly class FileSecurityGuard
{
    public const array BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'pht', 'pgif', 'shtml', 'cgi', 'pl', 'py', 'rb', 'jsp', 'jspx', 'asp',
        'aspx', 'ashx', 'asmx', 'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com',
        'msi', 'vbs', 'vbe', 'ps1', 'psm1', 'dll', 'scr', 'htaccess', 'htpasswd',
        'html', 'htm', 'xhtml', 'xhtm', 'jhtml', 'mhtml', 'mht', 'wml', 'svgz', 'svg2', 'ini', 'env',
        'php2', 'php6', 'phtm', 'phpt', 'inc', 'xht', 'shtm', 'stm', 'hta', 'jar', 'swf', 'wasm', 'xsl', 'xslt',
        'xml', 'rdf', 'js', 'mjs', 'cjs',
    ];

    private const array BLOCKED_ACTIVE_MIMES = [
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
    ];

    private const array STRICT_BINARY_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'ico', 'pdf', 'zip',
    ];

    private const array ZIP_CONTAINER_EXTENSIONS = [
        'zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub',
    ];

    public const array BLOCKED_FILENAMES = [
        '.htaccess', '.htpasswd', '.user.ini', '.env', '.meta.json', 'web.config', '.git', '.svn',
    ];

    public const array SAFE_RASTER_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'ico',
    ];

    public const array SAFE_THUMBNAIL_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'ico', 'svg',
    ];

    private const array WINDOWS_RESERVED_NAMES = [
        'con', 'prn', 'aux', 'nul', 'conin$', 'conout$',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    public function assertSafeFilename(string $filename, bool $strictOsChars = true): void
    {
        if (!mb_check_encoding($filename, 'UTF-8') || UnicodeHelper::hasForbiddenControls($filename)) {
            throw new UploadValidationException('Filename is invalid.', 'errors.invalid_filename');
        }

        $rawBase = PathNormalizer::basenameUtf8($filename);
        if (strlen($rawBase) > 255) {
            throw new UploadValidationException(
                'Filename exceeds maximum filesystem length (255 bytes).',
                'errors.path_too_long',
            );
        }
        $this->assertNoUnsafeOsChars($rawBase, $strictOsChars);

        if (str_ends_with($rawBase, ' ') || UnicodeHelper::hasEdgeInvisibleOrTrailingDot($rawBase)) {
            throw new UploadValidationException(
                'Filenames ending with a dot or space are blocked.',
                'errors.filename_trailing_dot_space',
            );
        }

        $nfcBase = UnicodeHelper::toNfc(UnicodeHelper::trimSpaces($rawBase));
        $nfkcBase = UnicodeHelper::toNfkc($nfcBase);
        $foldedNfc = UnicodeHelper::foldDotEquivalents($nfcBase);
        $foldedNfkc = UnicodeHelper::foldDotEquivalents($nfkcBase);
        $skeleton = UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton($nfkcBase));
        $stemSkeleton = UnicodeHelper::visibleSkeleton(PathNormalizer::stemUtf8($foldedNfkc));

        if (
            $nfcBase === ''
            || $skeleton === ''
            || $skeleton === '.'
            || $skeleton === '..'
            || $stemSkeleton === ''
            || preg_match('/^\.{2,}$/', $skeleton) === 1
        ) {
            throw new UploadValidationException('Filename is invalid.', 'errors.invalid_filename');
        }

        foreach (array_unique([
            PathNormalizer::extensionUtf8($nfcBase),
            PathNormalizer::extensionUtf8($foldedNfc),
            PathNormalizer::extensionUtf8($foldedNfkc),
        ]) as $rawExt) {
            if ($rawExt !== '' && (UnicodeHelper::visibleSkeleton($rawExt) !== $rawExt || preg_match('/^[\p{L}\p{M}\p{N}_+-]+$/u', $rawExt) !== 1)) {
                throw new UploadValidationException('Filename extension contains invalid characters.', 'errors.invalid_filename');
            }
        }

        foreach (array_unique([
            mb_strtolower($nfcBase, 'UTF-8'),
            mb_strtolower($nfkcBase, 'UTF-8'),
            mb_strtolower($foldedNfc, 'UTF-8'),
            mb_strtolower($foldedNfkc, 'UTF-8'),
            mb_strtolower($skeleton, 'UTF-8'),
        ]) as $candidateLower) {
            $this->assertNotBlockedCandidate($candidateLower, $nfcBase);
        }
    }

    public function assertSafeFolderName(string $folderName, bool $strictOsChars = true): void
    {
        if (!mb_check_encoding($folderName, 'UTF-8') || UnicodeHelper::hasForbiddenControls($folderName)) {
            throw new UploadValidationException('Folder name is invalid.', 'errors.invalid_filename');
        }

        $rawBase = PathNormalizer::basenameUtf8($folderName);
        if (strlen($rawBase) > 255) {
            throw new UploadValidationException(
                'Folder name exceeds maximum filesystem length (255 bytes).',
                'errors.path_too_long',
            );
        }
        $this->assertNoUnsafeOsChars($rawBase, $strictOsChars);

        if (str_ends_with($rawBase, ' ') || UnicodeHelper::hasEdgeInvisibleOrTrailingDot($rawBase)) {
            throw new UploadValidationException(
                'Folder names ending with a dot or space are blocked.',
                'errors.filename_trailing_dot_space',
            );
        }

        $nfcBase = UnicodeHelper::toNfc(UnicodeHelper::trimSpaces($rawBase));
        $nfkcBase = UnicodeHelper::toNfkc($nfcBase);
        $foldedNfc = UnicodeHelper::foldDotEquivalents($nfcBase);
        $foldedNfkc = UnicodeHelper::foldDotEquivalents($nfkcBase);
        $skeleton = UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton($nfkcBase));

        if (
            $nfcBase === ''
            || $skeleton === ''
            || $skeleton === '.'
            || $skeleton === '..'
            || preg_match('/^\.{2,}$/', $skeleton) === 1
        ) {
            throw new UploadValidationException('Folder name is invalid.', 'errors.invalid_filename');
        }

        foreach (array_unique([
            mb_strtolower($nfcBase, 'UTF-8'),
            mb_strtolower($nfkcBase, 'UTF-8'),
            mb_strtolower($foldedNfc, 'UTF-8'),
            mb_strtolower($foldedNfkc, 'UTF-8'),
            mb_strtolower($skeleton, 'UTF-8'),
        ]) as $candidateLower) {
            $this->assertNotBlockedFolderCandidate($candidateLower, $nfcBase);
        }
    }

    private function assertNotBlockedFolderCandidate(string $baseLower, string $displayBase): void
    {
        if ($baseLower === '' || $baseLower === '.' || $baseLower === '..') {
            throw new UploadValidationException('Folder name is invalid.', 'errors.invalid_filename');
        }

        if (
            in_array($baseLower, self::BLOCKED_FILENAMES, true)
            || str_starts_with($baseLower, '.ht')
            || str_starts_with($baseLower, '.env.')
        ) {
            throw new UploadValidationException(
                sprintf('Folder name "%s" is blocked for security reasons.', $baseLower),
                'errors.blocked_filename',
                ['%name%' => $baseLower],
            );
        }

        $firstPart = strtolower(explode('.', $baseLower, 2)[0]);
        if (in_array($firstPart, self::WINDOWS_RESERVED_NAMES, true)) {
            throw new UploadValidationException(
                sprintf('Reserved system folder name "%s" is blocked.', $displayBase),
                'errors.reserved_filename',
                ['%name%' => $displayBase],
            );
        }
    }

    private function assertNotBlockedCandidate(string $baseLower, string $displayBase): void
    {
        if ($baseLower === '' || $baseLower === '.' || $baseLower === '..') {
            throw new UploadValidationException('Filename is invalid.', 'errors.invalid_filename');
        }

        if (
            in_array($baseLower, self::BLOCKED_FILENAMES, true)
            || str_starts_with($baseLower, '.ht')
            || str_starts_with($baseLower, '.env.')
        ) {
            throw new UploadValidationException(
                sprintf('Filename "%s" is blocked for security reasons.', $baseLower),
                'errors.blocked_filename',
                ['%name%' => $baseLower],
            );
        }

        $firstPart = strtolower(explode('.', $baseLower, 2)[0]);
        if (in_array($firstPart, self::WINDOWS_RESERVED_NAMES, true)) {
            throw new UploadValidationException(
                sprintf('Reserved system filename "%s" is blocked.', $displayBase),
                'errors.reserved_filename',
                ['%name%' => $displayBase],
            );
        }

        $ext = PathNormalizer::extensionUtf8($baseLower);
        if ($ext !== '' && in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            throw new UploadValidationException(
                sprintf('File extension ".%s" is blocked for security reasons.', $ext),
                'errors.blocked_extension',
                ['%ext%' => $ext],
            );
        }

        if (preg_match(self::getDoubleExtensionPattern(), $baseLower) === 1) {
            throw new UploadValidationException(
                'Double executable extensions are blocked for security reasons.',
                'errors.double_extension',
            );
        }
    }

    private static function getDoubleExtensionPattern(): string
    {
        static $pattern = null;
        if ($pattern !== null) {
            return $pattern;
        }

        $middle = array_values(array_unique(array_merge(self::BLOCKED_EXTENSIONS, ['svg'])));
        $quoted = array_map(static fn (string $e): string => preg_quote($e, '/'), $middle);
        $pattern = '/\.(?:' . implode('|', $quoted) . ')\./i';

        return $pattern;
    }

    /**
     * Prevents polyglot / MIME-spoofed uploads where magic bytes pass `upload_allow` (e.g. `GIF89a`)
     * while the filename carries an unrelated or active extension, or active content is disguised as an image.
     */
    public function assertMimeMatchesExtension(string $filename, string $detectedMime): void
    {
        $mime = strtolower(trim(explode(';', $detectedMime, 2)[0]));
        $ext = PathNormalizer::extensionUtf8($filename);

        if ($ext === 'svg') {
            if (!in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'], true)) {
                throw new UploadValidationException(
                    sprintf('File extension ".%s" does not match detected content type "%s".', $ext, $mime),
                    'errors.extension_mime_mismatch',
                    ['%ext%' => $ext, '%mime%' => $mime],
                );
            }

            return;
        }

        if ($mime === 'image/svg+xml') {
            throw new UploadValidationException(
                sprintf('SVG content cannot be uploaded with ".%s" extension.', $ext),
                'errors.extension_mime_mismatch',
                ['%ext%' => $ext, '%mime%' => $mime],
            );
        }

        if (in_array($mime, self::BLOCKED_ACTIVE_MIMES, true)) {
            throw new UploadValidationException(
                sprintf('MIME type "%s" is denied by security policy.', $mime),
                'errors.upload_mime_denied',
                ['%mime%' => $mime],
            );
        }

        if ($mime === '' || $mime === 'application/octet-stream') {
            if ($mime === 'application/octet-stream' && in_array($ext, self::STRICT_BINARY_EXTENSIONS, true)) {
                throw new UploadValidationException(
                    sprintf('File extension ".%s" does not match detected content type "%s".', $ext, $mime),
                    'errors.extension_mime_mismatch',
                    ['%ext%' => $ext, '%mime%' => $mime],
                );
            }

            return;
        }

        if ($mime === 'text/plain') {
            if (in_array($ext, self::STRICT_BINARY_EXTENSIONS, true)) {
                throw new UploadValidationException(
                    sprintf('File extension ".%s" does not match detected content type "%s".', $ext, $mime),
                    'errors.extension_mime_mismatch',
                    ['%ext%' => $ext, '%mime%' => $mime],
                );
            }

            return;
        }

        $expectedExts = array_map('strtolower', MimeTypes::getDefault()->getExtensions($mime));
        if ($mime === 'application/zip') {
            $expectedExts = array_values(array_unique(array_merge($expectedExts, self::ZIP_CONTAINER_EXTENSIONS)));
        }

        if (in_array($ext, self::STRICT_BINARY_EXTENSIONS, true) && ($expectedExts === [] || str_starts_with($mime, 'text/'))) {
            throw new UploadValidationException(
                sprintf('File extension ".%s" does not match detected content type "%s".', $ext, $mime),
                'errors.extension_mime_mismatch',
                ['%ext%' => $ext, '%mime%' => $mime],
            );
        }

        if ($expectedExts !== [] && ($ext === '' || !in_array($ext, $expectedExts, true))) {
            throw new UploadValidationException(
                sprintf('File extension ".%s" does not match detected content type "%s".', $ext, $mime),
                'errors.extension_mime_mismatch',
                ['%ext%' => $ext !== '' ? $ext : '(none)', '%mime%' => $mime],
            );
        }
    }

    /**
     * Matches a MIME type against exact entries or `type/*` wildcards (case-insensitive).
     *
     * @param list<string> $patterns
     */
    public function matchesMimeList(string $mime, array $patterns): bool
    {
        $cleanMime = strtolower(trim(explode(';', $mime, 2)[0]));
        if ($cleanMime === '') {
            return false;
        }

        $parts = explode('/', $cleanMime, 2);
        $type = $parts[0];
        foreach ($patterns as $pattern) {
            $cleanPattern = strtolower(trim($pattern));
            if ($cleanPattern === '') {
                continue;
            }
            if ($cleanPattern === '*/*' || $cleanPattern === $cleanMime) {
                return true;
            }
            if (str_ends_with($cleanPattern, '/*')) {
                $patternType = substr($cleanPattern, 0, -2);
                if ($patternType !== '' && $patternType === $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Prevents renaming, moving, or deleting a parent folder that contains a nested trash directory
     * (e.g. `system` when `trash.directory` is configured as `system/trash`).
     */
    public function assertNotTrashAncestor(string $cleanPath, ProfileConfig $profile): void
    {
        if ($cleanPath === '') {
            return;
        }

        $trashDir = $this->resolveTrashDirectory($profile);
        $trashSkeleton = $trashDir !== '' ? $this->buildTrashComparisonPath($trashDir) : '';
        $lowerPath = mb_strtolower(UnicodeHelper::toNfc($cleanPath), 'UTF-8');
        $skeletonPath = $this->buildTrashComparisonPath($cleanPath);
        if (
            $trashDir !== ''
            && (
                str_starts_with($trashDir, $lowerPath . '/')
                || ($skeletonPath !== '' && $trashSkeleton !== '' && str_starts_with($trashSkeleton, $skeletonPath . '/'))
            )
        ) {
            throw new InvalidPathException(
                'Cannot modify or delete an ancestor folder of the trash directory.',
                'errors.trash_access_forbidden',
            );
        }
    }

    /**
     * Centralized check for whether a normalized relative path is hidden, inside trash,
     * or matches any exclude_patterns rule (case-insensitive trash + fail-closed regex).
     */
    public function isPathIgnored(string $cleanPath, ProfileConfig $profile): bool
    {
        if ($cleanPath === '') {
            return false;
        }

        try {
            $this->assertAccessiblePath($cleanPath, $profile);

            return false;
        } catch (InvalidPathException) {
            return true;
        }
    }

    /**
     * Fast path used while walking subdirectories whose parent path was already validated.
     */
    public function isChildSegmentIgnored(string $cleanFullPath, string $leafSegment, ProfileConfig $profile): bool
    {
        if ($cleanFullPath === '' || $leafSegment === '') {
            return false;
        }

        $trashDir = $this->resolveTrashDirectory($profile);
        $trashSkeleton = $trashDir !== '' ? $this->buildTrashComparisonPath($trashDir) : '';
        $isPureAscii = preg_match('/^[\x21-\x7E]+$/', $cleanFullPath) === 1;
        try {
            if ($trashDir !== '') {
                $lowerFull = $isPureAscii
                    ? strtolower($cleanFullPath)
                    : mb_strtolower(UnicodeHelper::toNfc($cleanFullPath), 'UTF-8');
                if ($lowerFull === $trashDir || str_starts_with($lowerFull, $trashDir . '/')) {
                    return true;
                }
                if (!$isPureAscii) {
                    $skeletonPath = $this->buildTrashComparisonPath($cleanFullPath);
                    if (
                        $skeletonPath !== ''
                        && $trashSkeleton !== ''
                        && ($skeletonPath === $trashSkeleton || str_starts_with($skeletonPath, $trashSkeleton . '/'))
                    ) {
                        return true;
                    }
                }
            }

            $this->assertSegmentAccessible($leafSegment, $profile);

            return false;
        } catch (InvalidPathException) {
            return true;
        }
    }

    public function assertAccessiblePath(string $cleanPath, ProfileConfig $profile): void
    {
        if ($cleanPath === '') {
            return;
        }

        $trashDir = $this->resolveTrashDirectory($profile);
        $trashSkeleton = $trashDir !== '' ? $this->buildTrashComparisonPath($trashDir) : '';
        $isPureAscii = preg_match('/^[\x21-\x7E]+$/', $cleanPath) === 1;
        // Case-insensitive + NFC-normalized + skeleton comparison anchored at volume root
        // (TrashManager always anchors trash at the volume root, so nested `projects/trash/` is legitimate).
        $lowerPath = $isPureAscii ? strtolower($cleanPath) : mb_strtolower(UnicodeHelper::toNfc($cleanPath), 'UTF-8');
        if ($trashDir !== '' && ($lowerPath === $trashDir || str_starts_with($lowerPath, $trashDir . '/'))) {
            throw new InvalidPathException(
                'Direct access to the trash directory is forbidden.',
                'errors.trash_access_forbidden',
            );
        }
        if (!$isPureAscii && $trashDir !== '' && $trashSkeleton !== '') {
            $skeletonPath = $this->buildTrashComparisonPath($cleanPath);
            if ($skeletonPath !== '' && ($skeletonPath === $trashSkeleton || str_starts_with($skeletonPath, $trashSkeleton . '/'))) {
                throw new InvalidPathException(
                    'Direct access to the trash directory is forbidden.',
                    'errors.trash_access_forbidden',
                );
            }
        }

        foreach (explode('/', $cleanPath) as $segment) {
            if ($segment === '') {
                continue;
            }
            $this->assertSegmentAccessible($segment, $profile);
        }
    }

    private function assertSegmentAccessible(string $segment, ProfileConfig $profile): void
    {
        if (preg_match('/^[\x21-\x7E]+$/', $segment) === 1) {
            if ($profile->ignoreDotFiles && $segment[0] === '.') {
                throw new InvalidPathException(
                    'Direct access to hidden dot-files or dot-folders is forbidden.',
                    'errors.hidden_access_forbidden',
                );
            }
            foreach ($profile->excludePatterns as $pattern) {
                if (@preg_match($pattern, $segment) !== 0) {
                    throw new InvalidPathException(
                        'Access to excluded path is forbidden.',
                        'errors.excluded_access_forbidden',
                    );
                }
            }

            return;
        }

        $nfcSegment = UnicodeHelper::toNfc($segment);
        $nfkcSegment = UnicodeHelper::toNfkc($segment);
        $foldedSeg = UnicodeHelper::foldDotEquivalents($nfkcSegment);
        $skeletonSeg = UnicodeHelper::visibleSkeleton($foldedSeg);

        if (
            $profile->ignoreDotFiles
            && (
                str_starts_with($segment, '.')
                || str_starts_with($nfkcSegment, '.')
                || str_starts_with($foldedSeg, '.')
                || str_starts_with($skeletonSeg, '.')
            )
        ) {
            throw new InvalidPathException(
                'Direct access to hidden dot-files or dot-folders is forbidden.',
                'errors.hidden_access_forbidden',
            );
        }

        foreach ($profile->excludePatterns as $pattern) {
            // Fail closed on raw, NFC, NFKC, and visible-skeleton forms so macOS NFD / HFS+ zero-width fillers cannot bypass exclude_patterns.
            $variants = array_unique([$segment, $nfcSegment, $nfkcSegment, $skeletonSeg]);
            foreach ($variants as $variant) {
                if (@preg_match($pattern, $variant) !== 0) {
                    throw new InvalidPathException(
                        'Access to excluded path is forbidden.',
                        'errors.excluded_access_forbidden',
                    );
                }
            }
        }
    }

    /**
     * Linear-time, ReDoS-safe check for whether a local file begins with an SVG root element
     * (including namespaced <prefix:svg>) after optional BOM, whitespace, XML PIs, DOCTYPE, and comments.
     */
    public static function looksLikeSvgDocument(string $localTempPath): bool
    {
        if (!is_file($localTempPath)) {
            return false;
        }
        $head = @file_get_contents($localTempPath, false, null, 0, 262_144);
        if (!is_string($head) || $head === '') {
            return false;
        }
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }

        $offset = 0;
        $len = strlen($head);
        $sawXmlPrologue = false;
        while ($offset < $len) {
            $offset += strspn($head, " \t\r\n\f", $offset);
            if ($offset >= $len) {
                return $sawXmlPrologue && $len >= 262_141;
            }
            if (substr_compare($head, '<!--', $offset, 4) === 0) {
                $sawXmlPrologue = true;
                $end = strpos($head, '-->', $offset + 4);
                if ($end === false) {
                    return $len >= 262_141;
                }
                $offset = $end + 3;
                continue;
            }
            if (substr_compare($head, '<?', $offset, 2) === 0) {
                $sawXmlPrologue = true;
                $end = strpos($head, '?>', $offset + 2);
                if ($end === false) {
                    return $len >= 262_141;
                }
                $offset = $end + 2;
                continue;
            }
            if (substr_compare($head, '<!DOCTYPE', $offset, 9, true) === 0) {
                $sawXmlPrologue = true;
                $doctypeEnd = self::findDoctypeEndOffset($head, $offset + 9, $len);
                if ($doctypeEnd === null) {
                    return true;
                }
                $offset = $doctypeEnd;
                continue;
            }
            break;
        }

        return preg_match('/^<(?:[a-zA-Z_][a-zA-Z0-9._-]*:)?svg(?:\s|>|\/)/i', substr($head, $offset, 64)) === 1;
    }

    /**
     * Scans past a `<!DOCTYPE ...>` declaration including bracketed internal DTD subsets `[...]`
     * and quoted public/system identifiers so `>` inside `[<!ELEMENT ...>]` cannot terminate early.
     */
    private static function findDoctypeEndOffset(string $head, int $pos, int $len): ?int
    {
        $bracketDepth = 0;
        $quote = '';
        for ($i = $pos; $i < $len; ++$i) {
            $ch = $head[$i];
            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '[') {
                ++$bracketDepth;
                continue;
            }
            if ($ch === ']' && $bracketDepth > 0) {
                --$bracketDepth;
                continue;
            }
            if ($ch === '>' && $bracketDepth === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    private function resolveTrashDirectory(ProfileConfig $profile): string
    {
        $rawTrash = is_string($profile->trash['directory'] ?? null) ? $profile->trash['directory'] : '.trash';
        $normalized = trim(str_replace('\\', '/', UnicodeHelper::trimSpaces($rawTrash)), '/');
        if ($normalized === '') {
            return '';
        }

        $segments = array_values(array_filter(
            explode('/', $normalized),
            static fn (string $seg): bool => $seg !== '' && $seg !== '.',
        ));

        return mb_strtolower(UnicodeHelper::toNfc(implode('/', $segments)), 'UTF-8');
    }

    private function assertNoUnsafeOsChars(string $rawBase, bool $strictOsChars): void
    {
        if (str_contains($rawBase, '::') || preg_match('/:\$[A-Z]+/i', $rawBase) === 1) {
            throw new UploadValidationException('Filename is invalid.', 'errors.invalid_filename');
        }

        if ($strictOsChars && preg_match('/[:<>"|?*]/', $rawBase) === 1) {
            throw new UploadValidationException('Filename is invalid.', 'errors.invalid_filename');
        }
    }

    private function buildTrashComparisonPath(string $cleanPath): string
    {
        $parts = [];
        foreach (explode('/', $cleanPath) as $segment) {
            if ($segment === '') {
                continue;
            }
            $folded = UnicodeHelper::foldDotEquivalents(UnicodeHelper::visibleSkeleton(UnicodeHelper::toNfkc($segment)));
            $parts[] = mb_strtolower($folded, 'UTF-8');
        }

        return implode('/', $parts);
    }
}
