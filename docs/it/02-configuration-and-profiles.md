# 02 — Configurazione e profili (Riferimento completo dei parametri e ricette)

[← Precedente: 01 — Requisiti e installazione](01-requirements-and-installation.md) | [Indice della documentazione](README.md) | [Avanti: 03 — Modalità d'uso e API JS →](03-usage-modes-and-js-api.md)

Questa guida descrive **ogni opzione** di `config/packages/liveux_file_manager.yaml`, il suo valore predefinito, quando personalizzarla e **5 ricette pronte per la produzione** da copiare direttamente nel tuo progetto.

---

## Modello mentale in 30 secondi: `defaults`, `volumes` e `profiles`

Pensa al file di configurazione come a **3 livelli ben separati**:

1. **`defaults` (Impostazioni globali predefinite):** Base comune ereditata da tutti i profili (tema, locale, tema colore, cache, driver metadati, audit log, impostazioni AI predefinite). Salvo override su uno specifico profilo, questi valori si applicano ovunque.
2. **`volumes` (Dischi / Bucket di archiviazione):** Definisce **dove risiedono fisicamente i file** (directory locale, AWS S3, Supabase Storage, Cloudflare R2, Backblaze B2, MinIO, Google Cloud Storage o un servizio Flysystem personalizzato).
3. **`profiles` (Profili d'uso):** Definisce **regole, layout UI e permessi** per una specifica schermata o flusso di lavoro. Ad esempio:
   - `product_catalog` accetta solo foto prodotto quadrate `1:1` e le converte automaticamente in WebP;
   - `customer_docs` si collega a un bucket Supabase S3 privato, accetta solo PDF e isola ogni utente nella propria cartella;
   - `editor_picker` apre un selettore modale essenziale per TinyMCE con selezione tramite doppio clic.

---

## 1. `defaults` — Impostazioni globali predefinite

```yaml
liveux_file_manager:
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'
        theme: 'bootstrap5'
        locale: null         # null = eredita il locale attivo di Symfony (fallback su 'en')
        color_scheme: 'auto'
        z_index: 1050
        auto_inject_dialog_host: true
        dialog_host_profile: 'default'
        cache:
            enabled: true
            pool: 'cache.app'
            ttl: 3600
        metadata:
            enabled: true
            driver: 'json_file'
            file: '%kernel.project_dir%/var/file-manager/metadata.json'
            connection: null
            entity_manager: 'default'
            table: 'liveux_fm_metadata'
            auto_create_table: true
        audit_log:
            enabled: false
            level: 'info'
        ai:
            default_model: 'gpt-4o-mini'
            platform_service: null
```

| Parametro | Predefinito | Cosa fa | Quando modificarlo |
| :--- | :--- | :--- | :--- |
| `base_path` | `%kernel.project_dir%/public/uploads` | Directory radice predefinita per profili abbreviati che definiscono `path` direttamente senza `volumes`. | Quando archivi i file locali in un'altra cartella, ad esempio `public/media`. |
| `theme` | `'bootstrap5'` | Tema UI predefinito per tutti i profili (`bootstrap5`, `tailwind`, `shadcn`, `flowbite`, `daisyui`, `tabler`, `preline`, `material`, `unstyled` o il nome di un tema personalizzato). | Per allinearlo al design system della tua app (es. `'shadcn'` o `'tailwind'` in progetti Tailwind). |
| `locale` | `null` | Se `null`, eredita automaticamente il locale attivo della richiesta/Translator di Symfony tra tutte le **12 lingue UI integrate** (`en`, `tr`, `fr`, `de`, `es`, `ar`, `it`, `pt`, `nl`, `pl`, `ja`, `zh_CN`), normalizza i formati BCP-47 e POSIX (`de_DE.UTF-8`, `zh-Hans-CN`), abilita automaticamente RTL (`dir="rtl"`) per arabo e altre lingue RTL ed esegue fallback su `'en'` per locale sconosciuti. | Impostalo esplicitamente (es. `'it'`, `'en'`, `'de'`, `'fr'`) per forzare una sola lingua UI per tutti gli utenti. |
| `color_scheme` | `'auto'` | Tema colore: `'auto'` (rileva `.dark`, `[data-bs-theme="dark"]`, `[data-theme="dark"]`), `'light'` (sempre chiaro) o `'dark'` (sempre scuro). | Per imporre un pannello admin sempre chiaro o sempre scuro. |
| `z_index` | `1050` | Contesto di impilamento base (`--liveux-fm-z-base`) per modali, pannelli OffCanvas, Lightbox e menu contestuali (`>= 1`). | Alzalo (es. a `10050`) se la navbar del tuo pannello admin usa `z-index: 9999`. |
| `auto_inject_dialog_host` | `true` | Inserisce automaticamente il contenitore `<dialog>` richiesto da `window.LiveUXFileManager.open()` prima di `</body>` nelle pagine HTML complete (senza modificare `base.html.twig`). | Impostalo su `false` se preferisci includere manualmente `@LiveUXFileManager/components/DialogHost.html.twig` solo in layout specifici. |
| `dialog_host_profile` | `'default'` | Profilo predefinito usato dal `DialogHost` iniettato automaticamente quando non viene passato alcun profilo. | Quando il tuo profilo principale non si chiama `default` (es. `admin_media`). |
| `cache.enabled` | `true` | Cache PSR-6 lato server per elenchi di cartelle e alberi delle cartelle. | Impostalo su `false` durante il debug locale (oppure disabilitalo per singolo profilo/volume). |
| `cache.pool` | `'cache.app'` | Pool di cache PSR-6 di Symfony (Redis, Filesystem, APCu, ecc.). Usa automaticamente `<pool>.taggable` quando disponibile per invalidazioni mirate per tag. | Per isolare le voci di cache del gestore file in un pool Redis dedicato. |
| `cache.ttl` | `3600` | Durata della cache in secondi (predefinito: 1 ora). Le modifiche (caricamento, eliminazione, spostamento, rinomina) invalidano automaticamente le cartelle interessate. | Riducilo a `60`–`300` se i file vengono spesso modificati dall'esterno tramite FTP o console cloud. |
| `metadata.enabled` | `true` | Archivia colori/icone/Elementi aggiunti ad Accesso rapido per le cartelle e metadati SEO per i file (`alt`, `title`, `caption`, `tags`, `focalPoint`). | Impostalo su `false` per selettori minimalisti che non usano metadati editoriali. |
| `metadata.driver` | `'json_file'` | Backend metadati: `'json_file'` (file locale per app single-server), `'doctrine'` (storage DBAL integrato per app multi-server / con load balancer su MySQL, MariaDB, PostgreSQL o SQLite) oppure `'none'`. | Passa a `'doctrine'` ogni volta che esegui più repliche dell'app (Kubernetes, load balancer, Docker Swarm)—vedi [Capitolo 13](13-doctrine-integration.md). |
| `metadata.file` | `%kernel.project_dir%/var/file-manager/metadata.json` | Percorso di archiviazione per il driver `json_file`. Nella stessa directory viene salvato un file sidecar per il Cestino (`trash-meta.json` o `<name>.trash-meta.json`) per i bucket cloud con restrizioni MIME. | Spostalo su un percorso NFS/volume condiviso se necessario. |
| `metadata.connection` / `entity_manager` / `table` / `auto_create_table` | `null` / `'default'` / `'liveux_fm_metadata'` / `true` | Nome connessione DBAL, EntityManager, nome tabella (`/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`) e flag di creazione automatica della tabella quando `driver: 'doctrine'`. | Imposta `auto_create_table: false` in produzione quando gestisci lo schema con Doctrine Migrations (vedi [Capitolo 13](13-doctrine-integration.md)). |
| `audit_log.enabled` | `false` | Scrive record di audit strutturati PSR-3 (`user`, `ip`, `path`, `volume`) per tutte le 9 modifiche a file/cartelle (caricamento, eliminazione, spostamento, copia, rinomina, duplicazione, creazione cartella, ripristino Cestino, eliminazione definitiva Cestino). | Abilitalo (`true`) in contesti soggetti a compliance dove serve sapere *"chi ha eliminato cosa, e quando"*. |
| `audit_log.level` | `'info'` | Livello di log PSR-3 per le voci di audit (`debug`, `info`, `notice`, `warning`). | Adattalo alle regole di routing/filtraggio di Monolog. |
| `ai.default_model` | `'gpt-4o-mini'` | Modello vision/testo predefinito usato quando è installato il pacchetto opzionale `symfony/ai-bundle` (vedi [Capitolo 09](09-symfony-ai-integration.md)). | Passa a Gemini, Claude o a un modello locale Ollama. |
| `ai.platform_service` | `null` | ID servizio `PlatformInterface` personalizzato di Symfony AI (`null` usa la piattaforma registrata di default). | Quando la tua applicazione definisce più piattaforme Symfony AI. |

---

## 2. `volumes` — Volumi di archiviazione (Locale e Cloud)

Un singolo profilo può montare più volumi contemporaneamente (ad esempio sia Disco locale sia Supabase/R2/S3). Gli utenti possono trascinare i file direttamente da una cartella locale a un bucket cloud.

```yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Disco locale'
            icon: 'disk'              # Opzionale (rilevata automaticamente da driver/endpoint se omessa)
            group: 'local'            # Opzionale (null -> 'local' per LocalFileSystem, 'network' per S3/Flysystem)
            driver: 'LocalFileSystem' # 'LocalFileSystem' | 'AwsS3' | 'FlysystemService'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'
            private: false
            cache_ttl: 3600           # TTL opzionale per singolo volume (0 = disabilitato)

        supabase_s3:
            label: 'Supabase Storage'
            icon: 'supabase'          # Rilevata automaticamente anche dall'endpoint
            group: 'network'
            driver: 'AwsS3'
            private: false
            cache_ttl: 60             # Le modifiche esterne dalla dashboard cloud appaiono entro 60 secondi
            url_prefix: 'https://ref.supabase.co/storage/v1/object/public/my-bucket'
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'
```

| Parametro | Predefinito | Cosa fa | Quando modificarlo |
| :--- | :--- | :--- | :--- |
| `label` | Chiave del volume | Nome visualizzato nella barra laterale, nel percorso di navigazione e nel selettore volumi. | Imposta un titolo chiaro per gli utenti (es. `'CDN Media Prodotti'`). |
| `icon` | `null` *(auto)* | Icona SVG nella barra laterale. Se `null`, viene rilevata automaticamente da `driver` ed `endpoint` (`disk`, `aws_s3`, `cloudflare_r2`, `supabase`, `backblaze_b2`, `minio`, `gcs`, `sftp`, `cloud`). | Fai riferimento a un file `<dir>/ui/my_icon.svg` personalizzato nel tuo set di icone. |
| `group` | `null` *(auto)* | Gruppo di sezione nella barra laterale. Se `null`, `LocalFileSystem` usa `'local'` mentre `AwsS3` / `FlysystemService` usano `'network'`. | Sovrascrivilo se vuoi raggruppare un mount NFS sotto `'network'` o un disco cloud sotto `'local'`. |
| `driver` | `'LocalFileSystem'` | Backend di archiviazione: filesystem locale (`LocalFileSystem`), object storage compatibile S3 (`AwsS3`) o servizio Flysystem esistente (`FlysystemService`). | Usa `'AwsS3'` per S3/R2/Supabase/B2/MinIO/GCS, oppure `'FlysystemService'` per SFTP/Azure/Dropbox. |
| `path` | `null` | Percorso fisico della directory per `LocalFileSystem` (supporta `{user_id}`, `{Y}`, `{m}`, `{d}`). | Imposta una radice personalizzata o un percorso isolato per utente (`.../users/{user_id}`). |
| `url_prefix` | `null` | Prefisso URL pubblico usato dal browser per accedere ai file pubblici (`/uploads` o `https://cdn.example.com`). Ignorato quando `private: true`. | Configuralo quando servi gli asset tramite CDN o bucket pubblico S3/R2/Supabase. |
| `private` | `false` | Quando è `true`, i file non sono mai esposti tramite URL pubblici diretti: tutti i download, le anteprime e le miniature passano dai controlli Voter di Symfony (`DownloadController`). | **Imposta sempre `true`** per fatture, documenti d'identità, contratti o file privati dei clienti. |
| `cache_ttl` | `null` *(ereditato)* | TTL della cache (in secondi) limitato a questo volume. `0` disabilita la cache sul volume; `null` eredita dal profilo o dai `defaults`. | Mantieni il disco locale in cache per `3600`s limitando un bucket cloud a `60`s o `0`. |
| `driver_options` | `[]` | **AwsS3:** `bucket`, `region`, `endpoint`, `key`, `secret`, `token` *(token di sessione STS temporaneo)*, `version` *(predefinito `'latest'`)*, `prefix`, `use_path_style_endpoint`, `acl`, `disable_acl` *(automaticamente `true` per endpoint personalizzati; predefinito `false` su AWS S3 standard—imposta `true` esplicitamente per Bucket owner enforced)*, `retain_visibility`, `request_checksum_calculation`, `response_checksum_validation`, `ca_bundle`. **FlysystemService:** `service`. | Vedi [Capitolo 07](07-cloud-storage-and-dynamic-config.md) per le guide specifiche per provider. |

---

## 3. `profiles` — Dizionario dei parametri di profilo

### 3.1. Archiviazione di base, filtri e quota
| Parametro | Predefinito | Cosa fa | Quando modificarlo |
| :--- | :--- | :--- | :--- |
| `locale` | `null` *(defaults)* | Sovrascrive il locale dell'interfaccia e dei messaggi di errore per questo profilo (`'it'`, `'en'`, `'tr'`, `'fr'`, `'de'`, `'ar'`, ecc.; `null` eredita `defaults.locale` → locale richiesta Symfony → `'en'`). | Quando pannelli diversi nella stessa app usano lingue differenti. |
| `volumes` | `[]` | Elenco ordinato delle chiavi dei volumi accessibili in questo profilo (es. `['local_uploads', 'supabase_s3']`). Il primo volume viene aperto di default. | Per collegare uno o più volumi locali/cloud. |
| `ignore_dot_files` | `true` | Nasconde i file con punto iniziale (`.env`, `.htaccess`, `.DS_Store`, `.trash`) dagli elenchi e ne blocca l'accesso diretto. | Mantienilo `true` per sicurezza. |
| `exclude_patterns` | `['/^_thumbs$/i', '/^\.DS_Store$/i']` | Nasconde e blocca l'accesso diretto a file o cartelle che corrispondono a regex o wildcard. | Nascondi cartelle interne come `/^cache$/i` o `/^backups$/i`. |
| `sort_by` | `'name'` | Colonna di ordinamento predefinita: `'name'`, `'date'`, `'size'` o `'type'`. | Usa `'date'` + `sort_direction: 'desc'` per mostrare prima i caricamenti più recenti. |
| `sort_direction` | `'asc'` | Direzione ordinamento: `'asc'` (A→Z / più piccolo prima) o `'desc'` (Z→A / più recente prima). | `'desc'` è ideale per gallerie media e librerie editoriali. |
| `folders_first` | `true` | Elenca sempre le cartelle prima dei file. | Imposta `false` per un ordinamento alfabetico o cronologico piatto. |
| `storage_quota` | `null` | Spazio su disco massimo consentito per il profilo (es. `'500M'`, `'2G'`). Una volta raggiunto, i caricamenti vengono rifiutati lato server e nella barra laterale appare una barra di utilizzo. | Indispensabile per SaaS multi-tenant o quote per utente (`{user_id}`). |
| `upload_subfolder` | `null` | Modello di sottocartella dinamica in cui i file caricati vengono salvati automaticamente sotto la directory corrente (es. `'{Y}/{m}'`). | Organizza automaticamente i caricamenti in cartelle per data come `2026/11`. |

### 3.2. Caricamenti, caricamento a blocchi (`chunk_upload`) e rilevamento duplicati (`duplicate_detection`)
| Parametro | Predefinito | Cosa fa | Quando modificarlo |
| :--- | :--- | :--- | :--- |
| `upload_allow` | `[]` *(tutti i tipi sicuri)* | Allowlist dei tipi MIME accettati (es. `['image/jpeg', 'image/png', 'image/webp']`). Se vuota, sono consentiti tutti i tipi sicuri non presenti in `upload_deny`. | Limita sempre a tipi MIME specifici per form di sole immagini o soli PDF. |
| `upload_deny` | **47 tipi MIME pericolosi** | Tipi MIME rigidamente bloccati (PHP, HTML, XML non-SVG, JS, Shell, EXE, Java, CGI, ecc.—validati lato server tramite magic bytes). | Aggiungi ulteriori tipi MIME bloccati se richiesto dalle tue policy. |
| `upload_max_size` | `'10M'` | Dimensione massima **ricomposta** di un singolo file caricato (es. `'200M'`, `'1G'`). Con il caricamento a blocchi attivo, file molto più grandi del limite PHP di `2M` vengono caricati senza problemi fino a questa soglia. | Alzalo a `'200M'`–`'500M'` per profili video/cataloghi PDF, oppure riducilo a `'2M'` per gli avatar. |
| `overwrite_policy` | `'rename'` | Comportamento in caso di collisione di nomi: `'rename'` (`photo-1.jpg`), `'overwrite'` o `'reject'`. | Mantieni `'rename'` per evitare perdite accidentali di dati. |
| `chunk_upload.enabled` | `true` | Suddivide i file di grandi dimensioni in piccoli blocchi con Metti in pausa / Riprendi / Annulla con conferma e tentativi automatici in caso di errori di rete transitori. | Disabilitalo solo se carichi esclusivamente file minuscoli (< 1 MB). |
| `chunk_upload.chunk_size` | `'2M'` | Dimensione target del blocco. Viene automaticamente limitata all'**80%** di `upload_max_filesize` e `post_max_size` di PHP (es. 1,60 MB con un limite PHP di 2M). | Aumentalo a `'5M'` su server con limiti PHP elevati (es. 32M+) per velocizzare i caricamenti grandi. |
| `chunk_upload.parallel_uploads` | `3` | Numero massimo di caricamenti simultanei (`1`–`10`). | Usa `2` su server con risorse limitate, oppure `4`–`5` su connessioni cloud/CDN veloci. |
| `chunk_upload.max_retries` | `3` | Tentativi con backoff esponenziale per singolo blocco in caso di caduta di rete (`0`–`10`). | `3`–`5` funziona bene per reti mobili o instabili. |
| `duplicate_detection.enabled` | `false` | Rileva caricamenti di file duplicati tramite hash SHA-256 del contenuto. | Abilitalo (`true`) per evitare che i redattori carichino ripetutamente la stessa foto prodotto. |
| `duplicate_detection.policy` | `'warn'` | Azione al rilevamento di un duplicato: `'warn'` (carica e avvisa), `'reject'` (blocca il caricamento) o `'reuse'` (restituisce il file esistente senza duplicare spazio). | `'reuse'` garantisce un notevole risparmio di spazio su e-commerce e CMS. |
| `duplicate_detection.max_scan_files` | `5000` | Limite massimo di file indicizzati per il rilevamento duplicati (`100`–`50000`) per evitare picchi di CPU/I/O su archivi enormi. | Alzalo a `10000`–`25000` per grandi librerie media. |

### 3.3. Normalizzazione automatica dei nomi file (`naming`)
| Parametro | Predefinito | Cosa fa | Esempio (`"Foto Prodotto 2026.JPG"`) |
| :--- | :--- | :--- | :--- |
| `naming.slugger` | `true` | Traslittera caratteri accentati/Unicode e spazi in slug ASCII puliti compatibili con gli URL. | `foto-prodotto-2026.jpg` |
| `naming.slugger_locale` | `null` | Locale di traslitterazione (`null` eredita il locale del profilo; es. `'de'` converte `ä/ö/ü/ß`, `'tr'` converte `İ/ı/ş/ğ/ç/ö/ü`). | Impostalo sulla lingua dei tuoi contenuti (es. `'it'`, `'de'`, `'tr'`, `'fr'`). |
| `naming.transformer` | `'none'` | Trasformazione maiuscole/stile: `'none'`, `'lowercase'`, `'uppercase'`, `'camel'`, `'snake'`, `'cameltitle'`, `'title'`, `'titleall'` oppure `'folder'` (nomina il file come la cartella padre!). | `'snake'` → `foto_prodotto_2026.jpg`, `'folder'` → `borracce-1.jpg` |
| `naming.prepend` / `append` | `''` | Aggiunge testo statico o token dinamici (`{Y}`, `{m}`, `{d}`, `{uniqid}`) all'inizio o alla fine del nome. | `prepend: '{Y}{m}_'` → `202611_prodotto.jpg` |
| `naming.apply_on_rename` | `true` | Applica le regole di `slugger` e `transformer` anche quando un utente rinomina un file dall'interfaccia. | Mantienilo `true` per garantire nomi file puliti anche dopo la rinomina. |
| `naming.apply_affixes_on_rename` | `false` | Evita che i token `prepend`/`append` vengano duplicati a ogni rinomina del file. | Mantienilo `false`. |
| `naming.allow_extension_change` | `false` | Impedisce agli utenti di cambiare estensione (es. `.jpg` → `.php` o `.png`) durante la rinomina. | Mantienilo `false` per sicurezza. |

### 3.4. Ottimizzazione immagini (`image_optimization`) e vincoli (`image_constraints`)
- **`image_optimization`:** Corregge automaticamente l'orientamento, rimuove i metadati EXIF, ridimensiona e comprime le foto grandi sul server durante il caricamento.
- **`image_constraints`:** Rifiuta le immagini non conformi **sia in 0ms nel browser sia sul server** (ad esempio bloccando foto sfocate a bassa risoluzione o foto prodotto non quadrate).

| Parametro | Predefinito | Cosa fa | Quando usarlo |
| :--- | :--- | :--- | :--- |
| `image_optimization.enabled` | `false` | Abilita l'ottimizzazione delle immagini al caricamento (Imagick → GD → no-op silenzioso se nessuno dei due è disponibile). | Abilitalo (`true`) in ogni profilo dove gli utenti caricano foto originali da smartphone. |
| `image_optimization.auto_orient` | `true` | Legge i tag EXIF di orientamento e ruota automaticamente le foto da smartphone storte. | Mantienilo `true` per i caricamenti da dispositivi mobili. |
| `image_optimization.strip_exif` | `true` | Rimuove coordinate GPS e metadati EXIF della fotocamera (privacy GDPR + file più leggeri). | Mantienilo `true` per tutte le immagini pubbliche. |
| `image_optimization.max_width` / `max_height` | `2400` / `2400` | Riduce proporzionalmente le immagini oltre questi limiti preservando le proporzioni (`>= 100`). | `1920`×`1920` è un ottimo compromesso per le immagini web. |
| `image_optimization.convert_to_webp` | `false` | Converte automaticamente i file JPEG/PNG caricati in WebP (file più leggeri del 30–60%). | Abilitalo (`true`) nei profili orientati alle prestazioni web moderne. |
| `image_optimization.quality` | `85` | Qualità di compressione in uscita (`10`–`100`). | `80`–`85` offre il miglior equilibrio tra peso e qualità. |
| `image_constraints.min_width` / `min_height` | `null` | Dimensioni minime richieste in pixel. | Impedisce caricamenti sfocati (es. copertine blog almeno `1200`×`630`). |
| `image_constraints.max_width` / `max_height` | `null` | Dimensioni massime consentite in pixel (verificate *dopo* il ridimensionamento di `image_optimization` quando attivo). | Impone dimensioni rigorose per banner o template di stampa. |
| `image_constraints.aspect_ratio` | `null` | Proporzioni richieste: `'1:1'` (quadrato), `'16:9'` (banner panoramico), `'4:3'`, `'3:4'`, `'1.91:1'`, ecc. | Schede prodotto e-commerce quadrate (`'1:1'`) o testate video/articoli (`'16:9'`). |
| `image_constraints.aspect_ratio_tolerance` | `0.05` | Scostamento tollerato dal rapporto richiesto (`0.0`–`0.5`; `0.05` = tolleranza del 5%, così passano sia `1920×1080` sia `1280×722`). | `0.05` è ideale in quasi tutti gli scenari. |

### 3.5. Miniature, Cestino (`trash`), ZIP (`zip`) e link di condivisione firmati (`share`)
| Parametro | Predefinito | Cosa fa |
| :--- | :--- | :--- |
| `thumbnail.enabled` / `driver` | `true` / `'auto'` | Genera miniature in cache per le immagini: `'auto'` (Imagick → GD), `'imagick'`, `'gd'`, `'liip_imagine'` o `'none'`. Le immagini cloud vengono scaricate una sola volta e salvate in cache locale. |
| `thumbnail.on_missing_driver` | `'fallback_original'` | Comportamento quando mancano sia GD sia Imagick: `'fallback_original'` (mostra l'immagine originale), `'icon'` (mostra l'icona del file) o `'exception'`. |
| `thumbnail.width` / `height` / `format` / `quality` / `fit` | `320` / `240` / `'webp'` / `80` / `'contain'` | Dimensioni della miniatura (`>= 32`), formato di output (`webp`, `jpeg`, `png`) e modalità di adattamento (`contain` = immagine interamente visibile, `cover` = riempimento con ritaglio centrato). Valuta `480`×`360` se usi `ui.item_size: 'xl'`. |
| `thumbnail.lazy_load` | `true` | Aggiunge `loading="lazy"` nativo alle immagini delle schede in Griglia per un rendering iniziale più rapido nelle cartelle grandi. |
| `thumbnail.cache_dir` | `%kernel.project_dir%/public/media-cache` | Directory locale in cui vengono salvate le miniature generate. |
| `trash.enabled` / `directory` / `retention_days` / `max_size` | `true` / `'.trash'` / `30` / `'500M'` | Sposta i file eliminati nel Cestino invece di distruggerli subito. Usa automaticamente un file sidecar locale `trash-meta.json` sui bucket cloud con restrizioni MIME. |
| `zip.enabled` / `max_files` / `max_total_size` | `true` / `100` / `'250M'` | Abilita il download `.zip` multi-file/cartella con un clic (richiede PHP `ext-zip`). |
| `zip.allow_extract` / `max_extract_files` / `max_extract_size` / `max_compression_ratio` | `false` / `200` / `'100M'` / `100` | Estrazione archivi protetta a 7 livelli contro Zip-Slip e Zip-Bomb. Resta sempre bloccata finché `allow_extract: false`; abilitandola, `permissions.extract_zip` diventa automaticamente `true` (puoi restringerlo a `'ROLE_ADMIN'` o `false`). |
| `share.enabled` / `default_ttl` / `max_ttl` / `allow_inline_preview` | `true` / `86400` / `2592000` / `true` | Genera link di condivisione temporanei con firma HMAC tramite `UriSigner` di Symfony. Impostando `enabled: false`, tutti i link emessi in precedenza vengono invalidati all'istante. |

### 3.6. Interfaccia utente (`ui`) — Layout, dimensioni e flag della barra degli strumenti
| Parametro | Predefinito | Cosa fa | Quando modificarlo |
| :--- | :--- | :--- | :--- |
| `ui.theme` | `null` *(defaults)* | Tema specifico del profilo (es. `'bootstrap5'` in admin, `'shadcn'` nel portale clienti). | Quando sezioni diverse dell'app adottano temi grafici differenti. |
| `ui.color_scheme` | `null` *(defaults)* | Tema colore specifico del profilo (`auto`, `light`, `dark`). | Forza `'dark'` in un'area di lavoro esclusivamente scura. |
| `ui.icon_set` | `'classic'` | Stile icone file/cartelle: `classic`, `windows11`, `macos`, `linux`, `minimal`, `vscode` o un percorso a una directory SVG personalizzata (`'%kernel.project_dir%/assets/fm-icons'`). | Scegli `'windows11'` o `'macos'` per un look desktop, oppure punta a icone SVG del tuo brand. |
| `ui.z_index` | `null` *(defaults)* | Override dello `z-index` degli overlay per questo profilo. | Se incorporato in una pagina con editor o pannelli ad alto `z-index`. |
| `ui.mobile_dialog_mode` | `'auto'` | Presentazione delle finestre su schermi piccoli (`<= 767.98px`): `'auto'` (modali e offcanvas laterali a schermo intero, offcanvas top/bottom come sheet nativi arrotondati), `'fullscreen'` (tutti a schermo intero) o `'sheet'` (tutti come bottom sheet nativo). | Imponi `'sheet'` o `'fullscreen'` su tutti i selettori mobile. |
| `ui.default_view` | `'grid'` | Vista iniziale: `'grid'` (Griglia a schede con miniature) o `'list'` (Elenco tabellare ordinabile). | Usa `'list'` per archivi PDF/documenti e `'grid'` per gallerie visive. |
| `ui.item_size` | `'md'` | Larghezza delle schede in Griglia e scala miniature in Elenco: `'xs'` (`108px`), `'sm'` (`132px`), `'md'` (`158px`), `'lg'` (`204px`), `'xl'` (`260px`) oppure un intero esatto in pixel (`96`–`320`). Attiva automaticamente padding compatto a `<= 132px`. | Usa `'lg'` / `'xl'` per revisioni visive e `'sm'` / `'xs'` per selettori compatti. |
| `ui.card_aspect_ratio` | `'4/3'` | Proporzioni del riquadro di anteprima: `'1/1'` (quadrato), `'4/3'` (standard), `'16/9'` (panoramico/video) o `'3/4'` (locandine/cataloghi verticali). | Adattalo alla forma prevalente dei tuoi asset (`'1/1'` per prodotti, `'16/9'` per video/banner). |
| `ui.folder_style` | `'tile'` | Stile cartelle nella vista Griglia: `'tile'` (Compatto — riquadri orizzontali stile Google Drive sopra i file) o `'card'` (Scheda — schede a dimensione piena stile Windows/Finder coerenti con i file). | Passa a `'card'` per cartelle visivamente più evidenti. |
| `ui.show_size_slider` | `true` | Mostra il cursore live della dimensione elementi (`96px`–`320px`) e il selettore stile cartelle dentro il menu Impostazioni. | Impostalo su `false` per bloccare la densità del layout. |
| `ui.volume_order` | `'local_first'` | Ordine dei volumi nella barra laterale e nel selettore: `'local_first'`, `'network_first'` o `'configured'` (ordine esatto di `volumes: [...]` in YAML senza divisori di gruppo). | Usa `'network_first'` o `'configured'` per flussi cloud-first. |
| `ui.show_folder_tree` | `true` | Mostra o nasconde la barra laterale sinistra con l'albero delle cartelle. | Imposta `false` per selettori semplici a cartella singola (TinyMCE o avatar). |
| `ui.tree_lines` | `true` | Mostra le linee di connessione gerarchica nell'albero delle cartelle. | Imposta `false` per una barra laterale più pulita e minimalista. |
| `ui.show_folder_item_count` | `true` | Mostra il badge con il conteggio elementi (`12`) accanto alle cartelle. Rispetta le autorizzazioni evitando di rivelare sottocartelle riservate. | Mantienilo `true` per una visibilità immediata del contenuto. |
| `ui.folder_count_mode` | `'recursive_files'` | Modalità di conteggio dei badge cartella: `'recursive_files'` (tutti i file incluse le sottocartelle), `'files'` (solo file diretti) o `'items'` (file e sottocartelle diretti). | Scegli `'files'` per contare solo i file diretti. |
| `ui.inspector_mode` | `'on_select'` | Pannello destro Anteprima e dettagli: `'on_select'` (si apre quando selezioni un elemento), `'always'` o `'hidden'`. | Imposta `'hidden'` dentro selettori modali stretti. |
| `ui.folder_open_on` | `'dblclick'` | Gesto di apertura delle cartelle: `'dblclick'` (clic singolo seleziona, doppio clic apre—convenzione desktop) o `'click'` (clic singolo apre subito—convenzione web/mobile). | Usa `'click'` per una navigazione rapida a singolo clic nei pannelli admin. |
| `ui.per_page` / `per_page_options` | `24` / `[24, 48, 96]` | Elementi per pagina predefiniti (`6`–`200`) e scelte disponibili per l'utente. | Alza a `48` e `[24, 48, 96, 150]` per cataloghi molto numerosi. |
| `ui.show_quota` / `show_quota_details` | `true` / `true` | Mostra la barra Spazio di archiviazione nella barra laterale e la finestra cliccabile Utilizzo spazio e dettagli quota. (Il controllo server-side `storage_quota` resta comunque attivo anche se nascosto.) | Imposta `show_quota: false` se non vuoi mostrare indicatori di quota nella UI. |
| `ui.allow_pdf_preview` / `allow_video_preview` | `true` / `true` | Controlla l'anteprima PDF inline nel Lightbox e la riproduzione video HTML5 (applicato sia nella UI sia lato server in `DownloadController`). | Imposta `false` per forzare il download diretto anziché l'anteprima nel browser. |
| `ui.remember_last_dir` | `true` | Salva in `localStorage` l'ultimo **volume + cartella** visitati dall'utente (ripristinandosi automaticamente al livello superiore o alla radice se quella cartella è stata eliminata). | Imposta `false` se ogni sessione deve aprirsi nella directory radice. |
| `ui.confirm_delete` / `confirm_move` | `true` / `true` | Chiede conferma prima di eliminare o spostare elementi tramite drag-and-drop. | Imposta `confirm_move: false` per velocizzare la riorganizzazione dei file. |
| `ui.double_click_to_select` | `true` | In modalità selettore Modale/OffCanvas, il doppio clic su un file lo seleziona subito e chiude la finestra. | Imposta `false` se preferisci che il doppio clic apra l'anteprima invece di selezionare. |
| `ui.allow_context_menu` / `show_context_nav` | `true` / `true` | Abilita i menu contestuali con tasto destro e la barra rapida `[ ← \| → \| ↑ \| ↻ ]` facendo clic con il tasto destro su un'area vuota. | Imposta `false` per schermate utente bloccate. |
| `ui.allow_drag_move` / `allow_dropzone_upload` | `true` / `true` | Abilita lo spostamento tramite trascinamento tra cartelle e il caricamento trascinando file/cartelle dal sistema operativo. | Imposta `false` per librerie di asset in sola lettura. |
| `ui.keyboard_shortcuts` | `true` | Abilita le scorciatoie da tastiera (`Ctrl+A`, `Ctrl+C/X/V`, `F2`, `Canc`, `Spazio`, `/`, `R`, `F`, `?`, ecc.). | Disabilitalo (`false`) se la pagina ospite usa scorciatoie globali in conflitto. |
| Flag `ui.show_*` | *(quasi tutti `true`, `show_perf_badge: false`)* | `show_perf_badge`, `show_nav_buttons`, `show_history_buttons`, `show_up_button`, `show_refresh_button`, `show_breadcrumbs`, `show_search`, `show_type_filter`, `show_view_toggle`, `show_pagination`, `show_per_page_selector`, `show_sort`, `show_fullscreen_button`, `show_settings_button`, `show_footer`, `show_context_nav`. | Attiva o nascondi i singoli controlli della barra degli strumenti per snellire l'interfaccia. *(Nota: le voci "Informazioni" e "GitHub" dentro Impostazioni sono attribuzioni integrate e non sono disabilitabili via YAML.)* |

### 3.7. Cache per profilo (`cache`), sicurezza (`security`), permessi (`permissions`), AI e Usage Guard

#### Cache (`cache`)
| Parametro | Predefinito | Descrizione |
| :--- | :--- | :--- |
| `cache.enabled` | `null` *(defaults)* | Abilita o disabilita la cache per questo profilo. Quando un profilo imposta `cache.enabled: false`, `volume.cache_ttl` non riattiva la cache. |
| `cache.ttl` | `null` *(defaults)* | Ordine di precedenza del TTL: **`volume.cache_ttl` → `profile.cache.ttl` → `defaults.cache.ttl`**. |

#### Sicurezza (`security`)
| Parametro | Predefinito | Descrizione |
| :--- | :--- | :--- |
| `security.voter_enabled` | `true` | Abilita i controlli RBAC tramite `FileManagerVoter` e qualsiasi Voter personalizzato. Mantienilo sempre `true` in produzione. |
| `security.csrf_protection` | `true` | Richiede un header `X-LiveUX-FM-CSRF` valido su ogni richiesta `POST`. |
| `security.sanitize_svg` | `true` | Sanifica i file SVG caricati e modificati tramite una rigida allowlist di 55 tag (`SvgSanitizer`). |
| `security.allow_remote_url_import` | `false` | Abilita la scheda **"Da URL"** nella finestra di caricamento (protetta contro SSRF e DNS rebinding da `RemoteUrlImporter`). |
| `security.audit_log` | `null` *(defaults)* | Sovrascrive il logging di audit PSR-3 per questo profilo (`true` / `false` / `null`). |

#### 17 permessi granulari (`permissions`)
> [!WARNING]
> **Importante: cosa significa `true` sotto `permissions`?**  
> Impostare un permesso su **`true`** **NON** significa *"accesso pubblico / anonimo"*! `FileManagerVoter` concede `true` **solo agli utenti autenticati (`$token->getUser() !== null`)** e nega le richieste anonime.  
> Inoltre, **`permissions.view`** funge da permesso principale: se un utente non dispone di `view`, tutte le altre azioni (`upload`, `delete`, `download`, ecc.) vengono negate automaticamente.

Ogni permesso accetta uno di questi 4 formati:
- `true` → Qualsiasi utente autenticato
- `false` → Nessuno (azione completamente disabilitata)
- `'ROLE_ADMIN'` → Solo utenti con quel ruolo
- `['ROLE_EDITOR', 'ROLE_ADMIN']` → Utenti con almeno uno di quei ruoli

| Chiave permesso | Predefinito | Azioni controllate |
| :--- | :--- | :--- |
| `view` | `true` | Elenco cartelle, albero delle cartelle, anteprime e **prerequisito per tutti gli altri permessi**. |
| `upload` | `true` | Caricamenti standard/a blocchi, salvataggio dall'editor immagini e importazione da URL remoto. |
| `create_folder` | `true` | Creazione di nuove cartelle. |
| `rename` | `true` | Rinomina di file e cartelle. |
| `clone` | `true` | Duplicazione di un file o di una cartella. |
| `move` | `true` | Spostamento elementi tramite drag-and-drop o Taglia/Incolla. |
| `copy` | `true` | Copia elementi tramite Copia/Incolla o finestra Sposta/Copia. |
| `delete` | `true` | Eliminazione file/cartelle (spostamento nel Cestino o eliminazione definitiva). |
| `trash_restore` | `true` | Ripristino di elementi dal Cestino. |
| `trash_purge` | `true` | Eliminazione definitiva di elementi dal Cestino o svuotamento del Cestino. |
| `download` | `true` | Download di singoli file, streaming PDF/video e copia del link del file. |
| `download_zip` | `true` | Download di più elementi selezionati come archivio `.zip`. |
| `extract_zip` | `null` *(auto)* | Forzato a `false` finché `zip.allow_extract: false`; diventa automaticamente `true` quando `zip.allow_extract: true` (puoi restringerlo a `'ROLE_ADMIN'` se preferisci). |
| `share` | `true` | Creazione di link di condivisione temporanei con firma HMAC. |
| `clear_cache` | `true` | Svuotamento cache da Impostazioni e forzatura di `?refresh=1` / `?fallback=1`. |
| `edit_metadata` | `true` | Modifica di `alt`/`title`/`caption`/`tags`/punto focale dei file e colore/icona/Accesso rapido delle cartelle. |
| `ai_enrich` | `true` | Invocazione di **✨ Suggerisci con AI** (richiede `view` + `edit_metadata` + `ai_enrich`). |

#### Arricchimento AI (`ai`) e Asset Usage Guard (`usage_guard`)
| Parametro | Predefinito | Descrizione |
| :--- | :--- | :--- |
| `ai.enabled` / `model` / `locale` | `false` / `null` / `null` | Abilita i suggerimenti metadati AI; `locale` controlla la lingua dei testi generati (es. `'it'`, `'en'`, `'de'`). |
| `ai.max_tags` / `max_image_dimension` | `6` / `1024` | Numero massimo di tag suggeriti (`1`–`20`) e limite di ridimensionamento prima dell'invio all'AI (`256`–`2048` px, per risparmiare token vision). |
| `ai.block_private_volumes` / `auto_enrich_on_upload` | `true` / `false` | Impedisce l'invio a API AI esterne di file su volumi `private: true`; arricchimento automatico opzionale dopo il caricamento (vedi [Capitolo 09](09-symfony-ai-integration.md)). |
| `usage_guard.enabled` / `policy` / `enforce_server_side` / `max_references` | `false` / `'warn'` / `true` / `20` | Avvisa o blocca le operazioni **`delete`, `rename`, `move` e `overwrite`** quando un asset è referenziato nel database (vedi [Capitolo 10](10-asset-usage-guard.md)). |

---

## 4. 5 ricette di profili pronte per la produzione (Copia e incolla)

Ecco le 5 configurazioni reali più richieste, pronte da inserire nel tuo progetto:

### Ricetta 1: Catalogo prodotti e-commerce (Schede quadrate + WebP + Riutilizzo duplicati)
> **Obiettivo:** Accettare solo foto prodotto quadrate `1:1` nitide; ridimensionare e convertire automaticamente in WebP le foto da smartphone; riutilizzare file esistenti in caso di duplicati; bloccare l'eliminazione accidentale di asset associati a prodotti attivi.

```yaml
liveux_file_manager:
    profiles:
        product_catalog:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '15M'
            storage_quota: '5G'
            naming:
                slugger: true
                slugger_locale: 'it'
                transformer: 'lowercase'
            image_optimization:
                enabled: true
                auto_orient: true
                strip_exif: true
                max_width: 1600
                max_height: 1600
                convert_to_webp: true
                quality: 84
            image_constraints:
                min_width: 800
                min_height: 800
                aspect_ratio: '1:1'
                aspect_ratio_tolerance: 0.05
            duplicate_detection:
                enabled: true
                policy: 'reuse'
            usage_guard:
                enabled: true
                policy: 'block'
                enforce_server_side: true
            ui:
                theme: 'shadcn'
                item_size: 'lg'          # Schede prodotto grandi da 204px
                card_aspect_ratio: '1/1' # Formato vetrina quadrato
                folder_style: 'card'     # Cartelle categoria a scheda piena
                show_folder_item_count: true
```

### Ricetta 2: Immagini di copertina Blog / News (Banner `16:9` + Testo alternativo AI)
> **Obiettivo:** Accettare solo copertine panoramiche `16:9` ad alta risoluzione (almeno `1200×630`px), organizzare automaticamente i caricamenti in cartelle `2026/11` e generare testo alternativo SEO e tag con l'AI.

```yaml
liveux_file_manager:
    profiles:
        blog_covers:
            volumes: ['local_uploads']
            upload_subfolder: '{Y}/{m}'
            upload_allow: ['image/jpeg', 'image/png', 'image/webp']
            upload_max_size: '10M'
            image_constraints:
                min_width: 1200
                min_height: 630
                aspect_ratio: '16:9'
                aspect_ratio_tolerance: 0.08
            sort_by: 'date'
            sort_direction: 'desc'    # Copertine più recenti per prime
            ai:
                enabled: true
                locale: 'it'
                max_tags: 6
            ui:
                item_size: 'xl'           # Anteprime copertina larghe da 260px
                card_aspect_ratio: '16/9' # Proporzioni banner panoramico
```

### Ricetta 3: Archivio documenti e PDF clienti (Supabase/S3 privato + Isolamento per utente)
> **Obiettivo:** Isolare ogni cliente sulle proprie fatture PDF in un bucket S3 privato, bloccare URL esterni diretti, aggiornare gli elenchi cloud ogni 60 secondi e registrare nell'audit log ogni azione.

```yaml
liveux_file_manager:
    volumes:
        customer_vault:
            label: 'Archivio PDF clienti'
            group: 'network'
            driver: 'AwsS3'
            private: true          # Nessun accesso URL diretto non autenticato
            cache_ttl: 60          # Le modifiche esterne sul cloud appaiono entro 60s
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'customers/{user_id}' # Ogni utente vede solo la propria cartella
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['customer_vault']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
            storage_quota: '500M'
            security:
                voter_enabled: true
                audit_log: true
            ui:
                default_view: 'list'     # Tabella ordinabile ideale per i PDF
                item_size: 'sm'          # Righe compatte
                volume_order: 'network_first'
                allow_pdf_preview: true
                show_quota: true
```

### Ricetta 4: Selettore rapido per editor Rich-Text (TinyMCE / CKEditor / Tiptap)
> **Obiettivo:** Un selettore modale leggero e privo di distrazioni in cui un doppio clic su un'immagine la inserisce immediatamente nell'editor.

```yaml
liveux_file_manager:
    profiles:
        editor_picker:
            volumes: ['local_uploads']
            upload_allow: ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
            upload_max_size: '5M'
            ui:
                item_size: 'sm'
                show_folder_tree: false
                inspector_mode: 'hidden'
                double_click_to_select: true
                show_quota: false
```

### Ricetta 5: Libreria Partner / Press Kit in sola lettura
> **Obiettivo:** Permettere ai partner di sfogliare loghi e cataloghi, scaricare singoli file o ZIP multipli e generare link di condivisione firmati, impedendo qualsiasi caricamento, modifica o eliminazione.

```yaml
liveux_file_manager:
    profiles:
        press_kit:
            volumes: ['local_uploads']
            trash:
                enabled: false
            share:
                enabled: true
                default_ttl: 604800 # 7 giorni
            permissions:
                view: 'ROLE_USER'
                download: 'ROLE_USER'
                download_zip: 'ROLE_USER'
                share: 'ROLE_USER'
                upload: false
                create_folder: false
                rename: false
                clone: false
                move: false
                copy: false
                delete: false
                trash_restore: false
                trash_purge: false
                edit_metadata: false
                clear_cache: false
                extract_zip: false
                ai_enrich: false
            ui:
                allow_dropzone_upload: false
                allow_drag_move: false
                show_quota: false
                folder_open_on: 'click'
```

---

## 5. Segnaposto dinamici nei percorsi (`DynamicPathResolver`)

Puoi usare segnaposto dinamici dentro `path` locale, `url_prefix`, `driver_options.prefix` sul cloud e `upload_subfolder` del profilo:

| Segnaposto | Descrizione | Esempio di output |
| :--- | :--- | :--- |
| `{user_id}` | Identificativo sicuro per il filesystem e privo di collisioni per l'utente Symfony autenticato (`guest` se non autenticato). | `42` oppure `alice_a--h-9f1c2e4b...` |
| `{Y}` | Anno a 4 cifre | `2026` |
| `{m}` | Mese a 2 cifre | `11` |
| `{d}` | Giorno a 2 cifre | `25` |

```yaml
volumes:
    user_space:
        label: 'Il mio spazio personale'
        group: 'local'
        driver: 'LocalFileSystem'
        path: '%kernel.project_dir%/var/private_uploads/user_{user_id}/{Y}/{m}'
        private: true
```

> [!IMPORTANT]
> **Isolamento multi-tenant `{user_id}` privo di collisioni:**  
> `DynamicPathResolver` genera chiavi di directory **disgiunte con suffisso SHA-256 `<slug>--h-<16-hex>`** per email (`alice.a@example.com` vs `alice_a@example.com`), varianti maiuscole/minuscole (`Alice` vs `alice`), username Unicode (arabo, cinese, turco), parole riservate (`guest`, `user`, identificativi che terminano in `--h-`) e nomi di dispositivo Windows (`con`, `nul`, `com1`..`9`, `lpt1`..`9`). Due utenti distinti non possono mai collidere sulla stessa cartella.
>
> **Comportamento CLI:** I comandi console (`doctor --check-remote`, `stats`, `trash:purge`, `thumbs:warmup`, ecc.) saltano automaticamente i volumi che contengono `{user_id}` per evitare la creazione di una cartella indesiderata `guest/`.

---

## 6. Riferimento completo dello schema YAML (Tutte le opzioni attive)

Lo snippet YAML seguente documenta **ogni singola chiave di configurazione attiva** definita in `Configuration.php` con il rispettivo valore predefinito e commenti inline. Nel tuo progetto dichiara solo le chiavi che vuoi personalizzare: tutto il resto eredita automaticamente il valore predefinito.

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    # =========================================================================
    # 1) IMPOSTAZIONI GLOBALI — Ereditate da tutti i profili salvo override
    # =========================================================================
    defaults:
        base_path: '%kernel.project_dir%/public/uploads'   # Directory radice predefinita per profili abbreviati su singolo disco
        theme: 'bootstrap5'                                # bootstrap5 | tailwind | shadcn | flowbite | daisyui | tabler | preline | material | unstyled | <custom>
        color_scheme: 'auto'                               # auto | light | dark
        locale: null                                       # null = locale richiesta/Translator Symfony (12 lingue integrate, fallback 'en') | 'it' | 'en' | 'tr' | ...
        z_index: 1050                                      # z-index base per overlay, popover e modali (>= 1)
        auto_inject_dialog_host: true                      # Inietta automaticamente DialogHost per Modali/OffCanvas nelle risposte HTML complete
        dialog_host_profile: 'default'                     # Profilo predefinito per il DialogHost iniettato automaticamente

        audit_log:
            enabled: false                                 # Registra tutte le 9 modifiche a file/cartelle su un canale PSR-3
            level: 'info'                                  # debug | info | notice | warning

        ai:
            default_model: 'gpt-4o-mini'                   # Modello AI predefinito se non sovrascritto nel profilo
            platform_service: null                         # ID servizio PlatformInterface personalizzato di Symfony AI (null = auto)

        cache:
            enabled: true                                  # Interruttore globale cache PSR-6 per directory e alberi
            pool: 'cache.app'                              # ID servizio del pool di cache PSR-6 di Symfony
            ttl: 3600                                      # TTL globale della cache in secondi (0 = disabilitato)

        metadata:
            enabled: true                                  # Colori/icone/Accesso rapido cartelle e alt/title/caption/tags/focalPoint dei file
            driver: 'json_file'                            # json_file | doctrine | none
            file: '%kernel.project_dir%/var/file-manager/metadata.json' # File di archiviazione per il driver json_file
            connection: null                               # Nome connessione DBAL opzionale per il driver doctrine (null = connessione dell'entity_manager)
            entity_manager: 'default'                      # Nome EntityManager / connessione per il driver doctrine
            table: 'liveux_fm_metadata'                    # Nome tabella DBAL per il driver doctrine (vedi Capitolo 13)
            auto_create_table: true                        # Crea automaticamente la tabella metadati se mancante (imposta false con migrations)

    # =========================================================================
    # 2) VOLUMI DI ARCHIVIAZIONE — Definizioni filesystem locali e cloud
    # =========================================================================
    volumes:
        local_uploads:
            label: 'Archiviazione locale'                  # Nome visualizzato nella barra laterale e nei breadcrumb
            icon: null                                     # null = auto | disk | cloud | aws_s3 | cloudflare_r2 | supabase | backblaze_b2 | minio | gcs | sftp
            group: null                                    # null = auto (LocalFileSystem->local, AwsS3/Flysystem->network) | local | network
            driver: 'LocalFileSystem'                      # LocalFileSystem | AwsS3 | FlysystemService
            path: '%kernel.project_dir%/public/uploads'    # Percorso fisico (supporta {user_id}, {Y}, {m}, {d})
            url_prefix: '/uploads'                         # Prefisso URL pubblico per il browser (lascia null con private: true)
            private: false                                 # true = invia tutti i file tramite DownloadController protetto da RBAC
            cache_ttl: null                                # TTL cache per volume in secondi (null = eredita, 0 = disabilitato)
            driver_options: []                             # Vuoto per LocalFileSystem

        cloud_s3_example:
            label: 'Cloud S3 / R2 / Supabase'
            icon: 'supabase'                               # Corrisponde a resources/icons/_ui/supabase.svg
            group: 'network'
            driver: 'AwsS3'
            path: null
            url_prefix: 'https://cdn.example.com'          # URL pubblico del bucket/CDN (null con private: true)
            private: false
            cache_ttl: 60                                  # Aggiorna le modifiche esterne sul cloud ogni 60 secondi
            driver_options:
                bucket: '%env(AWS_S3_BUCKET)%'             # Nome del bucket
                region: '%env(AWS_S3_REGION)%'             # Regione ('auto' per Cloudflare R2)
                endpoint: '%env(AWS_S3_ENDPOINT)%'         # URL endpoint S3 personalizzato per provider non AWS
                prefix: 'media/{Y}'                        # Prefisso radice opzionale dentro il bucket (supporta segnaposto dinamici)
                use_path_style_endpoint: true              # true per Supabase / MinIO / R2 / B2
                key: '%env(AWS_S3_KEY)%'                   # S3 Access Key ID
                secret: '%env(AWS_S3_SECRET)%'             # S3 Secret Access Key
                token: null                                # Token di sessione temporaneo AWS STS opzionale
                version: 'latest'                          # Versione API AWS S3 (predefinito: 'latest')
                ca_bundle: null                            # Percorso .pem CA bundle personalizzato opzionale (risolve cURL error 60 su Windows)
                disable_acl: true                          # Automaticamente true per endpoint personalizzati; imposta true su AWS S3 Bucket owner enforced
                retain_visibility: false                   # Automaticamente false per endpoint personalizzati (evita l'invio di x-amz-acl)
                request_checksum_calculation: 'when_required'  # Compatibilità AWS SDK >= 3.337 (automatica per endpoint personalizzati)
                response_checksum_validation: 'when_required'  # Compatibilità AWS SDK >= 3.337 (automatica per endpoint personalizzati)

        custom_flysystem_example:
            label: 'Archivio SFTP'
            icon: 'sftp'
            group: 'network'
            driver: 'FlysystemService'
            path: null
            url_prefix: null
            private: true
            cache_ttl: 30
            driver_options:
                service: 'app.sftp.storage'                # ID servizio League\Flysystem\FilesystemOperator

    # =========================================================================
    # 3) PROFILI — Set di regole indipendenti per ogni caso d'uso
    # =========================================================================
    profiles:
        full_reference:
            # --- Volumi montati, locale e opzioni abbreviate per singolo disco ---
            locale: null                                   # null = eredita defaults.locale | 'it' | 'en' | 'tr' | 'fr' | 'de' | ...
            volumes: ['local_uploads', 'cloud_s3_example'] # Se vuoto, viene creato un volume implicito usando driver/path qui sotto
            driver: 'LocalFileSystem'                      # Driver abbreviato usato solo quando volumes: []
            path: '%kernel.project_dir%/public/uploads'    # Percorso abbreviato usato solo quando volumes: []
            url_prefix: '/uploads'                         # Prefisso URL abbreviato usato solo quando volumes: []
            private: false                                 # Flag private abbreviato usato solo quando volumes: []
            driver_options: []                             # Opzioni driver abbreviate usate solo quando volumes: []

            # --- Elenco, elementi nascosti e ordinamento predefinito ---
            ignore_dot_files: true                         # Nasconde e blocca file con punto iniziale (.env, .htaccess, .git)
            exclude_patterns: ['/^_thumbs$/i', '/^\.DS_Store$/i'] # Pattern regex/wildcard nascosti dagli elenchi e bloccati all'accesso diretto
            sort_by: 'name'                                # name | date | size | type
            sort_direction: 'asc'                          # asc | desc
            folders_first: true                            # Elenca sempre le cartelle prima dei file

            # --- Caricamenti, quota e gestione collisioni ---
            upload_subfolder: null                         # es. '{Y}/{m}' — salva i caricamenti in sottocartelle dinamiche
            upload_allow: []                               # Vuoto = tutti i tipi sicuri; non vuoto = allowlist MIME rigorosa
            upload_deny:                                   # Tipi MIME pericolosi sempre bloccati (47 di default se omesso)
                - 'text/x-php'
                - 'application/x-php'
                - 'application/x-httpd-php'
                - 'text/html'
                - 'application/xhtml+xml'
                - 'text/xml'
                - 'application/xml'
                - 'text/javascript'
                - 'application/javascript'
                - 'application/x-javascript'
                - 'application/x-sh'
                - 'application/x-msdownload'
            upload_max_size: '10M'                         # Dimensione massima ricomposta per un singolo file (es. '50M', '2G')
            storage_quota: null                            # Quota disco totale del profilo (es. '500M', '5G'; null = illimitata)
            overwrite_policy: 'rename'                     # rename (aggiunge -1) | overwrite | reject

            # --- Caricamento a blocchi (supera upload_max_filesize di PHP) ---
            chunk_upload:
                enabled: true                              # Suddivide file grandi in blocchi riprendibili
                chunk_size: '2M'                           # Dimensione target del blocco (limitata automaticamente all'80% dei limiti PHP)
                parallel_uploads: 3                        # Max caricamenti simultanei (1..10)
                max_retries: 3                             # Tentativi automatici per blocco su errori di rete (0..10)

            # --- Rilevamento duplicati (SHA-256) ---
            duplicate_detection:
                enabled: false                             # Rileva contenuti duplicati in fase di caricamento
                policy: 'warn'                             # warn | reject | reuse (restituisce il file esistente)
                max_scan_files: 5000                       # Max file sottoposti a hash per indicizzazione duplicati (100..50000)

            # --- Normalizzazione automatica nomi file ---
            naming:
                slugger: true                              # Traslittera nomi file Unicode in slug ASCII puliti
                slugger_locale: null                       # null = eredita il locale del profilo (es. 'it', 'de', 'tr', 'fr')
                transformer: 'none'                        # none | uppercase | lowercase | camel | snake | cameltitle | title | titleall | folder
                prepend: ''                                # Prefisso statico/dinamico (es. 'prodotto-')
                append: ''                                 # Suffisso statico/dinamico prima dell'estensione (es. '-{Y}{m}{d}')
                apply_on_rename: true                      # Applica le regole slugger/transformer in fase di rinomina
                apply_affixes_on_rename: false             # Riapplica prefisso/suffisso in fase di rinomina
                allow_extension_change: false              # Consente agli utenti di cambiare estensione (.jpg -> .php) rinominando

            # --- Ottimizzazione immagini al caricamento ---
            image_optimization:
                enabled: false                             # Auto-orientamento, rimozione EXIF, ridimensionamento e compressione al caricamento
                auto_orient: true                          # Corregge l'orientamento EXIF degli smartphone
                strip_exif: true                           # Rimuove GPS e dati EXIF fotocamera per privacy e risparmio di spazio
                max_width: 2400                            # Larghezza max proporzionale in px (>= 100)
                max_height: 2400                           # Altezza max proporzionale in px (>= 100)
                convert_to_webp: false                     # Converte automaticamente caricamenti JPEG/PNG in WebP
                quality: 85                                # Qualità di output (10..100)

            # --- Vincoli di risoluzione e proporzioni immagini ---
            image_constraints:
                min_width: null                            # Larghezza minima in px (es. 800)
                min_height: null                           # Altezza minima in px (es. 600)
                max_width: null                            # Larghezza massima in px (verificata dopo image_optimization)
                max_height: null                           # Altezza massima in px
                aspect_ratio: null                         # Proporzioni richieste (es. '1:1', '16:9', '4:3', '3:4')
                aspect_ratio_tolerance: 0.05               # Scostamento consentito (0.0..0.5; 0.05 = 5%)

            # --- Motore miniature ---
            thumbnail:
                enabled: true                              # Genera anteprime in cache per le schede immagine
                driver: 'auto'                             # auto (Imagick->GD) | imagick | gd | liip_imagine | none
                on_missing_driver: 'fallback_original'     # fallback_original | icon | exception
                width: 320                                 # Larghezza miniatura in px (>= 32)
                height: 240                                # Altezza miniatura in px (>= 32)
                format: 'webp'                             # webp | jpeg | png
                quality: 80                                # Qualità di compressione (10..100)
                fit: 'contain'                             # contain (adatta all'interno) | cover (ritaglia per riempire)
                lazy_load: true                            # Aggiunge loading="lazy" alle miniature delle schede
                cache_dir: '%kernel.project_dir%/public/media-cache' # Directory cache locale per le miniature generate

            # --- Cestino (Eliminazione reversibile) ---
            trash:
                enabled: true                              # Sposta gli elementi eliminati nel Cestino invece di eliminarli definitivamente
                directory: '.trash'                        # Nome della directory nascosta del Cestino
                retention_days: 30                         # Periodo di conservazione in giorni per file-manager:trash:purge
                max_size: '500M'                           # Dimensione totale massima del Cestino

            # --- Download ZIP ed estrazione sicura archivi ---
            zip:
                enabled: true                              # Abilita il download ZIP di più file/cartelle
                max_files: 100                             # Max file in un singolo download ZIP
                max_total_size: '250M'                     # Dimensione totale max per un singolo download ZIP
                allow_extract: false                       # Abilita l'estrazione archivi (disabilitata di default; protetta a 7 livelli)
                max_extract_files: 200                     # Max file estratti per archivio (1..2000)
                max_extract_size: '100M'                   # Dimensione totale estratta max (protezione Zip-Bomb)
                max_compression_ratio: 100                 # Rapporto di compressione massimo consentito (1..1000 — protezione Zip-Bomb)

            # --- Link di condivisione firmati a scadenza (HMAC) ---
            share:
                enabled: true                              # Consente di generare link di condivisione firmati
                default_ttl: 86400                         # Scadenza predefinita in secondi (86400 = 24 ore)
                max_ttl: 2592000                           # Scadenza massima selezionabile in secondi (2592000 = 30 giorni)
                allow_inline_preview: true                 # Consente l'anteprima inline nel browser per i tipi MIME sicuri

            # --- Arricchimento opzionale Symfony AI ---
            ai:
                enabled: false                             # Mostra "✨ Suggerisci con AI" nella finestra metadati
                model: null                                # null = defaults.ai.default_model
                locale: null                               # null = locale del profilo/richiesta (es. 'it' => testo alternativo e tag in italiano)
                max_tags: 6                                # Numero massimo di tag suggeriti (1..20)
                max_image_dimension: 1024                  # Limite di ridimensionamento prima dell'invio all'AI (256..2048 px)
                block_private_volumes: true                # true = blocca le chiamate AI sui volumi private: true (predefinito: true)
                auto_enrich_on_upload: false               # Esegue automaticamente l'arricchimento AI dopo i caricamenti

            # --- Asset Usage Guard (Delete / Rename / Move / Overwrite) ---
            usage_guard:
                enabled: false                             # Verifica riferimenti nel DB prima di modificare gli asset
                policy: 'warn'                             # warn (mostra riferimenti + consente di procedere) | block (impedisce l'azione)
                enforce_server_side: true                  # Applica la protezione anche sulle chiamate API dirette
                max_references: 20                         # Max riferimenti elencati nella finestra (1..200)

            # --- Cache per profilo ---
            cache:
                enabled: null                              # null = defaults.cache.enabled | true | false
                ttl: null                                  # null = defaults.cache.ttl | secondi (0 = disabilitato)

            # --- Opzioni di sicurezza ---
            security:
                voter_enabled: true                        # Abilita i controlli RBAC di FileManagerVoter
                csrf_protection: true                      # Richiede X-LiveUX-FM-CSRF sulle richieste POST
                sanitize_svg: true                         # Sanifica i file SVG caricati/modificati
                allow_remote_url_import: false             # Abilita la scheda "Da URL" (protetta da SSRF)
                audit_log: null                            # null = defaults.audit_log.enabled | true | false

            # --- 17 permessi RBAC granulari (true = utente autenticato | false | 'ROLE_...' | ['ROLE_A', 'ROLE_B']) ---
            permissions:
                view: true                                 # Permesso principale richiesto per l'elenco e tutte le altre azioni
                upload: true                               # Caricamenti file, salvataggi editor immagini e importazioni da URL
                create_folder: true                        # Creazione nuove cartelle
                rename: true                               # Rinomina file e cartelle
                clone: true                                # Duplicazione file/cartelle
                move: true                                 # Spostamento elementi (inclusi drag-and-drop e Taglia/Incolla)
                copy: true                                 # Copia elementi (incluso Copia/Incolla)
                delete: true                               # Eliminazione elementi (Cestino o eliminazione definitiva)
                trash_restore: true                        # Ripristino elementi dal Cestino
                trash_purge: true                          # Eliminazione definitiva dal Cestino / svuotamento Cestino
                download: true                             # Download singolo file e copia link
                download_zip: true                         # Download ZIP multi-file
                extract_zip: false                         # Estrazione archivi ZIP (richiede anche zip.allow_extract: true)
                share: true                                # Creazione link di condivisione firmati
                clear_cache: true                          # Svuotamento cache da Impostazioni e forzatura ?refresh=1
                edit_metadata: true                        # Modifica alt/tag/punto focale file e colore/icona/Accesso rapido cartelle
                ai_enrich: true                            # Esecuzione arricchimento metadati AI

            # --- Flag interfaccia utente (UI) e dimensionamento ---
            ui:
                theme: null                                # null = defaults.theme
                color_scheme: null                         # null = defaults.color_scheme | auto | light | dark
                icon_set: 'classic'                        # classic | windows11 | macos | linux | minimal | vscode | <dir personalizzata>
                z_index: null                              # null = defaults.z_index | intero (>= 1)
                mobile_dialog_mode: 'auto'                 # Comportamento finestre su mobile (<= 767px): auto | fullscreen | sheet
                default_view: 'grid'                       # grid | list
                item_size: 'md'                            # xs (108) | sm (132) | md (158) | lg (204) | xl (260) | 96..320 px (<=132px compatto)
                card_aspect_ratio: '4/3'                   # 1/1 | 4/3 | 16/9 | 3/4
                folder_style: 'tile'                       # tile (compatto) | card (scheda cartella a dimensione piena)
                show_size_slider: true                     # Mostra cursore dimensione elementi e stile cartelle in Impostazioni
                folder_open_on: 'dblclick'                 # dblclick | click
                volume_order: 'local_first'                # local_first | network_first | configured
                inspector_mode: 'on_select'                # on_select | always | hidden
                show_folder_tree: true                     # Mostra la barra laterale sinistra dell'albero delle cartelle
                tree_lines: true                           # Mostra le linee di connessione gerarchica nell'albero delle cartelle
                show_folder_item_count: true               # Mostra i badge con il conteggio elementi sulle cartelle
                folder_count_mode: 'recursive_files'       # recursive_files | files | items
                show_quota: true                           # Mostra la barra quota nella barra laterale e il badge quota nell'intestazione
                show_quota_details: true                   # Abilita la finestra cliccabile Utilizzo spazio e dettagli quota
                per_page: 24                               # Elementi per pagina predefiniti (6..200)
                per_page_options: [24, 48, 96]             # Opzioni del menu Elementi per pagina
                show_perf_badge: false                     # Mostra il badge prestazioni CACHE HIT / DISCO nella barra degli strumenti
                show_nav_buttons: true                     # Interruttore principale per il gruppo Indietro / Avanti / Su / Aggiorna
                show_history_buttons: true                 # Mostra i pulsanti cronologia Indietro / Avanti
                show_up_button: true                       # Mostra il pulsante Cartella superiore
                show_refresh_button: true                  # Mostra il pulsante Aggiorna (?refresh=1)
                show_breadcrumbs: true                     # Mostra il percorso di navigazione cliccabile/trascinabile
                show_search: true                          # Mostra casella di ricerca e ricerca approfondita nelle sottocartelle
                show_type_filter: true                     # Mostra filtro per tipo (Immagini / Video / File / Cartelle)
                show_view_toggle: true                     # Mostra il selettore vista Griglia / Elenco
                show_pagination: true                      # Mostra la barra di paginazione inferiore
                show_per_page_selector: true               # Mostra il selettore Elementi per pagina
                show_sort: true                            # Mostra menu di ordinamento e direzione
                show_fullscreen_button: true               # Mostra il pulsante Schermo intero
                show_settings_button: true                 # Mostra il menu Impostazioni (⚙)
                show_footer: true                          # Mostra la barra di stato inferiore
                show_context_nav: true                     # Mostra la striscia di navigazione rapida nel menu contestuale su area vuota
                allow_dropzone_upload: true                # Consente il trascinamento di file/cartelle dal sistema operativo sull'elenco file
                remember_last_dir: true                    # Ricorda ultimo volume e cartella (si ripristina se eliminati)
                confirm_delete: true                       # Mostra finestra di conferma prima di eliminare
                confirm_move: true                         # Mostra finestra di conferma prima di spostare/copiare
                double_click_to_select: true               # Il doppio clic seleziona il file e chiude il selettore Modale/OffCanvas
                allow_context_menu: true                   # Abilita il menu contestuale con tasto destro
                allow_pdf_preview: true                    # Lightbox PDF inline (false forza il download)
                allow_video_preview: true                  # Player video inline (false forza il download)
                keyboard_shortcuts: true                   # Abilita le scorciatoie da tastiera nel contesto del componente
                allow_drag_move: true                      # Consente il trascinamento di elementi dentro le cartelle per spostarli
```

---

[← Precedente: 01 — Requisiti e installazione](01-requirements-and-installation.md) | [Indice della documentazione](README.md) | [Avanti: 03 — Modalità d'uso e API JS →](03-usage-modes-and-js-api.md)
