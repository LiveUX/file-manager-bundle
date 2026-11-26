# 08 — Eventi, Comandi CLI, Cache, Traduzioni e Risoluzione dei Problemi (FAQ)

[← Precedente: 07 — Cloud Storage e Configurazione Dinamica](07-cloud-storage-and-dynamic-config.md) | [Indice Principale](README.md) | [Successivo: 09 — Integrazione Symfony AI →](09-symfony-ai-integration.md)

> [!TIP]
> **In Sintesi: A Cosa Servono Eventi e Comandi CLI?**
> - **Eventi:** notificano automaticamente la tua applicazione Symfony ogni volta che un utente carica, elimina, rinomina o sposta un file — ad esempio: *"quando un file viene rinominato, aggiorna automaticamente il percorso dell'immagine prodotto nel database"*.
> - **Comandi CLI (`php bin/console file-manager:...`):** strumenti da terminale integrati per eseguire controlli diagnostici (`doctor`), invalidare le cache (`cache:clear`), pre-generare le miniature (`thumbs:warmup`) o svuotare gli elementi scaduti dal cestino (`trash:purge`).

---

## 1. Eventi Symfony (9 Eventi di Dominio)

Ogni evento del file manager estende la classe base comune **`AbstractFileManagerEvent`** (`$profile`, `$volume`, `$path`, `$canonicalPath`). Ascolta questi eventi per mantenere sincronizzato il database, registrare audit log, svuotare le cache CDN o inviare job asincroni in background.

| Classe Evento (`LiveUX\FileManagerBundle\Event\*`) | Emesso Quando | Proprietà |
| :--- | :--- | :--- |
| `FileUploadedEvent` | Un file viene caricato, importato da URL, salvato dall'Image Editor o estratto da un archivio ZIP | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$size`, `$mimeType`, `$fromArchiveExtraction` |
| `FileDeletedEvent` | Un file viene eliminato (spostato nel cestino o eliminato definitivamente) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath` |
| `FolderCreatedEvent` | Viene creata una cartella (anche durante l'estrazione di un archivio ZIP) | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$fromArchiveExtraction` |
| `FolderDeletedEvent` | Una cartella viene eliminata | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$movedToTrash`, `$trashCanonicalPath`, `$deletedChildCanonicalPaths`, `$deletedChildPaths` |
| `ItemRenamedEvent` | Un file o una cartella viene rinominato | `$profile`, `$volume`, `$oldPath`, `$oldCanonicalPath`, `$newPath`, `$newCanonicalPath`, `$isFolder` |
| `ItemMovedEvent` | Un elemento viene spostato o copiato (inclusi trasferimenti tra volumi diversi) | `$profile`, `$sourceVolume`, `$sourcePath`, `$sourceCanonicalPath`, `$targetVolume`, `$targetPath`, `$targetCanonicalPath`, `$isFolder`, `$isCopy` |
| `ItemClonedEvent` | Un elemento viene duplicato/clonato | `$profile`, `$volume`, `$sourcePath`, `$sourceCanonicalPath`, `$clonedPath`, `$clonedCanonicalPath`, `$isFolder` |
| `TrashRestoredEvent` | Un elemento viene ripristinato dal cestino | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$originalCanonicalPath`, `$isFolder` |
| `TrashPurgedEvent` | Uno o più elementi vengono eliminati definitivamente dal cestino | `$profile`, `$volume`, `$path`, `$canonicalPath`, `$purgedCount`, `$originalCanonicalPath`, `$trashCanonicalPath` |

