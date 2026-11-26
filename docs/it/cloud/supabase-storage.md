# Guida all'integrazione con Supabase Storage (S3)

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Indice principale](../README.md)

> **Quando scegliere Supabase Storage?**  
> Se hai già un progetto Supabase — oppure desideri un piano gratuito generoso per gestire sia asset CDN pubblici sia bucket privati di documenti da un'unica dashboard — Supabase Storage è la scelta S3-compatibile più pratica.

Poiché **Supabase Storage** supporta il protocollo S3-compatibile, funziona fin da subito con LiveUX FileManagerBundle in una configurazione ibrida **Local Storage + Supabase Cloud**, senza scrivere una sola riga di codice personalizzato. Il bundle rileva automaticamente il provider `supabase` dall'URL dell'endpoint e mostra l'icona SVG di Supabase nella sidebar.

---

## Passaggio 1: Installare i pacchetti richiesti

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passaggio 2: Generare le S3 Access Keys nella Supabase Dashboard

> [!WARNING]
> **Importante:** Le chiavi API REST/Auth di Supabase — `SUPABASE_PUBLISHABLE_KEY` (`sb_publishable_...`) e `SUPABASE_SECRET_KEY` (`sb_secret_...`) — **non** funzionano con il protocollo S3. Devi utilizzare le credenziali da 32 e 64 caratteri generate nella sezione **S3 Access Keys** descritta qui sotto.

1. Apri il tuo progetto nella Supabase Dashboard → **Storage** → **New bucket** e crea un bucket (es. `media` o `prints`).
   - Seleziona **Public bucket** per immagini e asset accessibili pubblicamente tramite CDN.
   - Lascia **Public bucket** deselezionato (privato) per documenti dei clienti o fatture.
2. Vai in **Project Settings** → **Storage** (oppure **Storage** → **Settings**).
3. Copia questi due valori dalla scheda **S3 Connection**:
   - **Endpoint:** `https://<project-ref>.storage.supabase.co/storage/v1/s3`
   - **Region:** es. `eu-central-1` o `eu-west-1`
4. Sotto **S3 Access Keys**, fai clic su **New access key**, inserisci una descrizione e copia i valori generati:
   - **Access Key ID** (32 caratteri)
   - **Secret Access Key** (64 caratteri — mostrata una sola volta!)

---

## Passaggio 3: Aggiungere le variabili d'ambiente in `.env.local`

```dotenv
# .env.local
SUPABASE_PROJECT_REF=abcdefghijklmnopqrst
SUPABASE_S3_ENDPOINT=https://abcdefghijklmnopqrst.storage.supabase.co/storage/v1/s3
SUPABASE_S3_REGION=eu-central-1
SUPABASE_S3_BUCKET=media
SUPABASE_S3_KEY=your_32_char_access_key_id
SUPABASE_S3_SECRET=your_64_char_secret_access_key
```

---

## Passaggio 4: Configurare `liveux_file_manager.yaml`

### Scenario A — Bucket pubblico

Immagini, video e file pubblici vengono serviti direttamente tramite l'URL CDN di Supabase (alleggerendo il carico sul server PHP), mentre le miniature vengono generate una sola volta sul server e servite dalla cache locale (`public/media-cache`):

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        local_uploads:
            label: 'Archivio locale'
            group: 'local'
            driver: 'LocalFileSystem'
            path: '%kernel.project_dir%/public/uploads'
            url_prefix: '/uploads'

        supabase_public:
            label: 'Supabase Storage'
            icon: 'supabase'          # Opzionale — rilevata automaticamente dall'endpoint
            group: 'network'
            driver: 'AwsS3'
            url_prefix: 'https://%env(SUPABASE_PROJECT_REF)%.supabase.co/storage/v1/object/public/%env(SUPABASE_S3_BUCKET)%'
            private: false
            cache_ttl: 60             # Cache di 60s per contenuti cloud che possono cambiare dall'esterno
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        default:
            volumes: ['local_uploads', 'supabase_public']
            ui:
                volume_order: 'local_first' # oppure 'network_first' / 'configured'
