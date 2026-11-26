# 08 — Zdarzenia, polecenia CLI, pamięć podręczna, tłumaczenia i rozwiązywanie problemów (FAQ)

[← Poprzedni: 07 — Pamięć w chmurze i dynamiczna konfiguracja](07-cloud-storage-and-dynamic-config.md) | [Spis treści](README.md) | [Następny: 09 — Integracja z Symfony AI →](09-symfony-ai-integration.md)

> [!TIP]
> **W skrócie: do czego służą zdarzenia i polecenia CLI?**
> - **Zdarzenia (Events):** automatycznie powiadamiają Twoją aplikację Symfony za każdym razem, gdy użytkownik prześle, usunie, zmieni nazwę lub przeniesie plik — np. *„gdy nazwa pliku zostanie zmieniona, automatycznie zaktualizuj ścieżkę zdjęcia produktu w bazie danych”*.
> - **Polecenia CLI (`php bin/console file-manager:...`):** wbudowane narzędzia terminalowe do diagnostyki (`doctor`), czyszczenia pamięci podręcznej (`cache:clear`), wcześniejszego generowania miniatur (`thumbs:warmup`) czy czyszczenia przeterminowanego Kosza (`trash:purge`).

---

## 1. Zdarzenia Symfony (9 zdarzeń domenowych)

Każde zdarzenie menedżera plików dziedziczy po wspólnej klasie bazowej **`AbstractFileManagerEvent`** (`$profile`, `$volume`, `$path`, `$canonicalPath`). Nasłuchuj tych zdarzeń, aby synchronizować bazę danych, zapisywać logi audytowe, czyścić pamięć podręczną CDN lub uruchamiać zadania w tle.

| Klasa zdarzenia (`LiveUX\FileManagerBundle\Event\*`) | Kiedy jest wywoływane | Właściwości |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Przesłanie pliku, import z adresu URL, zapis w edytorze obrazów lub rozpakowanie archiwum ZIP | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Usunięcie pliku (przeniesienie do Kosza lub trwałe usunięcie) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Utworzenie folderu (w tym podczas rozpakowywania archiwum ZIP) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Usunięcie folderu | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Zmiana nazwy pliku lub folderu | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Przeniesienie lub skopiowanie elementu (również pomiędzy różnymi wolumenami) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Zduplikowanie/sklonowanie elementu | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Przywrócenie elementu z Kosza | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Trwałe usunięcie jednego lub wielu elementów z Kosza | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Przykład listenera zdarzeń (`#[AsEventListener]`)

```php
<?php
// src/EventListener/MediaAuditListener.php

declare(strict_types=1);

namespace App\EventListener;

use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class MediaAuditListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        $this->logger->info('Przesłano nowy zasób multimedialny.', [
            'profile' => $event->profile->name,
            'volume' => $event->volume->name,
            'path' => $event->path,
            'size' => $event->size,
            'mime' => $event->mimeType,
            'from_zip' => $event->fromArchiveExtraction,
        ]);
    }
}
```

---

## 2. Polecenia konsolowe (CLI) — 10 wbudowanych poleceń

