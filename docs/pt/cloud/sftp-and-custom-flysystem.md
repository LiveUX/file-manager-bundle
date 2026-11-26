# SFTP, Azure Blob e Serviços Flysystem Personalizados (`FlysystemService`)

[← 07 — Armazenamento em Nuvem e Configuração Dinâmica](../07-cloud-storage-and-dynamic-config.md) | [Índice Principal](../README.md)

> **Quando escolher esta abordagem?**  
> Use `driver: 'FlysystemService'` sempre que precisar conectar-se a um backend que não seja S3 — como um **servidor SFTP/FTP**, **Azure Blob Storage** ou **WebDAV** — ou reutilizar um filesystem do Flysystem já configurado no seu projeto via `league/flysystem-bundle`.

---

## 1. Conectando um Servidor SFTP (`league/flysystem-sftp-v3`)

### Passo 1: Instalar o Adaptador SFTP

```bash
composer require league/flysystem-sftp-v3
```

### Passo 2: Adicionar Variáveis de Conexão ao `.env.local`

```dotenv
# .env.local
SFTP_HOST=files.example.com
SFTP_PORT=22
SFTP_USER=deploy
SFTP_PASS=secret-password
SFTP_ROOT=/var/www/shared-files
```

### Passo 3: Definir o Serviço Symfony (`config/services.yaml`)

```yaml
# config/services.yaml
services:
    app.sftp.connection_provider:
        class: League\Flysystem\PhpseclibV3\SftpConnectionProvider
        arguments:
            $host: '%env(SFTP_HOST)%'
            $username: '%env(SFTP_USER)%'
            $password: '%env(SFTP_PASS)%'     # Deixe null e defina $privateKey se autenticar com chave SSH
            $privateKey: null                 # ex.: '%kernel.project_dir%/config/ssh/id_ed25519'
            $passphrase: null
            $port: '%env(int:SFTP_PORT)%'
            $useAgent: false
            $timeout: 10
            $maxTries: 3

    app.sftp.adapter:
        class: League\Flysystem\PhpseclibV3\SftpAdapter
        arguments:
            $connectionProvider: '@app.sftp.connection_provider'
            $root: '%env(SFTP_ROOT)%'

    app.sftp.storage:
        class: League\Flysystem\Filesystem
        # Nota: public: true NÃO é obrigatório! O FlysystemServiceLocatorPass registra este serviço em um locator seguro automaticamente.
        arguments:
            $adapter: '@app.sftp.adapter'
```

### Passo 4: Registrar o Volume no `liveux_file_manager.yaml`

```yaml
# config/packages/liveux_file_manager.yaml
liveux_file_manager:
    volumes:
        sftp_archive:
            label: 'Servidor de Arquivos SFTP'
            icon: 'sftp'              # Ícone SVG nativo para SFTP
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: null
            private: true             # Streaming seguro de arquivos via DownloadController + Voter RBAC
            cache_ttl: 30             # TTL de cache curto, pois o conteúdo SFTP pode mudar externamente
            driver_options:
                service: 'app.sftp.storage'

    profiles:
        default:
            volumes: ['sftp_archive']
```

> **Usando um `ConfigProviderInterface` dinâmico?** Se você retorna o ID do serviço dinamicamente a partir de um banco de dados em vez de declará-lo no YAML, basta marcar seu serviço Flysystem com `tags: ['liveux_file_manager.storage']` (ou `flysystem.storage`) — novamente, `public: true` não é necessário.

---

## 2. Reutilizando Serviços Existentes do `league/flysystem-bundle` (Azure Blob, WebDAV etc.)

Se seu projeto já utiliza `league/flysystem-bundle` (por exemplo, `azure.storage` ou `webdav.storage`), basta apontar `driver_options.service` para o ID do serviço existente:

```yaml
liveux_file_manager:
    volumes:
        azure_blobs:
            label: 'Azure Blob Storage'
            icon: 'cloud'
            group: 'network'
            driver: 'FlysystemService'
            url_prefix: 'https://myaccount.blob.core.windows.net/media'
            private: false
            cache_ttl: 120
            driver_options:
                service: 'azure.storage'  # Qualquer serviço que implemente League\Flysystem\FilesystemOperator
```

---

## 3. Pastas Excluídas Externamente e `remember_last_dir`

Suponha que um usuário estava trabalhando em `/projects/2026/draft` em um servidor SFTP ou bucket em nuvem, e alguém exclui essa pasta externamente por meio de um cliente FTP/SFTP:
- Quando o usuário recarregar a página, o gerenciador de arquivos não gera erro — ele retorna automaticamente ao diretório pai existente mais próximo (`/projects/2026` ou a raiz do volume).
- Clicar no botão **Atualizar (`↻`)** na barra de ferramentas sempre ignora o cache via `?refresh=1` para buscar uma listagem em tempo real diretamente do SFTP ou armazenamento em nuvem.

---

## 4. Verificar a Conectividade em Tempo Real

```bash
php bin/console file-manager:doctor --check-remote
```

---

[← Anterior: Google Cloud Storage (GCS)](google-cloud-storage.md) | [07 — Índice de Armazenamento em Nuvem](../07-cloud-storage-and-dynamic-config.md)