### Esempio di Event Listener (`#[AsEventListener]`)

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
        $this->logger->info('Nuovo asset media caricato.', [
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

## 2. Comandi Console (CLI) (10 Comandi Integrati)

| Comando | Opzioni / Alias | Descrizione |
| :--- | :--- | :--- |
| `file-manager:doctor` | `--check-remote` | Verifica versione PHP, `upload_max_filesize`, `post_max_size`, cURL CA Bundle, GD/Imagick, EXIF, WebP, `ext-zip` e la configurazione di ogni profilo/volume. Aggiungi `--check-remote` per eseguire test di connettività live verso S3/R2/Supabase/MinIO (`ListObjectsV2`). |
| `file-manager:cache:clear [profile]` | `--volume`, `--path` | Svuota le cache delle directory e delle quote per tutti i profili o per uno specifico profilo/volume/percorso (`--volume`/`--path` richiedono l'argomento `profile`; non cancella mai chiavi non correlate in `cache.app`). |
| `file-manager:thumbs:warmup [profile]` | Alias: `thumbs:generate`, `thumbs:rebuild`<br>`--volume`, `--path`, `-f`/`--force`, `--limit`, `--dry-run` | Pre-genera in anticipo le miniature delle immagini così gli editor non devono attendere al primo caricamento. |
| `file-manager:thumbs:clear [profile]` | `--volume`, `--path`, `--orphans`, `--dry-run` | Cancella le miniature in cache. `--orphans` rimuove solo le miniature il cui file sorgente non esiste più (non combinabile con `--volume`/`--path` e protegge automaticamente le directory condivise `{user_id}` / `{Y}/{m}/{d}`). |
| `file-manager:images:optimize [profile]` | `--volume`, `--path`, `-f`/`--force`, `--allow-webp-rename`, `--limit`, `--dry-run` | Ottimizza retroattivamente le immagini esistenti (limite di sicurezza/memoria: max `25 MB` per immagine). |
| `file-manager:duplicates [profile]` | `--volume`, `--min-size`, `--json` | Rileva i file duplicati tramite hash SHA-256 del contenuto e riporta lo spazio su disco recuperabile. |
| `file-manager:stats [profile]` | `--json` | Mostra utilizzo dello spazio, numero di file/cartelle, utilizzo della quota, miniature e statistiche del cestino. |
| `file-manager:ai:enrich [profile]` | `--volume`, `--path`, `--locale`, `-f`/`--force`, `--limit` *(predefinito `50`)*, `--dry-run` | Genera in batch testi `alt`, titoli, didascalie e tag mancanti usando l'AI. |
| `file-manager:trash:purge [profile]` | `--volume`, `--days` *(`0` = svuota tutto)*, `--dry-run` | Elimina definitivamente gli elementi scaduti dal cestino. Quando più profili condividono una directory fisica, rispetta automaticamente il valore `retention_days` più lungo. |
| `file-manager:skills:install` | `-t`/`--target`, `--dir`, `--only`, `--symlink`, `-f`/`--force`, `--dry-run` | Installa le 6 AI Agent Skills del bundle in `.agents/skills`, `.claude/skills`, `.cursor/skills` o `.github/skills`. |

> [!NOTE]
> **Protezione di `{user_id}` e `{Y}/{m}/{d}` Dinamici nei Comandi CLI**
> Poiché in modalità CLI non esiste alcuna sessione utente autenticata, tutti i comandi di scansione e pulizia (`doctor`, `duplicates`, `ai:enrich`, `images:optimize`, `stats`, `thumbs:clear`, `thumbs:warmup`, `trash:purge`) **saltano automaticamente** i volumi contenenti `{user_id}` (evitando la creazione accidentale di una cartella `guest/` vuota). Per i volumi con segnaposto data `{Y}/{m}/{d}`, viene scansionato solo il periodo corrente e viene mostrato un avviso informativo.

### Esempio Cron per la Pulizia Automatica del Cestino

```cron
# Elimina definitivamente gli elementi scaduti dal cestino ogni notte alle 03:00
0 3 * * * php /var/www/project/bin/console file-manager:trash:purge --no-interaction
```

---

## 3. Prestazioni, Architettura Cache e Symfony Profiler

- **Cache TTL a 3 Livelli e Aggiornamento Manuale (`↻`):** il TTL dell'elenco directory viene risolto in questo ordine: **`volumes.<name>.cache_ttl` → `profiles.<name>.cache.ttl` → `defaults.cache.ttl`** (`0` disabilita la cache per quel volume o profilo). Facendo clic su **Aggiorna (`↻`)** nella barra degli strumenti o premendo `R` viene inviato `?refresh=1` *(bypassare la cache richiede il permesso `CLEAR_CACHE`)*.
- **Pool Cache Dedicato e Fingerprinting dei Profili:** indirizza `defaults.cache.pool` verso un pool Redis/APCu dedicato (se `<pool>.taggable` esiste, viene usato automaticamente). Senza cache taggabile, `DirectoryCacheManager` usa chiavi contatore di generazione a 30 giorni e non chiama mai `$cachePool->clear()` sulla cache principale dell'applicazione. Quando due profili condividono la stessa cartella fisica con regole diverse (`ignore_dot_files`, `exclude_patterns`, `trash.directory`, `locale`, `max_scan_files`), le loro cache restano rigorosamente isolate tramite `buildProfilePolicyFingerprint()`.
- **Sincronizzazione Chiave Canonica:** se due profili puntano alla stessa directory fisica, caricare o modificare un file in uno invalida automaticamente anche la cache dell'altro profilo.
- **`remember_last_dir` Auto-Riparante:** se l'ultima directory visitata dall'utente è stata eliminata esternamente (via FTP o console S3), il file manager risale in modo pulito alla cartella genitore esistente più vicina o alla radice invece di andare in errore.
- **HTTP 304 e Caching delle Miniature Consapevole del Contesto (`ThumbnailController`):**
  - **Contesto Privato / Autenticato** (volume `private: true`, volume dinamico `{user_id}`, profilo protetto da Voter, header `Authorization` o cookie di sessione presente): invia `Cache-Control: private, no-cache, must-revalidate` + `Vary: Cookie, Authorization` per evitare fughe su CDN condivise.
  - **Contesto Pubblico:**
    - Con parametro cache-buster `?v=<version>` → `Cache-Control: public, max-age=2592000, s-maxage=2592000, immutable` (30 giorni).
    - Senza `?v=` → `Cache-Control: public, max-age=3600, must-revalidate` (1 ora + `ETag` / `304 Not Modified`).
    - Quando `cache.enabled: false` sul profilo → `Cache-Control: public, no-cache, must-revalidate`.
  - Ogni risposta delle miniature imposta inoltre `Content-Security-Policy: default-src 'none'; sandbox`, `X-Content-Type-Options: nosniff` e `Referrer-Policy: no-referrer`.
- **Symfony Web Debug Toolbar:** nell'ambiente `dev`, il pannello **FileManager** del profiler mostra il profilo attivo, il volume, il driver miniature e le statistiche di hit/miss della cache.

---

## 4. Localizzazione Globale (`493` Chiavi), BCP-47 / POSIX / RTL e Lingue Personalizzate

LiveUX FileManagerBundle include di serie **12 lingue UI integrate** — **Inglese (`en` — fallback garantito), Turco (`tr`), Francese (`fr`), Tedesco (`de`), Spagnolo (`es`), Arabo (`ar`), Italiano (`it`), Portoghese (`pt`), Olandese (`nl`), Polacco (`pl`), Giapponese (`ja`) e Cinese Semplificato (`zh_CN`)** — con piena parità su tutte le **493 chiavi di traduzione**.

- **Supporto BCP-47 e POSIX `.UTF-8`:** `MessageTranslator` risolve automaticamente stringhe di locale regionali e POSIX come `tr_TR.UTF-8`, `de_DE@euro` e `zh-Hans-CN` (`zh_Hans_CN` → `zh_Hans` → `zh_CN` → `zh` → `en`). `toBcp47()` genera codici BCP-47 validi per gli attributi HTML `lang`.
- **Rilevamento Automatico Right-to-Left (RTL):** **15 lingue e scritture RTL** — tra cui Arabo (`ar`), Persiano (`fa`), Ebraico (`he`), Urdu (`ur`), Pashto (`ps`) e Curdo Centrale (`ckb`) (`Arab`, `Hebr`, `Syrc`, `Thaa`, ecc.) — vengono rilevate automaticamente, passando il layout del componente a `dir="rtl"`.
- **Allowlist dei Cataloghi a Tempo di Compilazione:** `FileManagerBundle` accetta solo i locale che corrispondono a cataloghi di traduzione reali, prevenendo il rigonfiamento di cache/OPcache (DoS) causato da parametri locale falsificati.
- **Aggiungere una Nuova Lingua (es. Svedese `sv`) o Sovrascritture Parziali:**
  Crea semplicemente `translations/LiveUXFileManagerBundle.sv.yaml` nel tuo progetto (oppure sovrascrivi singole chiavi in una lingua esistente come `LiveUXFileManagerBundle.it.yaml`). Qualsiasi chiave omessa esegue automaticamente il fallback all'inglese (`en`) — gli utenti non vedranno mai chiavi grezze come `ui.upload`. Copia `translations/LiveUXFileManagerBundle.en.yaml` dal bundle come modello completo di partenza.

---

## 5. Domande Frequenti e Risoluzione dei Problemi (FAQ)

### 1. `window.LiveUXFileManager is not initialized / undefined`
- Verifica che `assets/app.js` includa `import '@liveux/file-manager-bundle/js/file_manager_api.js';`.
- Controlla che `defaults.auto_inject_dialog_host: true` sia attivo (se lo hai disabilitato, includi manualmente `DialogHost.html.twig` in `base.html.twig`).
- Esegui `php bin/console cache:clear` ed effettua un aggiornamento forzato del browser (`Ctrl+Shift+R`).

### 2. Ogni richiesta POST restituisce `403 Invalid or missing CSRF token`
- Assicurati che `symfony/security-csrf` sia installato e che siano attivi sia `framework.csrf_protection: true` sia `framework.session.enabled: true`.
- Se invii direttamente richieste `fetch()` personalizzate, includi l'header `X-LiveUX-FM-CSRF`.

### 3. Ottengo `403 Access denied`
- Controlla il blocco `permissions` del tuo profilo in `liveux_file_manager.yaml`. Ricorda: anche impostare un permesso a `true` richiede un utente autenticato, e `permissions.view` è indispensabile per tutte le altre azioni.
- Se hai implementato un Symfony Voter personalizzato, controlla `access_decision_manager.strategy` (es. `unanimous` vs `affirmative`) in `config/packages/security.yaml`.

### 4. Il caricamento di file di grandi dimensioni fallisce (`upload_max_filesize`)
- Con `chunk_upload.enabled: true`, la dimensione dei chunk viene automaticamente limitata all'`80%` dei limiti PHP `upload_max_filesize`/`post_max_size` (es. chunk da `1.60 MB` con limite PHP `2M`, fino a 2.000 chunk).
- La dimensione massima complessiva del file è controllata da `upload_max_size` in `liveux_file_manager.yaml` (es. imposta `upload_max_size: '100M'` per video da 100 MB).

### 5. Scaricare un file interrompe la pagina (Symfony UX Turbo Drive)
- Tutti i link di download integrati includono già `data-turbo="false" download`. Se sovrascrivi i template Twig, assicurati che i tuoi tag `<a>` di download personalizzati includano `data-turbo="false"`.

### 6. Le miniature non vengono generate; vengono servite solo le immagini originali
- Esegui `php bin/console file-manager:doctor` e verifica che la riga `Thumbnail Driver` mostri `gd` o `imagick` come attivo.
- Controlla che `thumbnail.cache_dir` (`public/media-cache`) sia scrivibile dall'utente del web server.

### 7. `cURL error 60: SSL certificate problem` su Windows durante la connessione a S3 / Supabase
- Scarica un bundle `cacert.pem` aggiornato e configura `curl.cainfo` e `openssl.cafile` in `php.ini`, oppure specifica `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'` sul volume S3.

### 8. Le modifiche al frontend non risultano visibili nel browser
- **Sviluppo:** esegui `php bin/console cache:clear` e fai un refresh forzato (`Ctrl+Shift+R`).
- **Produzione (AssetMapper):** esegui `php bin/console asset-map:compile`.

---

[← Precedente: 07 — Cloud Storage e Configurazione Dinamica](07-cloud-storage-and-dynamic-config.md) | [Indice Principale](README.md) | [Successivo: 09 — Integrazione Symfony AI →](09-symfony-ai-integration.md)
