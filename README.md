# Support SLA

Sistema web local de Help Desk / Service Desk com controle de chamados, permissões por perfil, isolamento por organização, calendário de atendimento e motor de SLA no backend.

> O ambiente desta entrega não possui PHP, Composer ou MySQL instalados para execução local. A estrutura foi preparada para o ServBay e inclui scripts de instalação, schema, seeders e testes. Os testes devem ser executados no Windows após instalar PHP 8.3+, Composer e MySQL pelo ServBay.

## Requisitos

- Windows 10/11.
- ServBay com PHP 8.3+ e MySQL 8.x.
- Composer 2.x.
- Node.js 20+ (opcional; a interface já possui assets prontos).
- Extensões PHP `pdo`, `pdo_mysql`, `json`, `mbstring`, `openssl` e `fileinfo`.

## Instalação no ServBay

1. Extraia o projeto em, por exemplo, `C:\ServBay\www\support-sla`.
2. No ServBay, crie um site com:
   - Domínio: `system.test`
   - Diretório raiz: `C:\ServBay\www\support-sla\public`
   - PHP: 8.3 ou superior
3. Com o MySQL e o PHP ativos no ServBay, dê duplo clique em:

```text
1-INSTALAR-E-ABRIR.bat
```

Esse arquivo cria automaticamente o banco `support_sla`, executa o schema, insere os dados de demonstração e abre `http://system.test`.

Se a tela de login informar erro de MySQL, execute primeiro `2-TESTAR-MYSQL.bat`. Ele mostra se o problema é porta, senha, banco ou schema ausente.

### Instalação manual, se necessário

Abra o terminal na pasta do projeto e execute:

```powershell
composer install
Copy-Item .env.example .env
```

Edite `.env` e informe a senha do MySQL local:

```dotenv
APP_NAME="Support SLA"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://system.test
APP_TIMEZONE=America/Sao_Paulo
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=support_sla
DB_USERNAME=root
DB_PASSWORD=ServBay.dev
```

Depois, instale o schema e os dados de demonstração:

```powershell
php scripts/install.php
```

Ou, usando o script Composer:

```powershell
composer run install-local
```

Abra `http://system.test` no navegador.

## Contas de demonstração

Todas usam a senha `Admin@12345`:

| Perfil | E-mail |
|---|---|
| Superadministrador | superadmin@supportsla.test |
| Administrador | admin@supportsla.test |
| Gestor | gestor@supportsla.test |
| Supervisor | supervisor@supportsla.test |
| Técnico | tecnico@supportsla.test |
| Cliente | cliente@supportsla.test |
| Auditor | auditor@supportsla.test |

## Execução alternativa

Para testar sem o domínio do ServBay, aponte o document root para `public` e use:

```powershell
php -S 127.0.0.1:8080 -t public public/index.php
```

Nesse caso, acesse `http://127.0.0.1:8080`.

## Filas e tarefas agendadas

O processamento de SLA é seguro para execução periódica. No ambiente local, execute:

```powershell
php scripts/sla-worker.php
```

Para agendamento no Windows, use o Agendador de Tarefas a cada minuto. O script atualiza ciclos, alertas e violações sem duplicar eventos.

## Testes

Com PHP e Composer disponíveis:

```powershell
composer test
```

Se o PHPUnit ainda não estiver disponível:

```powershell
composer install
vendor\bin\phpunit
```

## Estrutura

```text
app/
  Domain/Sla/SlaEngine.php
  Support/Auth.php
  Support/Database.php
  Support/Env.php
  Support/Response.php
database/schema.sql
public/index.php
public/index.html
public/assets/
scripts/install.php
scripts/sla-worker.php
tests/
```

## Segurança local

- O arquivo `.env` não deve ser versionado.
- Os anexos são armazenados em `storage/uploads` e não são expostos diretamente.
- Chamados aceitam até 5 anexos por envio, com limite de 10 MB por arquivo. São aceitos PDF, imagens, documentos, planilhas, TXT, CSV e ZIP.
- O download dos anexos passa pela API e respeita a mesma permissão do chamado.
- O backend aplica organização, perfil e permissões antes de consultar ou alterar chamados.
- Notas internas nunca são retornadas para usuários clientes.
- As contas acima são somente para demonstração.

### Matriz de acesso aos módulos

O acesso é verificado em duas camadas: o menu da interface oculta o módulo e a API bloqueia tentativas diretas com HTTP 403.

| Módulo | Perfis com visualização | Perfis com criação |
|---|---|---|
| Clientes | Superadministrador, administrador, gestor, supervisor, auditor | Superadministrador, administrador, gestor, supervisor |
| Contratos | Superadministrador, administrador, gestor, auditor | Superadministrador, administrador, gestor |
| Equipes | Superadministrador, administrador, gestor, supervisor, auditor | Superadministrador, administrador, gestor, supervisor |
| Políticas de SLA | Superadministrador, administrador, gestor, auditor | Superadministrador, administrador, gestor |
| Ativos | Superadministrador, administrador, gestor, supervisor, técnico, auditor | Superadministrador, administrador, gestor, supervisor, técnico |
| Relatórios | Superadministrador, administrador, gestor, supervisor, auditor | — |
| Automações | Superadministrador, administrador, gestor | Superadministrador, administrador, gestor |
| Auditoria | Superadministrador, administrador, gestor, auditor | — |

Clientes continuam com acesso apenas aos próprios chamados e artigos públicos da base de conhecimento.

## Módulos funcionais incluídos

Além da Central de chamados, a versão atual possui telas conectadas ao banco para clientes, contratos, equipes, políticas de SLA, ativos, automações, relatórios operacionais, auditoria e base de conhecimento. Os cadastros de clientes, contratos, equipes, políticas, ativos e automações podem ser criados pela interface conforme as permissões do perfil.
