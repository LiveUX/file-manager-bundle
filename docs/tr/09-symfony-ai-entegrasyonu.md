# 09 — Symfony AI Entegrasyonu & Akıllı Varlık Zenginleştirme

[← Önceki: 08 — Event'ler ve CLI Komutları](08-olaylar-komutlar-cache-ve-sorun-giderme.md) | [Ana İndeks](README.md) | [Sonraki: 10 — Kullanım Koruması (Usage Guard) →](10-kullanim-korumasi-usage-guard.md)

> **En Basit Haliyle: Bu Özellik Ne İşe Yarar?**  
> Editörünüz bir ürün fotoğrafı yüklediğinde tek tek *"alt metni, başlık, açıklama ve arama etiketleri"* yazmak zorunda kalmaz. Sağ paneldeki **"✨ AI ile Öner"** butonuna basınca yapay zeka (OpenAI, Gemini, Claude veya yerel Ollama) görsele bakıp seçtiğiniz dilde SEO açıklaması ve `#etiket` önerilerini 2 saniyede doldurur; editör onaylarsa kaydedilir.  
> *(Varsayılan olarak özel/private diskler güvenliğiniz için dış AI servisine gönderilmez: `block_private_volumes: true`.)*

**LiveUX FileManagerBundle**, resmi **[Symfony AI](https://github.com/symfony/ai)** (`symfony/ai-bundle` + `symfony/ai-platform`) ekosistemiyle **sıfır zorunlu bağımlılık** ilkesiyle bütünleşir:

- `symfony/ai-bundle` kurulu **değilse** bundle 0 hata, 0 ek bellek yüküyle çalışır; arayüzde AI butonları otomatik gizlenir.
- `symfony/ai-bundle` **kuruluysa** `FileManagerBundle` yerleşik `SymfonyAiAssetEnricher` köprüsünü otomatik algılar ve kaydeder.
- AI özellikleri **profil bazlı açıkça açılmadıkça (`ai.enabled: true`)** kapalıdır — asla sürpriz API faturası oluşmaz.

---

## 1. Neler Kazandırır?

| Özellik | Açıklama |
| :--- | :--- |
| **Otomatik SEO & WCAG `alt` Metni** | Görselleri analiz edip seçtiğiniz dilde (`tr`, `en`, `fr`, `de`, `ja`, `ar` vb.) ekran okuyucu ve SEO uyumlu `alt` metni, kısa `title` ve `caption` üretir. |
| **Akıllı Otomatik Etiketleme (`tags`)** | Görsel veya metin dosyasının içeriğini anlayıp anahtar kelimeler üretir (ör. `["termos", "mat siyah", "kurumsal hediye"]`). Dosya yöneticisindeki `#etiket` aramasıyla anında çalışır. |
| **Metin / Belge Özeti** | `.txt`, `.md`, `.csv`, `.json`, `.yaml`/`.yml` dosyalarının ilk 8 KB'lık bölümünden başlık, açıklama ve arama etiketleri çıkarır. |
| **Otomatik Token Maliyeti Tasarrufu** | 10–15 MB'lık fotoğraflar Vision API'ye ham gönderilmez; geçici kopyada otomatik `max_image_dimension` (varsayılan `1024px`) boyutuna küçültülür (~%80 daha düşük token maliyeti). |
| **Sağlayıcı Özgürlüğü** | OpenAI, Google Gemini, Anthropic Claude, Mistral, VertexAI veya tamamen **yerel & ücretsiz Ollama** arasında tek satır YAML değiştirerek geçiş yapabilirsiniz. |
| **3 Çalışma Modu** | Arayüzde editör onaylı **"✨ AI ile Öner"**, CLI ile toplu eski arşiv zenginleştirme (`file-manager:ai:enrich`) veya `symfony/messenger` ile arka planda otomatik işleme. |

---

## 2. Kurulum (Sağlayıcınıza Göre Birini Seçin)

### Seçenek A — OpenAI (`gpt-4o-mini` / `gpt-4.1-mini`)
```bash
composer require symfony/ai-bundle symfony/ai-open-ai-platform
```
```dotenv
# .env.local
OPENAI_API_KEY="sk-..."
```
```yaml
# config/packages/ai.yaml
ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
```

### Seçenek B — Google Gemini (`gemini-2.5-flash`)
```bash
composer require symfony/ai-bundle symfony/ai-gemini-platform
```
```dotenv
# .env.local
GEMINI_API_KEY="AIza..."
```
```yaml
# config/packages/ai.yaml
ai:
    platform:
        gemini:
            api_key: '%env(GEMINI_API_KEY)%'
```

### Seçenek C — Anthropic Claude (`claude-sonnet-4-20250514` / `claude-3-5-haiku-latest`)
```bash
composer require symfony/ai-bundle symfony/ai-anthropic-platform
```
```dotenv
# .env.local
ANTHROPIC_API_KEY="sk-ant-..."
```
```yaml
# config/packages/ai.yaml
ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
```

### Seçenek D — Yerel & %100 Gizlilik Odaklı Ollama (`qwen2.5vl` / `llava`)
Şirket içi gizli belgelerin dışarı çıkmaması gereken projelerde ücretsiz yerel model kullanabilirsiniz:
```bash
ollama pull qwen2.5vl
composer require symfony/ai-bundle symfony/ai-ollama-platform
```
```yaml
# config/packages/ai.yaml
ai:
    platform:
        ollama:
            host_url: 'http://127.0.0.1:11434'
```

---

## 3. LiveUX FileManagerBundle Yapılandırması

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    defaults:
        locale: 'tr'
        ai:
            default_model: 'gpt-4o-mini'
            # Birden fazla Symfony AI platformunuz varsa belirli bir servisi seçebilirsiniz (opsiyonel):
            # platform_service: 'ai.platform.ollama'

    profiles:
        products:
            volumes: ['local_uploads']
            ai:
                enabled: true                     # Varsayılan: false (maliyet koruması)
                model: 'gpt-4o-mini'              # Örn: 'gemini-2.5-flash', 'qwen2.5vl'
                locale: 'tr'                      # null ise istek/profil dilini miras alır
                max_tags: 6                       # 1..20 arası etiket limiti
                max_image_dimension: 1024         # Vision API öncesi otomatik küçültme (256..2048 px)
                block_private_volumes: true       # private: true hacimlerde dış AI'yı engeller
                auto_enrich_on_upload: false      # true => senkron yükleme anında çalıştırır; Messenger için false bırakın
            permissions:
                view: 'ROLE_USER'
                edit_metadata: ['ROLE_EDITOR', 'ROLE_ADMIN']
                ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']

        contracts_private:
            volumes: ['private_vault']
            ai:
                enabled: false                    # Hassas sözleşmelerde tamamen kapalı
```

### AI Dil Öncelik Sırası (`ai.locale`)
Arayüzde **✨ AI ile Öner** çalıştırıldığında AI'ın hangi dilde yanıt vereceği şu öncelik sırasıyla belirlenir (BCP-47, ICU ve POSIX `.UTF-8` kodları desteklenir):
1. İstek gövdesinde açıkça gönderilen `aiLocale`
2. Profildeki `profiles.<ad>.ai.locale` *(ayarlıysa UI dilini ezer!)*
3. Aktif HTTP isteğinin / arayüzün dili
4. Profildeki `profiles.<ad>.locale`
5. `'en'` *(garantili yedek)*

---

## 4. Arayüz Kullanımı (Editör Onaylı Tek Tık)

1. Dosya yöneticisinde bir görsele veya metin dosyasına sağ tıklayın → **🏷️ Metadata & Odak Noktası** (veya sağ Inspector panelinden).
2. Aşağıdaki **5 şartın tamamı** sağlandığında modalda **✨ AI ile Öner** butonu görünür ve sunucuda da zorunlu doğrulanır:
   - `defaults.metadata.enabled: true`
   - `profiles.<ad>.ai.enabled: true`
   - Aktif bir `AssetEnricherInterface` servisi mevcut
   - Kullanıcının ilgili dosya ve tüm üst klasörleri için **`FILE_MANAGER_VIEW` + `FILE_MANAGER_EDIT_METADATA` + `FILE_MANAGER_AI_ENRICH`** yetkilerinin üçüne birden sahip olması
   - Hacim `private: true` ise `block_private_volumes: false` yapılmış olması
3. Butona tıklandığında mevcut metadata **henüz değişmez**; AI'ın önerdiği `alt`, `title`, `caption` ve `tags` alanları forma dolar. Editör düzenleyip **Kaydet** dediğinde kaydedilir.

---

## 5. CLI ile Eski Arşivi Toplu Zenginleştirme

Mevcut binlerce görselin eksik `alt` ve etiketlerini maliyet kontrollü şekilde tek komutla doldurabilirsiniz:

```bash
# 1. Önce AI API çağırmadan hangi dosyaların işleneceğini görün (ücretsiz önizleme):
php bin/console file-manager:ai:enrich products --limit=25 --dry-run

# 2. Metadata'sı eksik ilk 50 dosyayı Türkçe zenginleştir:
php bin/console file-manager:ai:enrich products --limit=50 --locale=tr

# 3. Belirli bir hacim ve alt klasörde çalıştır:
php bin/console file-manager:ai:enrich products --volume=local_uploads --path=2026/katalog --limit=100

# 4. Mevcut metadata'yı zorla yeniden üret (--force):
php bin/console file-manager:ai:enrich products --path=kampanya --force --limit=20
```

> [!NOTE]
> **CLI Güvenlik ve Maliyet Kuralları (`file-manager:ai:enrich`):**
> - `--limit` varsayılanı **`50`**'dir; yanlışlıkla tüm arşivi tek seferde ücretli API'ye göndermenizi önler.
> - `--force` olmadan yalnızca **eksik editoryal metadatası** olan dosyalar işlenir (`alt` dolu **ve** `tags`/`title`/`caption` alanlarından en az biri doluysa dosya otomatik atlanır).
> - Metadata ön-yükleme (`preload()`) **250'şer öğelik** gruplarla yapılır; **20 MB** üstü dosyalar, `{user_id}` dinamik hacimler ve `block_private_volumes: true` iken `private: true` hacimler otomatik atlanır.

---

## 6. Yükleme Sonrası Otomatik Asenkron Zenginleştirme (`symfony/messenger`)

> **Önemli:** `ai.auto_enrich_on_upload: true` yapıldığında yerleşik `MetadataSyncListener` yükleme isteği sırasında AI çağrısını **senkron** çalıştırır. Kullanıcıyı yüklerken bekletmemek için `symfony/messenger` kullanıyorsanız `ai.auto_enrich_on_upload: false` bırakın.

### Adım 1 — Messenger Mesajı
```php
<?php
// src/Message/EnrichUploadedAssetMessage.php

declare(strict_types=1);

namespace App\Message;

final readonly class EnrichUploadedAssetMessage
{
    public function __construct(
        public string $profileName,
        public string $volumeName,
        public string $path,
    ) {
    }
}
```

### Adım 2 — `FileUploadedEvent` Dinleyicisi
```php
<?php
// src/EventListener/DispatchAssetEnrichmentListener.php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\EnrichUploadedAssetMessage;
use LiveUX\FileManagerBundle\Event\FileUploadedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: FileUploadedEvent::class)]
final readonly class DispatchAssetEnrichmentListener
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(FileUploadedEvent $event): void
    {
        if (($event->profile->ai['enabled'] ?? false) !== true) {
            return;
        }
        // Senkron auto_enrich_on_upload açıksa çift işlemeyi önle:
        if (($event->profile->ai['auto_enrich_on_upload'] ?? false) === true) {
            return;
        }
        // ZIP arşiv çıkarmada tek seferde 200 dosyanın AI kuyruğunu şişirmesini önle:
        if ($event->fromArchiveExtraction) {
            return;
        }

        $this->messageBus->dispatch(new EnrichUploadedAssetMessage(
            profileName: $event->profile->name,
            volumeName: $event->volume->name,
            path: $event->path,
        ));
    }
}
```

### Adım 3 — Messenger Worker Handler
```php
<?php
// src/MessageHandler/EnrichUploadedAssetHandler.php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EnrichUploadedAssetMessage;
use LiveUX\FileManagerBundle\Config\ConfigProviderInterface;
use LiveUX\FileManagerBundle\Service\AssetEnrichmentService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EnrichUploadedAssetHandler
{
    public function __construct(
        private ConfigProviderInterface $configProvider,
        private AssetEnrichmentService $enrichmentService,
    ) {
    }

    public function __invoke(EnrichUploadedAssetMessage $message): void
    {
        $profile = $this->configProvider->getProfile($message->profileName);
        if (!$this->enrichmentService->isAvailableForProfile($profile)) {
            return;
        }

        $this->enrichmentService->enrichAndSave(
            profile: $profile,
            path: $message->path,
            volumeName: $message->volumeName,
            onlyMissing: true,
            skipVoter: true, // Arka plan worker'ında HTTP kullanıcı oturumu yoktur
        );
    }
}
```

> [!WARNING]
> **Worker Notları:**  
> 1. Arka plan Messenger worker'ında aktif bir kullanıcı oturumu olmadığı için `{user_id}` dinamik hacimler `guest` olarak çözülür; kullanıcıya özel `{user_id}` hacimleri Messenger ile işlemeyin.  
> 2. `skipVoter: true` verilse bile `block_private_volumes: true` güvenliği çalışmaya devam eder ve `private: true` bir hacimde `AccessDeniedException` fırlatır.

---

## 7. Kendi Özel AI Sağlayıcınızı Yazma (`AssetEnricherInterface`)

`symfony/ai-bundle` kurmadan kendi Python mikroservisinizi, AWS Rekognition'ı, Azure Vision'ı veya özel HTTP API'nizi bağlayabilirsiniz:

```php
<?php
// src/AI/InternalVisionEnricher.php

