# Permissions Map & Production Hardening Checklist

## All 17 `FileManagerPermissions` Constants

| YAML Key (`permissions.*`) | Constant (`FileManagerPermissions::*`) | Scope |
| :--- | :--- | :--- |
| `view` | `VIEW` (`FILE_MANAGER_VIEW`) | Listing, tree, thumbnail, inline preview, dialog |
| `upload` | `UPLOAD` (`FILE_MANAGER_UPLOAD`) | File upload, chunked upload, URL import, image editor save |
| `create_folder` | `CREATE_FOLDER` (`FILE_MANAGER_CREATE_FOLDER`) | Create directories |
| `rename` | `RENAME` (`FILE_MANAGER_RENAME`) | Rename files & folders |
| `clone` | `CLONE` (`FILE_MANAGER_CLONE`) | Duplicate files |
| `move` | `MOVE` (`FILE_MANAGER_MOVE`) | Move / cut-paste / drag-move |
| `copy` | `COPY` (`FILE_MANAGER_COPY`) | Copy / copy-paste |
| `delete` | `DELETE` (`FILE_MANAGER_DELETE`) | Soft delete to trash (or permanent delete if trash disabled) |
| `trash_restore` | `TRASH_RESTORE` (`FILE_MANAGER_TRASH_RESTORE`) | Restore from `.trash` |
| `trash_purge` | `TRASH_PURGE` (`FILE_MANAGER_TRASH_PURGE`) | Permanently purge `.trash` items or empty trash |
| `download` | `DOWNLOAD` (`FILE_MANAGER_DOWNLOAD`) | Single file download & Copy URL |
| `download_zip` | `DOWNLOAD_ZIP` (`FILE_MANAGER_DOWNLOAD_ZIP`) | Multi-file ZIP archive download (checked per path) |
| `extract_zip` | `EXTRACT_ZIP` (`FILE_MANAGER_EXTRACT_ZIP`) | Opt-in ZIP archive extraction (requires `zip.allow_extract: true`) |
| `share` | `SHARE` (`FILE_MANAGER_SHARE`) | Create HMAC-signed expiring share links (requires `share.enabled: true`) |
| `clear_cache` | `CLEAR_CACHE` (`FILE_MANAGER_CLEAR_CACHE`) | Clear dir/profile/thumb cache from Settings popover |
| `edit_metadata` | `EDIT_METADATA` (`FILE_MANAGER_EDIT_METADATA`) | Folder color/icon/pin + file alt/title/caption/tags/focal point |
| `ai_enrich` | `AI_ENRICH` (`FILE_MANAGER_AI_ENRICH`) | AI metadata suggestion button (`ai.enabled: true`) |

*(Note: Listing `.trash` via `GET /api/trash` requires trash enabled AND at least one of `DELETE`, `TRASH_RESTORE`, or `TRASH_PURGE`.)*

## Built-In Response Headers & Defenses

- **CSRF-first:** `UploadController`, `ImageEditorController`, `ZipDownloadController`, and `ApiController::action()` verify `X-LiveUX-FM-CSRF` before validating files/payloads.
- **Private / Voter Cache Isolation:** `ThumbnailController` sends `Cache-Control: private, no-cache, must-revalidate`, `Vary: Cookie, Authorization` (never `public`/`s-maxage`), and `Referrer-Policy: no-referrer` whenever `$volume->private || $profile->isVoterEnabled()`. `DownloadController` and `ZipDownloadController` send `Cache-Control: private, no-store, max-age=0`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: no-referrer`.
- **Active MIME Protection:** HTML/JS/SVG/XML files are always forced to `Content-Disposition: attachment` with `Content-Security-Policy: default-src 'none'; sandbox`.
- **User-Scoped Chunks:** Chunk temp directories incorporate the authenticated user's identifier (`0750` mode).

## Web Server Upload Directory Hardening

### Apache (`public/uploads/.htaccess`)
```apache
<FilesMatch "\.(?i:php|php\d|phtml|phar|phps|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
Options -Indexes -ExecCGI
Header always set X-Content-Type-Options "nosniff"
```

### Nginx (`nginx.conf`)
```nginx
location ^~ /uploads/ {
    location ~* \.(php|phtml|phar|cgi|pl|py|sh)$ {
        deny all;
    }
    add_header X-Content-Type-Options "nosniff" always;
}
```
