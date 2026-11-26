# 06 — Bezpieczeństwo i autoryzacja (RBAC i ochrona wielowarstwowa)

[← Poprzedni: 05 — FormType i integracje z edytorami](05-formtype-and-editor-integrations.md) | [Spis treści](README.md) | [Następny: 07 — Pamięć w chmurze i dynamiczna konfiguracja →](07-cloud-storage-and-dynamic-config.md)

Menedżery plików stanowią jedną z najbardziej wrażliwych powierzchni ataku w każdej aplikacji webowej. LiveUX FileManagerBundle został zaprojektowany zgodnie z architekturą **ochrony wielowarstwowej (defense-in-depth)**, z domyślnie włączonymi wielopoziomowymi zabezpieczeniami.

> **W skrócie: Jak zabezpieczyć menedżer plików w 3 krokach**  
> 1. **Chroń trasy (`security.yaml`):** Zablokuj dostęp niezalogowanym odwiedzającym do endpointów menedżera plików.  
> 2. **Zdefiniuj uprawnienia ról (`permissions`):** Jawnie określ w każdym profilu, kto może przeglądać (`view`), wysyłać (`upload`) i usuwać (`delete`) pliki.  
> 3. **Dodaj `prefix` tras w aplikacjach z wieloma firewallami:** Jeśli Twoja aplikacja korzysta z osobnych firewalli dla panelu administracyjnego (`/admin`) i użytkowników publicznych (`/`), zamontuj trasy pakietu pod `prefix: /admin` w pliku `config/routes/liveux_file_manager.yaml`, aby sesja administratora była prawidłowo rozpoznawana, a zwykli użytkownicy nie mieli dostępu do plików admina.

---

## 1. Ochrona tras w `security.yaml` (pierwszy krok)

Na produkcji zawsze chroń wszystkie endpointy pod `/_liveux-file-manager` za firewallem Symfony:

```yaml
# config/packages/security.yaml (aplikacje z jednym firewallem)
security:
    access_control:
        # Zostaw publiczne tylko wtedy, gdy używasz podpisanych linków udostępniania dla odbiorców zewnętrznych:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Jeśli masz dwa osobne firewalle — np. panel administracyjny (`/admin`) oraz zwykłych użytkowników serwisu (`/`):**

```yaml
# config/routes/liveux_file_manager.yaml
liveux_file_manager:
    resource: '@FileManagerBundle/config/routes.php'
    prefix: /admin
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/admin/_liveux-file-manager, roles: IS_AUTHENTICATED_REMEMBERED }
```

---

## 2. Autoryzacja per profil (`permissions` i `FileManagerVoter`)

Każdy profil pozwala zdefiniować niezależne reguły dostępu dla **17 odrębnych operacji**.

### Co oznaczają wartości uprawnień?
- **`true`:** **Wyłącznie zalogowani użytkownicy (`$token->getUser() !== null`)!** *(NIGDY nie przyznaje dostępu anonimowym odwiedzającym.)*
- **`false`:** Nikt (operacja jest całkowicie wyłączona).
- **`'ROLE_ADMIN'`:** Wyłącznie użytkownicy posiadający tę konkretną rolę.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Użytkownicy posiadający którąkolwiek z wymienionych ról.

### 4 złote zasady autoryzacji (egzekwowane automatycznie)
1. **`permissions.view` to główna bramka dostępu:** Jeśli użytkownik nie ma uprawnienia `view`, to `upload`, `delete`, `download` i wszystkie pozostałe operacje są automatycznie odrzucane.
2. **Bezpieczna odmowa przy braku konfiguracji — Fail-Closed (`FileManagerAuthorizer`):** Gdy skonfigurowano `security.voter_enabled: true`, ale `AuthorizationCheckerInterface` (czyli `symfony/security-bundle`) nie jest zainstalowany, pakiet **bezpiecznie odmawia dostępu (`AccessDeniedException`)** zamiast go dopuszczać.
3. **Weryfikacja łańcucha katalogów nadrzędnych (`isPathAndAncestorsGranted`):** Gdy użytkownik uzyskuje dostęp do `confidential/2026/invoice.pdf`, Twoje własne Votery są automatycznie wywoływane nie tylko dla `confidential/2026/invoice.pdf`, ale również dla jego katalogów nadrzędnych **`confidential`** oraz **`confidential/2026`** — uniemożliwiając obejście zablokowanych folderów nadrzędnych przez zgadywanie adresów URL.
4. **Podwójna blokada rozpakowywania ZIP (`zip.allow_extract`):** Dopóki `zip.allow_extract: false`, rozpakowywanie archiwów jest blokowane po stronie serwera niezależnie od wartości `permissions.extract_zip`.

### Kompletna lista 17 uprawnień Votera

| Klucz YAML | Stała Votera (`FileManagerPermissions::*`) | Chronione operacje |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Listowanie katalogów, drzewo folderów, miniatury, podgląd inline, okna dialogowe oraz **warunek wstępny dla wszystkich pozostałych uprawnień** |
| `upload` | `FILE_MANAGER_UPLOAD` | Wysyłanie plików, wysyłanie w częściach (chunked upload), import ze zdalnego URL, zapisywanie z edytora obrazów |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Tworzenie nowych folderów |
| `rename` | `FILE_MANAGER_RENAME` | Zmiana nazw plików i folderów |
| `clone` | `FILE_MANAGER_CLONE` | Klonowanie/duplikowanie plików i folderów |
| `move` | `FILE_MANAGER_MOVE` | Przenoszenie elementów (przeciągnij i upuść oraz modal Przenieś — sprawdzana jest zarówno ścieżka źródłowa, jak i docelowa) |
| `copy` | `FILE_MANAGER_COPY` | Kopiowanie elementów (sprawdzana jest zarówno ścieżka źródłowa, jak i docelowa) |
| `delete` | `FILE_MANAGER_DELETE` | Usuwanie elementów (miękkie przeniesienie do kosza / trwałe usunięcie) oraz sprawdzanie użycia (`checkUsage`) |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Przywracanie elementów z kosza |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Trwałe usuwanie elementów lub opróżnianie kosza |
| `download` | `FILE_MANAGER_DOWNLOAD` | Pobieranie pojedynczych plików, strumieniowanie PDF/wideo oraz kopiowanie adresów URL |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Pobieranie wielu elementów jako archiwum ZIP (sprawdzane osobno dla każdej zaznaczonej ścieżki) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Bezpieczne rozpakowywanie ZIP (wymaga dodatkowo `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Generowanie wygasających, podpisanych przez HMAC linków udostępniania (wymaga dodatkowo `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Czyszczenie cache katalogów/profili/miniatur oraz parametrów omijających cache `?refresh=1` i `?fallback=1` |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Aktualizacja kolorów/ikon/przypięć folderów oraz tekstu `alt`, tytułów, tagów i punktów ostrości (focal point) plików |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | Sugestie metadanych generowane przez AI (wymaga `view` + `edit_metadata` + `ai.enabled: true`) |

### Przykładowa konfiguracja profilu korporacyjnego

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info'
    profiles:
        default:
            share:
                enabled: true
                default_ttl: 86400
                max_ttl: 2592000
                allow_inline_preview: true
            security:
                voter_enabled: true
                csrf_protection: true
                sanitize_svg: true
                allow_remote_url_import: false
                audit_log: null
            permissions:
                view: 'ROLE_USER'
                upload: ['ROLE_EDITOR', 'ROLE_ADMIN']
                create_folder: ['ROLE_EDITOR', 'ROLE_ADMIN']
                rename: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clone: ['ROLE_EDITOR', 'ROLE_ADMIN']
                move: ['ROLE_EDITOR', 'ROLE_ADMIN']
                copy: ['ROLE_EDITOR', 'ROLE_ADMIN']
                delete: 'ROLE_ADMIN'
                trash_restore: 'ROLE_ADMIN'
                trash_purge: 'ROLE_ADMIN'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                extract_zip: false
                share: ['ROLE_EDITOR', 'ROLE_ADMIN']
                clear_cache: 'ROLE_ADMIN'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: 'ROLE_ADMIN'
```

> [!TIP]
> **Interfejs świadomy uprawnień:** Przyciski akcji, do których użytkownik nie ma uprawnień (np. przycisk Usuń dla `ROLE_EDITOR`, gdy ustawiono `delete: 'ROLE_ADMIN'`), są automatycznie ukrywane w interfejsie **oraz** rygorystycznie blokowane statusem `HTTP 403 Forbidden` przy bezpośrednim wywołaniu API.

---

## 3. Pisanie własnego Symfony Votera (szczegółowe reguły dla folderów i użytkowników)

Gdy same role w YAML nie wystarczają — na przykład chcesz *„zablokować usuwanie folderu `locked/` oraz przenoszenie plików do `locked/`”* — napisz własnego Votera Symfony.

> [!WARNING]
> **Najczęstsza pułapka początkujących: `affirmative` kontra `unanimous`!**  
> Domyślna strategia `access_decision_manager.strategy` w Symfony to **`affirmative`** (wystarczy, że jeden Voter zwróci `true`, aby przyznać dostęp). Oznacza to, że jeśli wbudowany `FileManagerVoter` zwróci `true` dla użytkownika `ROLE_ADMIN`, to głos **`false` (odmowa)** Twojego własnego Votera **zostanie NADPISANY** i użytkownik nadal będzie mógł usunąć plik!  
>  
> Aby Twoje własne reguły **odmowy** w Voterze były faktycznie respektowane, ustaw strategię na **`unanimous`** w `config/packages/security.yaml` (każdy głosujący Voter musi wyrazić zgodę; pojedyncze `false` blokuje akcję):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(Pakiet automatycznie wykrywa własne Votery aplikacji poprzez `DetectExternalVotersPass` i filtruje za ich pomocą elementy w drzewie folderów oraz liczniki plików, zapewniając zarówno prywatność, jak i spójność danych.)*

### Właściwości obiektu `FileManagerSubject`

Obiekt `$subject` przekazywany do metody `voteOnAttribute()` Twojego Votera udostępnia 4 właściwości tylko do odczytu (`readonly`):

| Właściwość | Typ | Opis | Przykład |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Konfiguracja aktywnego profilu (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Względna ścieżka źródłowego pliku/folderu od katalogu głównego wolumenu | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Nazwa aktywnego wolumenu | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Ścieżka docelowa** dla operacji `MOVE`, `COPY`, `RENAME` lub `CLONE` (`null` dla wszystkich pozostałych) | `'locked/new-name.pdf'` |

### Przykład własnego Votera (blokada folderu `locked/` przed usuwaniem, zmianą nazwy i przenoszeniem)

```php
<?php
// src/Security/Voter/CustomMediaVoter.php

declare(strict_types=1);

namespace App\Security\Voter;

use LiveUX\FileManagerBundle\Security\FileManagerPermissions;
use LiveUX\FileManagerBundle\Security\FileManagerSubject;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, FileManagerSubject> */
final class CustomMediaVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof FileManagerSubject
            && in_array($attribute, [
                FileManagerPermissions::DELETE,
                FileManagerPermissions::MOVE,
                FileManagerPermissions::RENAME,
            ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // 1) Zablokuj usuwanie, przenoszenie lub zmianę nazwy folderu "locked" i wszystkiego w jego wnętrzu
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Zablokuj również przenoszenie lub zmianę nazwy plików DO wnętrza folderu "locked"
        if ($subject->targetPath !== null && $this->isLockedPath($subject->targetPath)) {
            return false;
        }

        return true;
    }

    private function isLockedPath(string $path): bool
    {
        return $path === 'locked' || str_starts_with($path, 'locked/');
    }
}
```

---

## 4. 14 wbudowanych warstw ochrony wielowarstwowej (odporność na realne ataki)

| # | Wektor ataku | Zabezpieczenie pakietu |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Każde żądanie `POST` (zapis, usuwanie, wysyłanie, ZIP, edytor obrazów) weryfikuje nagłówek `X-LiveUX-FM-CSRF` **przed** przetworzeniem jakichkolwiek danych wejściowych (`CsrfRequestValidator`). |
| 2 | **Path Traversal, sztuczki Unicode i systemowe** | `PathNormalizer` blokuje `../`, `..\`, bajty zerowe (`\0`), znaki sterujące, `C:\`, wrappery strumieni `phar://`/`php://`, końcowe kropki/spacje, znaki Unicode o zerowej szerokości/bidi, kropki CJK (`。`), strumienie NTFS Alternate Data Streams (`file.txt:stream`) oraz zastrzeżone nazwy urządzeń Windows (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), wymuszając maks. **32 poziomy zagnieżdżenia**, **255 bajtów/segment** oraz **1024 znaki UTF-8 / 3072 bajty** łącznej długości ścieżki. |
| 3 | **Pliki wykonywalne, poligloty i podwójne rozszerzenia** | `FileSecurityGuard` egzekwuje **69 zablokowanych rozszerzeń** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config` itd.), **47 zablokowanych typów MIME**, wykrywanie podwójnych rozszerzeń (`shell.php.jpg`) oraz walidację **magicznych bajtów (magic-bytes)** — ściśle przestrzeganą przy wysyłaniu, zmianie nazwy (nawet przy `allow_extension_change: true`) i pobieraniu. |
| 4 | **Bezpośredni dostęp do ukrytych folderów i kosza** | Ścieżki pod `.trash`, `.env`, `.git`, `.htaccess` i pasujące do `exclude_patterns` są odrzucane na wszystkich endpointach; `ZipDownloadController` wykonuje indywidualną weryfikację Votera `DOWNLOAD_ZIP` dla **każdej zaznaczonej ścieżki**. |
| 5 | **SVG XSS, XXE i bomby DoS z tagiem `<use>`** | `SvgSanitizer` odrzuca `<!DOCTYPE`/`<!ENTITY`, stosuje **ścisłą białą listę 55 tagów**, ogranicza rozmiar SVG do **5 MB**, filtruje składnię CSS (`@import`, `expression()`, `url(javascript:)`), usuwa `<script>`/`<foreignObject>`/`on*`/`javascript:` oraz ogranicza ataki DoS przez rozwinięcie `<use>` (maks. `100` elementów `<use>`, głębokość `4`, `1000` rozwiniętych węzłów, `5000` kroków analizy, `8` zduplikowanych ID). |
| 6 | **SSRF i DNS Rebinding (import ze zdalnego URL)** | `RemoteUrlImporter` jest domyślnie wyłączony (`allow_remote_url_import: false`). Po włączeniu waliduje adresy IP przez `inet_pton()`, blokując pętlę zwrotną (loopback), zakresy prywatne, `0.0.0.0`, CGNAT `100.64.0.0/10`, metadane chmury (`169.254.169.254`), adresy IPv4 mapowane na IPv6 (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6 oraz obejścia UTS-46 IDNA / pełnej szerokości / ósemkowe / szesnastkowe IP, podczas gdy **przypinanie IP przez `CURLOPT_RESOLVE`** eliminuje ataki DNS rebinding. |
| 7 | **RCE przez pliki poligloty w edytorze obrazów** | `ImageEditorController` ogranicza operację `overwrite` do istniejących bezpiecznych plików rastrowych (`jpg/jpeg/png/webp`) oraz egzekwuje `upload_max_size`, profilowe `upload_allow`/`upload_deny`, weryfikację Asset Usage Guard i rzeczywistą walidację `getimagesizefromstring()`. |
| 8 | **Bomby dekompresyjne (pikselowe) i ImageTragick** | `GdThumbnailDriver` i `ImagickThumbnailDriver` sprawdzają wymiary obrazu przez `getimagesizefromstring()` przed zdekodowaniem do pamięci (egzekwując maks. `8192×8192` i `40 MP`) oraz stosują ścisłe limity pamięci, czasu i wątków dla Imagick. |
| 9 | **Ucieczka przez dowiązania symboliczne (symlink) i izolacja** | `ConfinedLocalFilesystemAdapter` sprawdza `is_link()` + `realpath()` względem katalogu głównego na każdym segmencie ścieżki przy każdym lokalnym odczycie, zapisie, przenoszeniu, kopiowaniu i usuwaniu; rekurencyjne usuwanie/przenoszenie poddrzew zawierających symlinki jest odrzucane, a listowanie katalogów wymusza `SKIP_LINKS`. |
| 10 | **Zapobieganie kolizjom w trybie Multi-Tenant (`{user_id}`)** | `DynamicPathResolver` izoluje katalogi użytkowników unikalnymi sufiksami SHA-256 `<slug>--h-<16-hex>` dla adresów e-mail, zróżnicowanej wielkości liter, znaków Unicode, nazw zastrzeżonych (`guest`, `user`) oraz nazw urządzeń Windows — czyniąc kolizje katalogów między dwoma użytkownikami niemożliwymi. |
| 11 | **Izolacja pobierania, miniatur i cache CDN** | `DownloadController`, `ThumbnailController` i `ZipDownloadController` wysyłają nagłówki `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` oraz `Content-Security-Policy: default-src 'none'; sandbox`; wymuszają `Cache-Control: private` + `Vary: Cookie, Authorization` w kontekstach prywatnych / z Voterem / z sesją / z `{user_id}`; wymagają uprawnienia `CLEAR_CACHE` dla `?refresh=1` i `?fallback=1`; oraz wymuszają `Content-Disposition: attachment` dla HTML/JS/SVG/XML. |
| 12 | **Ochrona żądań POST w FormType i pochodzenia URL w Twig** | `FileManagerPickerType` odrzuca zmanipulowane ładunki POST po stronie serwera (protokoły XSS, `../`, `.trash/`, JSON > 256 KB, > 500 elementów, niepoprawny UTF-8, przekroczenie `max_files`, tablice HPP); `FileManagerTwigExtension` oraz funkcja `isSafeUrl()` na froncie blokują podstawianie zewnętrznych/niebezpiecznych adresów URL. |
| 13 | **Dowolne katalogi motywów/ikon i DOM XSS** | Parametry runtime `dialog?theme=` akceptują wyłącznie 9 wbudowanych motywów, a `dialog?iconSet=` wyłącznie 6 wbudowanych zestawów ikon; `sanitizeHexColor()` zapobiega wstrzykiwaniu kolorów; katalogi tymczasowe fragmentów (chunk staging) są izolowane per użytkownik (`0750`). |
| 14 | **Ochrona przed DoS, Log Injection i izolacja cache** | Masowe usuwanie ograniczone jest do 200 elementów, wysyłanie w częściach do 2000 fragmentów, a rekurencyjne kopiowanie folderów do 5000 elementów; `AuditLogListener` oczyszcza dane z prób log-injection (`\r`/`\n`/ANSI/znaki sterujące); `DirectoryCacheManager` nigdy nie czyści całej puli `cache.app` aplikacji hostującej (nigdy nie wywołuje `clear()`). |

---

## 5. Utwardzanie serwera WWW (Apache i Nginx)

Jako dodatkową warstwę obrony zawsze wyłącz wykonywanie skryptów PHP i innych skryptów wewnątrz publicznie serwowanych katalogów z plikami, takich jak `public/uploads`, bezpośrednio na poziomie serwera WWW:

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

---

## 6. Podpisane linki udostępniania i strażnik użycia zasobów

- **Podpisane, wygasające linki udostępniania (`ShareController`):** Gdy `share.enabled: true`, tymczasowe adresy URL są podpisywane przez `UriSigner` z Symfony (HMAC + znacznik czasu `expires`). Ustawienie `share.enabled: false` na profilu natychmiast unieważnia wszystkie istniejące linki kodem `403 Forbidden`, wygasłe linki zwracają `410 Gone`, a wszystkie odpowiedzi zawierają `Cache-Control: private, no-store` oraz restrykcyjne nagłówki CSP.
- **Strażnik użycia zasobów — Asset Usage Guard (`AssetUsageCheckerInterface`):** Zapobiega usuwaniu, zmianie nazwy, przenoszeniu lub nadpisywaniu plików aktywnie używanych w bazie danych (zobacz [10 — Strażnik użycia zasobów](10-asset-usage-guard.md)).

---

## 7. Dziennik audytu — Konfiguracja produkcyjna i przykłady

`AuditLogListener` rejestruje wszystkie 9 zdarzeń modyfikacji plików i folderów w ustrukturyzowanym kontekście PSR-3 (`action`, `profile`, `volume`, `path`, `user`, `ip` oraz metadane specyficzne dla zdarzenia) i automatycznie usuwa znaki sterujące/CRLF/BiDi, aby zapobiec atakom typu log injection.

### Krok 1: Włącz dziennik audytu (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Włącz lub nadpisz dla konkretnego profilu
        public_temp:
            security:
                audit_log: false
```

### Krok 2: Przekieruj wpisy audytu do osobnego, rotowanego pliku JSON (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # 90-dniowa retencja i rotacja
            channels: ['file_manager_audit']
            formatter: monolog.formatter.json
```

```yaml
# config/services.yaml
services:
    LiveUX\FileManagerBundle\EventListener\AuditLogListener:
        arguments:
            $logger: '@monolog.logger.file_manager_audit'
```

### Przykładowy ustrukturyzowany wpis JSON

```json
{
  "message": "[LiveUX FileManager Audit] {action} \"{path}\"",
  "context": {
    "action": "file.uploaded",
    "profile": "default",
    "volume": "media",
    "path": "Products/nordic-thermos.webp",
    "user": "admin@example.com",
    "ip": "203.0.113.42",
    "size": 45128,
    "mime": "image/webp"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "file_manager_audit",
  "datetime": "2026-11-26T09:15:42.128412+00:00"
}
```

### Wszystkie 9 audytowanych zdarzeń cyklu życia

| Zdarzenie Symfony | Klucz `action` | Dodatkowe pola kontekstu |
| :--- | :--- | :--- |
| `FileUploadedEvent` | `file.uploaded` | `size`, `mime` |
| `FileDeletedEvent` | `file.deleted` | `movedToTrash` (`bool`) |
| `FolderCreatedEvent` | `folder.created` | — |
| `FolderDeletedEvent` | `folder.deleted` | `movedToTrash` (`bool`) |
| `ItemRenamedEvent` | `file.renamed` / `folder.renamed` | `newPath` |
| `ItemMovedEvent` | `file.moved` / `file.copied` / `folder.moved` / `folder.copied` | `targetVolume`, `targetPath` |
| `ItemClonedEvent` | `item.cloned` | `clonedPath` |
| `TrashRestoredEvent` | `trash.restored` | `restoredPath` |
| `TrashPurgedEvent` | `trash.purged` | `purgedCount` |

### Krok 3 (opcjonalnie): Zapisywanie dziennika audytu w tabeli bazy danych Doctrine

Aby udostępnić przeszukiwalną historię operacji w panelu administracyjnym, zasubskrybuj te same zdarzenia Symfony:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FileManagerAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use LiveUX\FileManagerBundle\Event\AbstractFileManagerEvent;
use LiveUX\FileManagerBundle\Event\FileDeletedEvent;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use LiveUX\FileManagerBundle\Event\ItemMovedEvent;
use LiveUX\FileManagerBundle\Event\ItemRenamedEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DatabaseFileAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FileUploadedEvent::class => 'onUploaded',
            FileDeletedEvent::class => 'onDeleted',
            ItemRenamedEvent::class => 'onRenamed',
            ItemMovedEvent::class => 'onMoved',
        ];
    }

    public function onUploaded(FileUploadedEvent $e): void
    {
        $this->save($e, 'file.uploaded', ['size' => $e->size, 'mime' => $e->mimeType]);
    }

    public function onDeleted(FileDeletedEvent $e): void
    {
        $this->save($e, 'file.deleted', ['movedToTrash' => $e->movedToTrash]);
    }

    public function onRenamed(ItemRenamedEvent $e): void
    {
        $this->save($e, $e->isFolder ? 'folder.renamed' : 'file.renamed', ['newPath' => $e->newPath]);
    }

    public function onMoved(ItemMovedEvent $e): void
    {
        $verb = $e->isCopy ? 'copied' : 'moved';
        $this->save($e, ($e->isFolder ? 'folder.' : 'file.') . $verb, [
            'targetVolume' => $e->targetVolume->name,
            'targetPath' => $e->targetPath,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function save(AbstractFileManagerEvent $event, string $action, array $meta = []): void
    {
        $entry = (new FileManagerAuditLog())
            ->setAction($action)
            ->setProfile($event->profile->name)
            ->setVolume($event->volume->name)
            ->setPath($event->path)
            ->setUserIdentifier($this->security->getUser()?->getUserIdentifier() ?? 'anonymous')
            ->setClientIp($this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli')
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```

> [!TIP]
> W aplikacjach o dużym ruchu wysyłaj asynchroniczną wiadomość przez Symfony **Messenger** zamiast wywoływać `$this->em->flush()` synchronicznie, aby operacje na plikach pozostały nieblokujące.

---

[← Poprzedni: 05 — FormType i integracje z edytorami](05-formtype-and-editor-integrations.md) | [Spis treści](README.md) | [Następny: 07 — Pamięć w chmurze i dynamiczna konfiguracja →](07-cloud-storage-and-dynamic-config.md)