| Polecenie | Flagi / Aliasy | Opis |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Sprawdza wersję PHP, `upload_max_filesize`, `post_max_size`, paczkę certyfikatów cURL CA, GD/Imagick, EXIF, WebP, `ext-zip` oraz konfigurację każdego profilu i wolumenu. Dodaj `--check-remote`, aby wykonać testy połączenia na żywo z S3/R2/Supabase/MinIO (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Czyści pamięć podręczną katalogów i limitów (quota) dla wszystkich profili lub wskazanego profilu/wolumenu/ścieżki (`--volume`/`--path` wymagają podania argumentu `profile`; polecenie nigdy nie usuwa niepowiązanych kluczy z `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Aliasy: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Z wyprzedzeniem generuje miniatury obrazów, dzięki czemu redaktorzy nie czekają przy pierwszym otwarciu folderu. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Czyści zapisane miniatury. Flaga `--orphans` usuwa wyłącznie osierocone miniatury, których plik źródłowy już nie istnieje (nie można jej łączyć z `--volume`/`--path`; automatycznie chroni współdzielone katalogi `{user_id}` i `{Y}/{m}/{d}`). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Wstecznie optymalizuje istniejące obrazy (limit bezpieczeństwa/pamięci: maks. `25 MB` na obraz). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Wykrywa duplikaty plików na podstawie sum kontrolnych SHA-256 i raportuje ilość miejsca możliwą do odzyskania. |
| `file-manager:stats [profile]` | `--json` | Raportuje zajętość dysku, liczbę plików/folderów, wykorzystanie limitu (quota), statystyki miniatur oraz Kosza. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(domyślnie `50`)*, `--dry-run` | Wsadowo generuje za pomocą AI brakujący tekst `alt`, tytuły, podpisy i tagi. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = usuń wszystko)*, `--dry-run` | Trwale usuwa przeterminowane elementy z Kosza. Gdy wiele profili współdzieli fizyczny katalog, automatycznie respektowana jest najdłuższa wartość `retention_days`. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Instaluje 6 wbudowanych skilli AI (AI Agent Skills) z pakietu do `.agents/skills`, `.claude/skills`, `.cursor/skills` lub `.github/skills`. |

> [!NOTE]
> **Ochrona dynamicznych ścieżek `{user_id}` i `{Y}/{m}/{d}` w poleceniach CLI**
> Ponieważ w trybie CLI nie istnieje uwierzytelniona sesja użytkownika, wszystkie polecenia skanujące i czyszczące (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) **automatycznie pomijają** wolumeny zawierające `{user_id}` (zapobiegając przypadkowemu utworzeniu pustego folderu `guest/`). W przypadku wolumenów z placeholderami daty `{Y}/{m}/{d}` skanowany jest wyłącznie bieżący okres oraz wyświetlany jest komunikat informacyjny.

### Przykład zadania Cron do automatycznego czyszczenia Kosza

```cron
# Trwałe usuwanie przeterminowanych elementów z Kosza codziennie o 03:00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Wydajność, architektura pamięci podręcznej i Symfony Profiler

- **3-poziomowy TTL pamięci podręcznej i ręczne odświeżanie (`↻`):** czas życia (TTL) listy katalogów jest wyznaczany w kolejności: **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` wyłącza cache dla danego wolumenu lub profilu). Kliknięcie **Odśwież (`↻`)** na pasku narzędzi lub naciśnięcie klawisza `R` wysyła `?refresh=1` *(pominięcie pamięci podręcznej wymaga uprawnienia `CLEAR_CACHE`)*.
- **Dedykowana pula cache i fingerprinting profili:** skieruj `defaults.cache.pool` na dedykowaną pulę Redis/APCu (jeśli istnieje `<pool>.taggable`, zostanie użyta automatycznie). Bez pamięci podręcznej z tagami `DirectoryCacheManager` korzysta z 30-dniowych kluczy liczników generacji i nigdy nie wywołuje `$cachePool->clear()` na głównej pamięci podręcznej aplikacji. Gdy dwa profile współdzielą ten sam fizyczny folder z różnymi regułami (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), ich wpisy w cache pozostają ściśle odizolowane dzięki `buildProfilePolicyFingerprint()`.
- **Synchronizacja kluczy kanonicznych:** jeśli dwa profile wskazują na ten sam fizyczny katalog, przesłanie lub modyfikacja pliku w jednym z nich automatycznie unieważnia również pamięć podręczną drugiego profilu.
- **Samonaprawiające się `remember_last_dir`:** jeśli ostatnio odwiedzony przez użytkownika katalog został usunięty z zewnątrz (np. przez FTP lub konsolę S3), menedżer plików płynnie cofa się do najbliższego istniejącego katalogu nadrzędnego lub katalogu głównego, zamiast zwracać błąd.
- **HTTP 304 i kontekstowe cache'owanie miniatur (`ThumbnailController`):**
  - **Kontekst prywatny / uwierzytelniony** (wolumen `private: true`, dynamiczny wolumen `{user_id}`, profil chroniony Voterem, obecny nagłówek `Authorization` lub ciasteczko sesyjne): wysyła `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization`, aby zapobiec wyciekowi zasobów przez współdzielony CDN.
  - **Kontekst publiczny:**
    - Z parametrem cache-buster `?v=<version>` → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 dni).
    - Bez `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 godzina + `ETag` / `304 Not Modified`).
    - Gdy na profilu ustawiono `cache.enabled: false` → `Cache-Control: public, no-cache, must-revalidate`.
  - Każda odpowiedź z miniaturą ustawia również nagłówki `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` oraz `Referrer-Policy: no-referrer`.
- **Symfony Web Debug Toolbar:** w środowisku `dev` panel profilera **FileManager** pokazuje aktywny profil, wolumen, sterownik miniatur oraz statystyki trafień/chybień pamięci podręcznej (hit/miss).

---

## 4. Globalna lokalizacja (`493` klucze), BCP-47 / POSIX / RTL i własne języki

LiveUX FileManagerBundle zawiera w standardzie **12 wbudowanych języków interfejsu** — **angielski (`en` — gwarantowany język zapasowy), turecki (`tr`), francuski (`fr`), niemiecki (`de`), hiszpański (`es`), arabski (`ar`), włoski (`it`), portugalski (`pt`), niderlandzki (`nl`), polski (`pl`), japoński (`ja`) oraz chiński uproszczony (`zh_CN`)** — z pełną zgodnością wszystkich **493 kluczy tłumaczeń**.

- **Obsługa BCP-47 i POSIX `.UTF-8`:** `MessageTranslator` automatycznie rozpoznaje kody regionalne oraz formaty POSIX, takie jak `tr_TR.UTF-8`, `de_DE@euro` czy `zh-Hans-CN` (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). Metoda `toBcp47()` generuje poprawne kody BCP-47 dla atrybutu HTML `lang`.
- **Automatyczne wykrywanie układu od prawej do lewej (RTL):** **15 języków i pism RTL** — w tym arabski (`ar`), perski (`fa`), hebrajski (`he`), urdu (`ur`), paszto (`ps`) oraz kurdyjski centralny (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa` itd.) — wykrywanych jest automatycznie, przełączając układ komponentu na `dir="rtl"`.
- **Lista dozwolonych katalogów tłumaczeń budowana w czasie kompilacji:** `FileManagerBundle` akceptuje wyłącznie kody językowe odpowiadające rzeczywistym katalogom tłumaczeń, co chroni przed rozrostem pamięci podręcznej/OPcache (DoS) przez spreparowane parametry `locale`.
- **Dodawanie nowego języka (np. szwedzkiego `sv`) lub częściowe nadpisania:**
  Wystarczy utworzyć w projekcie plik `translations/LiveUXFileManagerBundle.sv.yaml` (lub nadpisać wybrane klucze w istniejącym języku, np. `LiveUXFileManagerBundle.pl.yaml`). Każdy pominięty klucz automatycznie cofa się do języka angielskiego (`en`) — użytkownicy nigdy nie zobaczą surowych kluczy typu `ui.upload`. Jako kompletny szablon startowy możesz skopiować plik `translations/LiveUXFileManagerBundle.en.yaml` z pakietu.

---

## 5. Najczęstsze pytania i rozwiązywanie problemów (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Upewnij się, że `assets/app.js` zawiera `import '@liveux/file-manager-bundle/js/file_manager_api.js';`.
- Sprawdź, czy opcja `defaults.auto_inject_dialog_host: true` jest włączona (jeśli została wyłączona, dołącz ręcznie `DialogHost.html.twig` w `base.html.twig`).
- Uruchom `php bin/console cache:clear` i wykonaj twarde odświeżenie przeglądarki (`Ctrl+Shift+R`).

### 2. Każde żądanie POST zwraca `403 Invalid or missing CSRF token`
- Sprawdź, czy zainstalowano `symfony/security-csrf` oraz czy aktywne są obie opcje: `framework.csrf_protection: true` i `framework.session.enabled: true`.
- Jeśli wysyłasz własne żądania `fetch()` bezpośrednio, dołącz nagłówek `X-LiveUX-FM-CSRF`.

### 3. Otrzymuję błąd `403 Access denied`
- Sprawdź sekcję `permissions` dla swojego profilu w `liveux_file_manager.yaml`. Pamiętaj: nawet ustawienie uprawnienia na `true` wymaga uwierzytelnionego użytkownika, a uprawnienie `permissions.view` jest wymagane dla wszystkich pozostałych operacji.
- Jeśli zaimplementowałeś własny Symfony Voter, sprawdź ustawienie `access_decision_manager.strategy` (np. `unanimous` vs `affirmative`) w `config/packages/security.yaml`.

### 4. Przesyłanie dużych plików kończy się błędem (`upload_max_filesize`)
- Przy włączonej opcji `chunk_upload.enabled: true` rozmiar pojedynczej części (chunk) jest automatycznie ograniczany do `80%` limitów PHP `upload_max_filesize`/`post_max_size` (np. części `1.60 MB` przy limicie PHP `2M`, maksymalnie 2 000 części).
- Łączny dozwolony rozmiar pliku kontroluje opcja `upload_max_size` w `liveux_file_manager.yaml` (np. ustaw `upload_max_size: '100M'` dla plików wideo do 100 MB).

### 5. Pobieranie pliku psuje stronę (Symfony UX Turbo Drive)
- Wszystkie wbudowane linki pobierania mają już atrybuty `data-turbo="false" download`. Jeśli nadpisujesz szablony Twig, upewnij się, że Twoje niestandardowe tagi `<a>` do pobierania również zawierają `data-turbo="false"`.

### 6. Miniatury nie są generowane; serwowane są wyłącznie oryginalne obrazy
- Uruchom `php bin/console file-manager:doctor` i sprawdź, czy w wierszu `Thumbnail Driver` aktywny jest sterownik `gd` lub `imagick`.
- Upewnij się, że katalog `thumbnail.cache_dir` (`public/media-cache`) ma uprawnienia do zapisu dla użytkownika serwera WWW.

### 7. `cURL error 60: SSL certificate problem` w systemie Windows przy łączeniu z S3 / Supabase
- Pobierz aktualny plik `cacert.pem` i ustaw `curl.cainfo` oraz `openssl.cafile` w `php.ini`, albo wskaż `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` bezpośrednio na wolumenie S3.

### 8. Zmiany we frontendzie nie są widoczne w przeglądarce
- **Środowisko deweloperskie:** uruchom `php bin/console cache:clear` i wykonaj twarde odświeżenie (`Ctrl+Shift+R`).
- **Produkcja (AssetMapper):** uruchom `php bin/console asset-map:compile`.

---

[← Poprzedni: 07 — Pamięć w chmurze i dynamiczna konfiguracja](07-cloud-storage-and-dynamic-config.md) | [Spis treści](README.md) | [Następny: 09 — Integracja z Symfony AI →](09-symfony-ai-integration.md)
