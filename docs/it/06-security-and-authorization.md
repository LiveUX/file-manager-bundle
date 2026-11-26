# 06 — Sicurezza e Autorizzazioni (RBAC e Defense-in-Depth)

[← Precedente: 05 — FormType e Integrazioni Editor](05-formtype-and-editor-integrations.md) | [Indice](README.md) | [Successivo: 07 — Cloud Storage e Configurazione Dinamica →](07-cloud-storage-and-dynamic-config.md)

I file manager rappresentano una delle superfici d'attacco più critiche in qualsiasi applicazione web. LiveUX FileManagerBundle è progettato attorno a un'architettura **defense-in-depth** con protezioni multilivello abilitate di default.

> **In sintesi: come mettere in sicurezza il bundle in 3 passi**  
> 1. **Proteggi le rotte (`security.yaml`):** impedisci ai visitatori non autenticati di raggiungere gli endpoint del file manager.  
> 2. **Definisci i permessi per ruolo (`permissions`):** dichiara esplicitamente in ogni profilo chi può visualizzare (`view`), caricare (`upload`) ed eliminare (`delete`).  
> 3. **Aggiungi un `prefix` alle rotte nelle app multi-firewall:** se la tua applicazione utilizza firewall separati per il pannello admin (`/admin`) e per gli utenti pubblici (`/`), monta le rotte del bundle sotto `prefix: /admin` in `config/routes/liveux_file_manager.yaml` in modo che la sessione admin venga risolta correttamente e gli utenti normali non possano accedere ai file di amministrazione.

---

## 1. Protezione delle rotte in `security.yaml` (Primo passo)

In produzione, proteggi sempre tutti gli endpoint sotto `/_liveux-file-manager` dietro il firewall di Symfony:

```yaml
# config/packages/security.yaml (App con un singolo firewall)
security:
    access_control:
        # Lascia pubblico solo se utilizzi link di condivisione firmati per destinatari esterni:
        - { path: ^/_liveux-file-manager/share$, roles: PUBLIC_ACCESS }
        - { path: ^/_liveux-file-manager, roles: ROLE_USER }
```

**Se disponi di due firewall separati — ad esempio un'area admin (`/admin`) e gli utenti standard del sito (`/`):**

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

## 2. Autorizzazione basata sui profili (`permissions` e `FileManagerVoter`)

Ogni profilo ti consente di definire regole di accesso indipendenti per **17 operazioni distinte**.