declare(strict_types=1);

namespace App\AI;

use LiveUX\FileManagerBundle\AI\AssetEnricherInterface;
use LiveUX\FileManagerBundle\AI\AssetEnrichmentResult;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(AssetEnricherInterface::class)]
final readonly class InternalVisionEnricher implements AssetEnricherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $visionEndpoint,
    ) {
    }

    public function supports(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function enrich(
        string $localFilePath,
        string $mimeType,
        string $locale = 'en',
        array $context = [],
    ): AssetEnrichmentResult {
        $response = $this->httpClient->request('POST', $this->visionEndpoint, [
            'body' => [
                'locale' => $locale,
                'max_tags' => $context['max_tags'] ?? 6,
                'file' => fopen($localFilePath, 'rb'),
            ],
        ])->toArray();

        return new AssetEnrichmentResult(
            alt: $response['alt'] ?? null,
            title: $response['title'] ?? null,
            caption: $response['caption'] ?? null,
            tags: $response['tags'] ?? [],
            provider: 'internal-vision',
            model: 'v1',
        );
    }
}
```

`#[AsAlias(AssetEnricherInterface::class)]` sayesinde hem arayüzdeki **✨ AI ile Öner** butonu hem de `file-manager:ai:enrich` CLI komutu otomatik olarak bu servisi kullanır.