```

### Scenario B — Bucket privato con isolamento per utente

Per fatture dei clienti o PDF riservati accessibili solo agli utenti autenticati e autorizzati:

```yaml
liveux_file_manager:
    volumes:
        supabase_private:
            label: 'Archivio documenti Supabase'
            group: 'network'
            driver: 'AwsS3'
            url_prefix: null          # Nessun URL pubblico perché il bucket è privato
            private: true             # Streaming sicuro tramite DownloadController + Voter
            cache_ttl: 30
            driver_options:
                bucket: '%env(SUPABASE_S3_BUCKET)%'
                region: '%env(SUPABASE_S3_REGION)%'
                endpoint: '%env(SUPABASE_S3_ENDPOINT)%'
                prefix: 'users/{user_id}' # Isola ogni utente nella propria sottocartella senza collisioni
                use_path_style_endpoint: true
                key: '%env(SUPABASE_S3_KEY)%'
                secret: '%env(SUPABASE_S3_SECRET)%'

    profiles:
        customer_docs:
            volumes: ['supabase_private']
            upload_allow: ['application/pdf']
            upload_max_size: '50M'
```

---

## Passaggio 5: Verificare la connettività live con un solo comando

```bash
php bin/console file-manager:doctor --check-remote
```

Esempio di output in caso di successo:
```text
 default | supabase_public | network | AwsS3 | OK | media — Connected (420ms)
 [OK] Diagnostics & live cloud connectivity checks passed.
```

> **Nota:** I volumi dinamici per utente che utilizzano segnaposto come `prefix: 'users/{user_id}'` (come nello Scenario B) vengono saltati in sicurezza con stato `INFO` da `file-manager:doctor --check-remote`, poiché nell'ambiente CLI non è presente alcuna sessione utente attiva.

---

## Suggerimenti e risoluzione problemi specifici per Supabase

### 1. Eliminazione di file in un bucket con restrizioni MIME (`InvalidMimeType: application/json`)
Se hai configurato **Allowed MIME types** sul tuo bucket Supabase per consentire solo tipi come `application/pdf` o `image/*`, Supabase restituirà `InvalidMimeType` quando la funzionalità cestino tenterà di scrivere `.trash/<id>/.meta.json`.
- **Fallback automatico:** `TrashManager` rileva automaticamente questa condizione: il file viene spostato in `.trash/<id>/...` nel bucket cloud, mentre i metadati `.meta.json` vengono salvati in un file di fallback locale sul server (`trash-meta.json` oppure `<name>.trash-meta.json` nella directory configurata in `defaults.metadata.file`). Ripristino ed eliminazione definitiva continuano a funzionare senza interruzioni.

### 2. Errori di checksum e ACL in AWS SDK v3.337+
Per i volumi S3 configurati con un `endpoint` personalizzato come Supabase, `FlysystemStorageFactory` applica automaticamente:
- `request_checksum_calculation: 'when_required'` e `response_checksum_validation: 'when_required'`
- `retain_visibility: false` e rimozione degli header `x-amz-acl` (`disable_acl: true`)

Non è richiesta alcuna configurazione aggiuntiva.

### 3. Errore certificato SSL `cURL error 60` su Windows / WAMP
Se incontri `cURL error 60: unable to get local issuer certificate` sulla tua macchina di sviluppo locale:
- Scarica [cacert.pem](https://curl.se/ca/cacert.pem) e indicalo in `php.ini` (sia nel `php.ini` di Apache sia in quello della CLI):
  ```ini
  curl.cainfo = "C:\wamp64\bin\php\cacert.pem"
  openssl.cafile = "C:\wamp64\bin\php\cacert.pem"
  ```
- In alternativa, configuralo solo per un volume specifico tramite `driver_options.ca_bundle: '%kernel.project_dir%/config/certs/cacert.pem'`.

### 4. I file eliminati dalla Supabase Dashboard continuano ad apparire nell'elenco
- Fai clic sul pulsante **Aggiorna (`↻`)** nella toolbar (oppure premi `R`) per bypassare immediatamente la cache tramite `?refresh=1`.
- Se i file nel bucket vengono modificati frequentemente dall'esterno, imposta `cache_ttl: 30` (oppure `cache_ttl: 0` per disabilitare la cache) sul volume.

---

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Successivo: Guida AWS S3 →](aws-s3.md)