### Cosa significano i valori dei permessi?
- **`true`:** **Solo utenti autenticati (`$token->getUser() !== null`)!** *(Non concede MAI l'accesso a visitatori anonimi.)*
- **`false`:** Nessuno (l'operazione è completamente disabilitata).
- **`'ROLE_ADMIN'`:** Solo gli utenti che possiedono questo specifico ruolo.
- **`['ROLE_EDITOR', 'ROLE_ADMIN']`:** Gli utenti che possiedono almeno uno dei ruoli elencati.

### Le 4 regole d'oro dell'autorizzazione (applicate automaticamente)
1. **`permissions.view` è il cancello principale:** se un utente non dispone del permesso `view`, tutte le altre operazioni (`upload`, `delete`, `download`, ecc.) vengono negate automaticamente.
2. **Fail-Closed (`FileManagerAuthorizer`):** quando `security.voter_enabled: true` è configurato ma `AuthorizationCheckerInterface` (cioè `symfony/security-bundle`) non è installato, il bundle **nega l'accesso in sicurezza (`AccessDeniedException`)** invece di lasciarlo aperto.
3. **Controllo della catena di directory antenate (`isPathAndAncestorsGranted`):** quando un utente accede a `confidential/2026/invoice.pdf`, i tuoi Voter personalizzati vengono valutati automaticamente non solo per `confidential/2026/invoice.pdf`, ma anche per le cartelle padre **`confidential`** e **`confidential/2026`** — impedendo che un URL indovinato aggiri le restrizioni sulle directory genitore.
4. **Doppio blocco per l'estrazione ZIP (`zip.allow_extract`):** finché `zip.allow_extract: false`, l'estrazione di archivi viene rifiutata lato server indipendentemente dal valore di `permissions.extract_zip`.

### Riferimento completo dei 17 permessi Voter

| Chiave YAML | Costante Voter (`FileManagerPermissions::*`) | Operazioni protette |
| :--- | :--- | :--- |
| `view` | `FILE_MANAGER_VIEW` | Elenco directory, albero cartelle, miniature, anteprime inline, dialog e **prerequisito per tutti gli altri permessi** |
| `upload` | `FILE_MANAGER_UPLOAD` | Caricamento file, upload a blocchi (chunked), importazione da URL remoto, salvataggi dell'editor immagini |
| `create_folder` | `FILE_MANAGER_CREATE_FOLDER` | Creazione di nuove cartelle |
| `rename` | `FILE_MANAGER_RENAME` | Rinomina di file e cartelle |
| `clone` | `FILE_MANAGER_CLONE` | Clonazione/duplicazione di file e cartelle |
| `move` | `FILE_MANAGER_MOVE` | Spostamento di elementi (drag-and-drop e modale Sposta — vengono controllati sia il percorso sorgente che quello di destinazione) |
| `copy` | `FILE_MANAGER_COPY` | Copia di elementi (vengono controllati sia il percorso sorgente che quello di destinazione) |
| `delete` | `FILE_MANAGER_DELETE` | Eliminazione di elementi (cestino / eliminazione definitiva) e verifiche `checkUsage` |
| `trash_restore` | `FILE_MANAGER_TRASH_RESTORE` | Ripristino di elementi dal cestino |
| `trash_purge` | `FILE_MANAGER_TRASH_PURGE` | Eliminazione permanente di elementi o svuotamento del cestino |
| `download` | `FILE_MANAGER_DOWNLOAD` | Download di singoli file, streaming PDF/video e copia degli URL |
| `download_zip` | `FILE_MANAGER_DOWNLOAD_ZIP` | Download di archivi ZIP multi-elemento (verificato singolarmente per ogni percorso selezionato) |
| `extract_zip` | `FILE_MANAGER_EXTRACT_ZIP` | Estrazione ZIP sicura (richiede inoltre `zip.allow_extract: true`) |
| `share` | `FILE_MANAGER_SHARE` | Generazione di link di condivisione firmati HMAC con scadenza (richiede inoltre `share.enabled: true`) |
| `clear_cache` | `FILE_MANAGER_CLEAR_CACHE` | Pulizia delle cache di directory/profilo/miniature, oltre ai bypass della cache `?refresh=1` e `?fallback=1` |
| `edit_metadata` | `FILE_MANAGER_EDIT_METADATA` | Aggiornamento di colori/icone/pin delle cartelle e testi `alt`, titoli, tag e punti focali dei file |
| `ai_enrich` | `FILE_MANAGER_AI_ENRICH` | Suggerimenti di metadati basati su AI (richiede `view` + `edit_metadata` + `ai.enabled: true`) |

### Esempio di configurazione profilo enterprise

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
> **Permessi sincronizzati con l'interfaccia:** i pulsanti delle azioni per cui un utente non è autorizzato (ad esempio il pulsante Elimina per un `ROLE_EDITOR` quando `delete: 'ROLE_ADMIN'`) vengono nascosti automaticamente nella UI **e** bloccati rigorosamente con `HTTP 403 Forbidden` in caso di chiamata API diretta.

---

## 3. Scrivere un Voter Symfony personalizzato (Regole granulari per cartelle e utenti)

Quando i ruoli YAML non bastano — ad esempio: *"impedisci agli utenti di eliminare la cartella `locked/` o di spostare file all'interno di `locked/`"* — scrivi il tuo Symfony Voter personalizzato.

> [!WARNING]
> **L'errore più comune per chi inizia: `affirmative` vs. `unanimous`!**  
> La strategia predefinita `access_decision_manager.strategy` di Symfony è **`affirmative`** (basta che un solo Voter restituisca `true` per concedere l'accesso). Di conseguenza, se il `FileManagerVoter` integrato restituisce `true` per un utente `ROLE_ADMIN`, il voto **`false` (nega)** del tuo Voter personalizzato viene **IGNORATO** e l'utente potrà comunque eliminare il file!  
>  
> Per assicurarti che le regole di **diniego** del tuo Voter personalizzato vengano effettivamente applicate, imposta la strategia su **`unanimous`** in `config/packages/security.yaml` (tutti i Voter votanti devono approvare; un solo `false` blocca l'azione):
> ```yaml
> # config/packages/security.yaml
> security:
>     access_decision_manager:
>         strategy: unanimous
> ```
> *(Il bundle rileva automaticamente i Voter applicativi personalizzati tramite `DetectExternalVotersPass` ed esegue il filtraggio per singolo elemento sull'albero delle cartelle e sui contatori dei file per preservare sia la privacy che l'accuratezza.)*

### Proprietà di `FileManagerSubject`

Il parametro `$subject` passato al metodo `voteOnAttribute()` del tuo Voter espone 4 proprietà `readonly`:

| Proprietà | Tipo | Descrizione | Esempio |
| :--- | :--- | :--- | :--- |
| `$subject->profile` | `ProfileConfig` | Configurazione del profilo attivo (`$subject->profile->name`) | `'default'` |
| `$subject->path` | `string` | Percorso relativo del file/cartella sorgente dalla radice del volume | `'locked/contract.pdf'` |
| `$subject->volume` | `?string` | Nome del volume attivo | `'local_uploads'` |
| `$subject->targetPath` | `?string` | **Percorso di destinazione** per operazioni `MOVE`, `COPY`, `RENAME` o `CLONE` (`null` per tutte le altre) | `'locked/new-name.pdf'` |

### Esempio di Voter personalizzato (blocco della cartella `locked/` contro eliminazioni, rinomine e spostamenti)

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
        // 1) Impedisce di eliminare, spostare o rinominare "locked" o qualsiasi elemento al suo interno
        if ($this->isLockedPath($subject->path)) {
            return false;
        }

        // 2) Impedisce anche di spostare o rinominare file DENTRO la cartella "locked"
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

## 4. I 14 livelli integrati di Defense-in-Depth (temprati contro attacchi reali)

| # | Vettore d'attacco | Protezione del bundle |
| :---: | :--- | :--- |
| 1 | **CSRF (Cross-Site Request Forgery)** | Ogni richiesta `POST` di scrittura, eliminazione, caricamento, ZIP ed editor immagini valida l'header `X-LiveUX-FM-CSRF` **prima** di elaborare qualsiasi input (`CsrfRequestValidator`). |
| 2 | **Path Traversal, Unicode e trucchi del SO** | `PathNormalizer` blocca `../`, `..\`, byte nulli (`\0`), caratteri di controllo, `C:\`, wrapper di stream `phar://`/`php://`, punti/spazi finali, caratteri Unicode zero-width/bidi, punti CJK (`。`), NTFS Alternate Data Streams (`file.txt:stream`) e nomi di dispositivo Windows (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`, `CONIN$`, `CONOUT$`), imponendo un massimo di **32 livelli di profondità**, **255 byte/segmento** e **1024 caratteri UTF-8 / 3072 byte** di lunghezza totale del percorso. |
| 3 | **File eseguibili, polyglot e doppie estensioni** | `FileSecurityGuard` impone **69 estensioni bloccate** (`.php*`, `.phtml`, `.phar`, `.html`, `.shtml`, `.cgi`, `.aspx`, `.exe`, `.sh`, `.htaccess`, `.user.ini`, `.env`, `.meta.json`, `web.config`, ecc.), **47 tipi MIME bloccati**, controlli sulle doppie estensioni (`shell.php.jpg`) e validazione **polyglot tramite magic-bytes** — applicata rigorosamente a upload, rinomine (anche con `allow_extension_change: true`) e download. |
| 4 | **Accesso diretto a cartelle nascoste e Cestino** | I percorsi sotto `.trash`, `.env`, `.git`, `.htaccess` ed `exclude_patterns` vengono rifiutati in tutti gli endpoint; `ZipDownloadController` esegue un controllo Voter `DOWNLOAD_ZIP` individuale su **ogni percorso selezionato**. |
| 5 | **SVG XSS, XXE e bombe DoS `<use>`** | `SvgSanitizer` rifiuta `<!DOCTYPE`/`<!ENTITY`, impone una **rigida allowlist di 55 tag**, limita la dimensione SVG a **5 MB**, filtra la sintassi CSS (`@import`, `expression()`, `url(javascript:)`), rimuove `<script>`/`<foreignObject>`/`on*`/`javascript:` e limita il DoS da espansione `<use>` (max `100` `<use>`, profondità `4`, `1000` nodi espansi, `5000` passi di analisi, `8` ID duplicati). |
| 6 | **SSRF e DNS Rebinding (Importazione URL remoti)** | `RemoteUrlImporter` è disabilitato di default (`allow_remote_url_import: false`). Quando abilitato, valida gli IP tramite `inet_pton()` per bloccare loopback, reti private, `0.0.0.0`, CGNAT `100.64.0.0/10`, metadati cloud (`169.254.169.254`), IPv6 IPv4-mapped (`::ffff:127.0.0.1`), NAT64 (`64:ff9b::`), SIIT/ORCHID/SRv6 e bypass UTS-46 IDNA / full-width / IP ottali / esadecimali, mentre il **pinning IP `CURLOPT_RESOLVE`** elimina gli attacchi DNS rebinding. |
| 7 | **RCE Polyglot nell'editor immagini** | `ImageEditorController` limita `overwrite` a file raster sicuri esistenti (`jpg/jpeg/png/webp`) e applica `upload_max_size`, `upload_allow`/`upload_deny` del profilo, controlli Usage Guard e validazione reale con `getimagesizefromstring()`. |
| 8 | **Bombe di decompressione (Pixel Bomb) e ImageTragick** | `GdThumbnailDriver` e `ImagickThumbnailDriver` ispezionano le dimensioni tramite `getimagesizefromstring()` prima di decodificare in memoria (imponendo un massimo di `8192×8192` e `40 MP`) e applicano limiti rigorosi di memoria, tempo e thread per Imagick. |
| 9 | **Symlink Escape e confinamento** | `ConfinedLocalFilesystemAdapter` verifica `is_link()` + il confinamento nella radice tramite `realpath()` su ogni segmento del percorso per ogni lettura, scrittura, spostamento, copia ed eliminazione locale; eliminazioni/spostamenti ricorsivi di sottoalberi contenenti symlink vengono rifiutati e l'elenco directory applica `SKIP_LINKS`. |
| 10 | **Prevenzione collisioni Multi-Tenant (`{user_id}`)** | `DynamicPathResolver` isola le directory utente con suffissi SHA-256 distinti `<slug>--h-<16-hex>` per email, maiuscole/minuscole, Unicode, nomi riservati (`guest`, `user`) e nomi di dispositivo Windows — rendendo impossibili le collisioni di directory tra due utenti. |
| 11 | **Isolamento cache per Download, Miniature e CDN** | `DownloadController`, `ThumbnailController` e `ZipDownloadController` inviano `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` e `Content-Security-Policy: default-src 'none'; sandbox`; impongono `Cache-Control: private` + `Vary: Cookie, Authorization` in contesti privati/Voter/sessione/`{user_id}`; richiedono `CLEAR_CACHE` per `?refresh=1` e `?fallback=1`; forzano `Content-Disposition: attachment` per HTML/JS/SVG/XML. |
| 12 | **Protezione POST di FormType e origine Twig** | `FileManagerPickerType` rifiuta payload POST manomessi lato server (protocolli XSS, `../`, `.trash/`, JSON > 256 KB, > 500 elementi, UTF-8 non valido, superamento di `max_files`, array HPP); `FileManagerTwigExtension` e `isSafeUrl()` lato frontend impediscono lo spoofing di URL esterni o pericolosi. |
| 13 | **Directory arbitrarie di temi/icone e DOM XSS** | A runtime `dialog?theme=` accetta solo i 9 temi integrati e `dialog?iconSet=` accetta solo i 6 set di icone integrati; `sanitizeHexColor()` previene l'iniezione di colori; le directory di staging dei chunk sono isolate per utente (`0750`). |
| 14 | **DoS, Log Injection e isolamento della cache** | L'eliminazione massiva è limitata a 200 elementi, gli upload a blocchi a 2000 chunk e le copie ricorsive di cartelle a 5000 elementi; `AuditLogListener` rimuove payload di log-injection (`\r`/`\n`/ANSI/caratteri di controllo); `DirectoryCacheManager` non svuota mai il pool `cache.app` dell'applicazione ospite (non chiama mai `clear()`). |

---

## 5. Hardening del Web Server (Apache e Nginx)

Come livello di difesa aggiuntivo, disabilita sempre l'esecuzione di PHP e script all'interno delle directory di upload pubbliche come `public/uploads` direttamente a livello di web server:

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

## 6. Link di Condivisione Firmati e Asset Usage Guard

- **Link di condivisione firmati con scadenza (`ShareController`):** quando `share.enabled: true`, gli URL temporanei vengono firmati con `UriSigner` di Symfony (HMAC + timestamp `expires`). Impostando `share.enabled: false` su un profilo si revocano immediatamente tutti i link esistenti con `403 Forbidden`, i link scaduti restituiscono `410 Gone` e tutte le risposte includono `Cache-Control: private, no-store` oltre a un rigoroso hardening CSP.
- **Asset Usage Guard (`AssetUsageCheckerInterface`):** impedisce di eliminare, rinominare, spostare o sovrascrivere file attualmente referenziati nel tuo database (vedi [10 — Asset Usage Guard](10-asset-usage-guard.md)).

---

## 7. Audit Logging — Configurazione in Produzione ed Esempi

`AuditLogListener` registra tutti i 9 eventi di modifica di file e cartelle con contesto PSR-3 strutturato (`action`, `profile`, `volume`, `path`, `user`, `ip`, oltre ai metadati specifici dell'evento) e rimuove automaticamente i caratteri di controllo/CRLF/BiDi per prevenire la log injection.

### Passo 1: Abilita l'Audit Logging (`config/packages/liveux_file_manager.yaml`)

```yaml
liveux_file_manager:
    defaults:
        audit_log:
            enabled: true
            level: 'info' # debug | info | notice | warning

    profiles:
        default:
            security:
                audit_log: true # Abilita o sovrascrivi per singolo profilo
        public_temp:
            security:
                audit_log: false
```

### Passo 2: Indirizza le Voci di Audit verso un Log JSON Dedicato con Rotazione (`monolog.yaml` + `services.yaml`)

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['file_manager_audit']
    handlers:
        file_manager_audit:
            type: rotating_file
            path: '%kernel.logs_dir%/file_manager_audit.log'
            level: info
            max_files: 90 # Conservazione e rotazione di 90 giorni
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

### Esempio di Output JSON Strutturato

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

### Tutti i 9 Eventi del Ciclo di Vita Registrati

| Evento Symfony | Chiave `action` | Campi di Contesto Extra |
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

### Passo 3 (Opzionale): Salvare i Log di Audit in una Tabella Doctrine

Per esporre uno storico di audit ricercabile all'interno del tuo pannello di amministrazione, iscriviti agli stessi eventi Symfony:

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
> Nelle applicazioni ad alto traffico, invia un messaggio asincrono tramite Symfony **Messenger** invece di chiamare `$this->em->flush()` in linea, così da mantenere non bloccanti le operazioni sui file.

---

[← Precedente: 05 — FormType e Integrazioni Editor](05-formtype-and-editor-integrations.md) | [Indice](README.md) | [Successivo: 07 — Cloud Storage e Configurazione Dinamica →](07-cloud-storage-and-dynamic-config.md)
