# WebGit API para hospedagem Windows

Interface PHP para trabalhar com repositórios GitHub em hospedagens Windows compartilhadas nas quais o executável `git` não está disponível.

A ferramenta usa a API do GitHub para simular as operações principais de Git pelo navegador:

- conectar a repositórios públicos e privados;
- clonar ou atualizar arquivos em um workspace local;
- identificar arquivos novos, alterados e removidos;
- criar arquivos e pastas;
- editar e excluir arquivos;
- fazer upload de arquivos;
- enviar as alterações ao GitHub em um único commit;
- bloquear o push quando o repositório remoto mudou após o clone.

> A ferramenta não cria uma pasta `.git` e não copia o histórico completo, tags ou branches. As operações são equivalentes funcionais executados pela API do GitHub.

## Requisitos

- PHP 7.3 ou superior;
- extensões PHP `curl`, `openssl`, `json` e `zip`;
- sessões PHP funcionando;
- permissão de leitura no diretório da aplicação;
- permissão de escrita em `storage/` e `workspace/`;
- saída HTTPS para `api.github.com`;
- arquivo `certificates/cacert.pem` legível pelo PHP.

## Estrutura

```text
webgit/
├── index.php
├── app.php
├── config.php
├── diagnostico-git.php
├── web.config
├── .htaccess
├── certificates/
│   └── cacert.pem
├── storage/
│   ├── backups/
│   ├── locks/
│   ├── logs/
│   ├── state/
│   └── tmp/
└── workspace/
```

## Campos de conexão

### URL HTTPS

URL do repositório no GitHub.

Exemplo público:

```text
https://github.com/PHPMailer/PHPMailer.git
```

Exemplo privado:

```text
https://github.com/usuario/meu-repositorio
```

### Branch

Branch usada nas operações.

Exemplos:

```text
main
master
develop
```

### Usuário

Campo opcional. Na implementação atual, a autenticação efetiva no GitHub é realizada pelo token.

### Senha

Campo opcional e mantido apenas por compatibilidade. Para GitHub, use preferencialmente um Personal Access Token.

### Token

Token usado para autenticar a API do GitHub. O token permanece somente na sessão PHP e não é gravado no `config.php`.

Permissões recomendadas:

- leitura ou clone: `Contents: Read-only`;
- push: `Contents: Read and write`;
- alterações em `.github/workflows/`: `Workflows: Read and write`.

Use tokens limitados somente aos repositórios necessários e nunca publique tokens no código ou neste repositório.

## Operações da interface

### Conectar

Valida o acesso ao repositório informado e mantém os dados de autenticação na sessão PHP.

### Remoto

Mostra os arquivos e diretórios existentes na branch remota.

### Clonar/atualizar workspace

Faz o fluxo:

```text
GitHub -> workspace local
```

O botão baixa os arquivos remotos. O botão não envia alterações locais.

Exemplo de destino local:

```text
workspace/usuario/repositorio/
```

### Workspace/status

Varre recursivamente o workspace e compara os arquivos com o estado salvo após o clone ou último push.

Status possíveis:

- `IGUAL`: arquivo local igual ao estado-base;
- `NOVO`: arquivo existe localmente, mas não existia no estado-base;
- `ALTERADO`: conteúdo local foi modificado;
- `REMOVIDO`: arquivo existia no estado-base e foi excluído localmente.

Arquivos copiados via FTP ou SSH também aparecem no status.

### Novo arquivo

Cria um arquivo dentro do workspace.

Exemplo:

```text
docs/exemplo.txt
```

### Nova pasta

Cria uma pasta com um arquivo `.gitkeep`, porque Git não versiona diretórios vazios.

Exemplo:

```text
storage/cache
```

### Upload

Envia um arquivo do navegador para o workspace. O diretório de destino é opcional.

Exemplo:

```text
Diretório: assets/images
Arquivo: logo.png
```

### Editar

Edita um arquivo de texto existente no workspace. Antes de salvar, a ferramenta cria uma cópia na área de backups.

### Excluir

Remove o arquivo localmente. A exclusão no GitHub somente ocorre depois do push.

### Push das alterações

Envia arquivos novos, alterados e removidos em um único commit.

Antes do envio, a ferramenta confirma que a branch remota continua no mesmo commit registrado durante o clone. Se o remoto mudou, o push é bloqueado para evitar sobrescrita.

Fluxo recomendado:

```text
Conectar
-> Clonar/atualizar workspace
-> Alterar pela interface, FTP ou SSH
-> Workspace/status
-> Revisar NOVO, ALTERADO e REMOVIDO
-> Informar mensagem do commit
-> Push das alterações
```

## Uso com FTP ou SSH

Para adicionar uma pasta completa, copie-a para o workspace correto:

```text
workspace/usuario/repositorio/nova-pasta/
```

Depois:

1. abra `Workspace/status`;
2. confira os arquivos com status `NOVO`;
3. informe a mensagem do commit;
4. clique em `Push das alterações`.

Para remover arquivos via FTP ou SSH:

1. exclua os arquivos no workspace;
2. abra `Workspace/status`;
3. confira o status `REMOVIDO`;
4. execute o push.

## Diagnóstico

O arquivo `diagnostico-git.php` verifica:

- versão e SAPI do PHP;
- extensões necessárias;
- funções disponíveis;
- permissões reais de criação e remoção de arquivos;
- resolução DNS;
- conectividade HTTP e HTTPS;
- configuração do pacote de certificados CA;
- presença eventual do executável Git.

Acesse temporariamente:

```text
https://seu-dominio/caminho/webgit/diagnostico-git.php
```

O diagnóstico revela caminhos e informações do ambiente. Restrinja o acesso e remova ou bloqueie o arquivo após o uso.

## Limpeza de um workspace

Para remover apenas uma cópia local e o estado correspondente:

```bash
rm -rf webgit/workspace/USUARIO/REPOSITORIO
rm -f webgit/storage/state/USUARIO--REPOSITORIO--BRANCH.json
```

Exemplo:

```bash
rm -rf webgit/workspace/maiconibb100/phpmailer-privado
rm -f webgit/storage/state/maiconibb100--phpmailer-privado--main.json
```

Isso não exclui o repositório no GitHub.

## Segurança

- publique a interface somente por HTTPS;
- proteja `index.php` com autenticação adicional ou restrição de IP;
- não grave tokens no código;
- não envie `storage/`, `workspace/`, backups ou sessões ao repositório;
- bloqueie acesso HTTP direto a `config.php`, `app.php`, `certificates/`, `storage/` e `workspace/`;
- conceda permissões mínimas ao token;
- revise o status antes do push;
- remova ou restrinja `diagnostico-git.php` após os testes.

## Limitações

- funciona atualmente com GitHub;
- não mantém `.git` local;
- não preserva histórico completo ao copiar um workspace para outro repositório;
- não executa merge ou rebase;
- não resolve conflitos automaticamente;
- não oferece suporte completo a Git LFS e submodules;
- diretórios vazios precisam de `.gitkeep`.

## Aviso

Use inicialmente em repositórios de teste. Faça backup antes de habilitar a ferramenta em conteúdo de produção.