---

## 8. Güvenlik, Prompt Injection & LLM Red Koruması

1. **Gizli Dosyalar (`private: true`):** Bulut LLM kullanıyorsanız `block_private_volumes: true` bırakın veya yerel **Ollama** bağlayın.
2. **Yetkilendirme:** Ücretli API'ye erişimi `permissions.ai_enrich: ['ROLE_EDITOR', 'ROLE_ADMIN']` ile sınırlandırın (`VIEW` + `EDIT_METADATA` + `AI_ENRICH` birlikte denetlenir).
3. **Maliyet Sınırı:** `max_image_dimension: 1024` (veya `768`) tutun; CLI'da her zaman önce `--dry-run` ve `--limit` kullanın.
4. **Prompt Injection & Çok-Dilli LLM Red Koruması:**
   - Dosya adları prompt'a verilmeden önce kontrol/bidi/tırnak karakterlerinden temizlenir ve **120 karakter** ile sınırlandırılır.
   - `SymfonyAiAssetEnricher::parseJsonResponse()`, **11 dilde** (EN, TR, FR, DE, ES, AR, FA, RU, JA, ZH, KO) modelin *"Üzgünüm, bu görseli analiz edemem / I'm sorry, I cannot..."* benzeri red yanıtlarını algılar ve bunların yanlışlıkla veritabanına `alt` metni olarak kaydedilmesini engeller.
   - Dönen tüm metinler `AssetEnrichmentResult` ve `FolderMetadata::sanitizeTags()` süzgecinden geçer ve DOM'da daima HTML-escape edilerek basılır.

---

[← Önceki: 08 — Event'ler ve CLI Komutları](08-olaylar-komutlar-cache-ve-sorun-giderme.md) | [Ana İndeks](README.md) | [Sonraki: 10 — Kullanım Koruması (Usage Guard) →](10-kullanim-korumasi-usage-guard.md)
