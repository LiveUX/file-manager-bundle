# Guida all'integrazione con Backblaze B2

[← 07 — Cloud Storage e configurazione dinamica](../07-cloud-storage-and-dynamic-config.md) | [Indice principale](../README.md)

> **Quando scegliere Backblaze B2?**  
> Backblaze B2 è l'ideale quando hai bisogno di archiviare centinaia di gigabyte di video, PDF pronti per la stampa o archivi ad alta risoluzione al minor costo di archiviazione possibile (specialmente se abbinato al traffico in uscita gratuito offerto dai partner della Bandwidth Alliance come Cloudflare o Bunny CDN).

L'endpoint `.backblazeb2.com` viene rilevato automaticamente e nella sidebar viene mostrata l'icona SVG `backblaze_b2`.

---

## Passaggio 1: Installare i pacchetti richiesti

```bash
composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php
```

---

## Passaggio 2: Configurare bucket, Application Key e CORS in Backblaze

1. Vai su Backblaze B2 → **Buckets** → **Create a Bucket** (es. `app-media`).
   - Dopo la creazione, annota l'**Endpoint** mostrato nella scheda del bucket (es. `s3.eu-central-003.backblazeb2.com`).
   - La **Region** è il segmento centrale dell'endpoint: `eu-central-003`.
2. Vai su **Application Keys** → **Add a New Application Key**:
   - **Allow access to Bucket(s):** seleziona solo il bucket appena creato
   - **Type of Access:** `Read and Write`
   - Copia i valori **`keyID`** e **`applicationKey`** generati.
3. *(Opzionale — per l'Editor Immagini)* In Bucket → **CORS Rules**, scegli **Share everything in this bucket with every origin** oppure aggiungi una regola S3 CORS personalizzata per il tuo dominio:
   ```json
   [
       {
           "corsRuleName": "liveux-file-manager-editor",
           "allowedOrigins": ["https://your-site.com"],
           "allowedOperations": ["s3_get", "s3_head"],
           "allowedHeaders": ["*"],
           "exposeHeaders": ["ETag"],
           "maxAgeSeconds": 3600
       }
   ]
   ```

---

## Passaggio 3: Configurare `.env.local` e `liveux_file_manager.yaml`

```dotenv
# .env.local
B2_BUCKET=app-media
B2_REGION=eu-central-003
B2_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
B2_KEY_ID=003xxxxxxxxxxxxxxxxxxxxxx
B2_APP_KEY=K003yyyyyyyyyyyyyyyyyyyyyyyyy
```

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        backblaze_b2:
            label: 'Backblaze B2'
            icon: 'backblaze_b2'      # Opzionale — rilevata automaticamente dall'endpoint
            group: 'network'
            driver: 'AwsS3'
            # Per bucket pubblici, usa il Friendly URL o il tuo dominio CDN (imposta null se privato):
            url_prefix: 'https://f003.backblazeb2.com/file/%env(B2_BUCKET)%'
            private: false
            cache_ttl: 120
            driver_options:
                bucket: '%env(B2_BUCKET)%'
                region: '%env(B2_REGION)%'
                endpoint: '%env(B2_ENDPOINT)%'
                use_path_style_endpoint: true
                key: '%env(B2_KEY_ID)%'
                secret: '%env(B2_APP_KEY)%'

    profiles:
        default:
            volumes: ['backblaze_b2']
```

> **Nota:** Poiché è configurato un endpoint personalizzato, `FlysystemStorageFactory` abilita automaticamente per Backblaze B2 la compatibilità checksum di AWS SDK v3.337+ (`when_required`), `disable_acl: true` e `retain_visibility: false`.

---

## Passaggio 4: Verificare la connettività live

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Precedente: Cloudflare R2](cloudflare-r2.md) | [07 — Indice Cloud Storage](../07-cloud-storage-and-dynamic-config.md) | [Successivo: MinIO / RustFS →](minio-rustfs.md)
