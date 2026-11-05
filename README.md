# FixIt — Assistência Técnica

Sistema de gestão para uma oficina de reparação de computadores e telemóveis.
O equipamento entra no balcão, recebe um talão com um **PIN de 6 dígitos** e o
cliente acompanha a reparação — recebido, em diagnóstico, em reparação, pronto,
entregue — sem telefonar. A oficina registra tudo no back office: pedidos,
orçamentos, técnicos, clientes, equipamentos e o histórico de cada mudança.

Duas superfícies, uma base de dados:

- **Site público** (`frontoffice/`) — apresentação da oficina, pedido de
  orçamento, consulta do estado por PIN e decisão do orçamento pelo cliente.
- **Área da oficina** (`backoffice/`) — login, painel, lista de pedidos, ficha
  do pedido, caixa de entrada de pedidos do site, clientes, equipamentos,
  utilizadores e conta.

---

## Requisitos

| O quê | Versão / nota |
| --- | --- |
| PHP | 8.1 ou superior (o código usa `declare(strict_types=1)`, tipos de retorno e `match`) |
| Extensões PHP | `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `exif` (as três últimas para as fotografias) |
| Base de dados | MySQL 8.0.16+ ou MariaDB 10.4+ (as `CHECK` só são aplicadas a partir destas versões) |
| Servidor web | Apache, Nginx ou o servidor embutido do PHP |

Não há Composer, `npm`, framework, etapa de build nem dependência de CDN no
código do site. O único recurso externo é o ficheiro do gráfico do painel,
descrito em [Limitações](#limitações-conhecidas).

---

## Instalação

### 1. Base de dados

Importar **por esta ordem** (a ordem importa: a segunda migração altera a
tabela que a primeira cria):

```bash
mysql -u root -p < sql/fixit.sql                    # esquema, tabelas e triggers
mysql -u root -p fixit < sql/migracao-solicitacoes.sql
mysql -u root -p fixit < sql/migracao-solicitacoes-fluxo.sql
mysql -u root -p < sql/utilizadores-iniciais.sql    # contas de acesso
```

> **Atenção:** `sql/fixit.sql` começa por apagar as tabelas existentes
> (`DROP TABLE`). Serve para instalar de novo ou para repor a demonstração;
> nunca deve ser executado sobre dados reais.

Os restantes ficheiros de `sql/` são migrações incrementais para bases já
existentes (`migracao-sessoes.sql`, `migracao-avisos-pin.sql`,
`migracao-consultas-publicas.sql`, `migracao-decisoes-orcamento.sql`,
`migracao-historico-orcamentos.sql`, `migracao-historico-pins.sql`,
`migracao-historico-tecnicos.sql`). São idempotentes: podem ser importadas
mais do que uma vez. Numa instalação nova já estão todas incluídas no
`fixit.sql`.

### 2. Ficheiros

Copiar o projecto para a pasta servida pelo servidor web (em Laragon ou XAMPP,
por exemplo `htdocs/fixit/`) e garantir que `uploads/` tem permissão de escrita.

### 3. Executar

Com Apache/Nginx, abrir a pasta do projecto no browser. Sem servidor
instalado, o servidor embutido do PHP serve:

```bash
php -S 127.0.0.1:8141 -t .
```

Depois:

- site público — <http://127.0.0.1:8141/>
- consulta por PIN — <http://127.0.0.1:8141/frontoffice/consulta.php>
- área da oficina — <http://127.0.0.1:8141/backoffice/login.php>

A raiz (`index.php`) redireciona para o site público.

### Contas de demonstração

| Email | Palavra-passe | Cargo | Pode |
| --- | --- | --- | --- |
| `martim@fixit.pt` | `FixIt!2026` | Administrador | tudo, incluindo Utilizadores |
| `ana@fixit.pt` | `Oficina!2026` | Técnico | pedidos, clientes, equipamentos; **não** vê Utilizadores |
| `rui@fixit.pt` | `Oficina!2026` | Técnico | idem |

As palavras-passe são guardadas com `password_hash()` (bcrypt) e confirmadas
com `password_verify()`. Antes de usar a sério, mudá-las em **Conta**.

---

## O que a aplicação faz

**Site público**

- Apresentação da oficina, do percurso de uma reparação e do que se repara.
- Pedido de orçamento com validação campo a campo, limite de 5 envios por
  endereço a cada 10 minutos e campo-armadilha para robôs.
- Consulta do estado por PIN: percurso com datas, orçamento e histórico.
- O cliente aceita ou recusa o orçamento pela própria consulta — aceitar passa
  o pedido a *Em Reparação*, recusar passa-o a *Pronto* sem ter sido mexido.

**Área da oficina**

- **Painel** — contadores por estado, gráfico de anéis, fila de trabalho,
  avisos e atalhos.
- **Pedidos** — lista com pesquisa (nome, NIF, PIN, número de série), filtros
  por estado e técnico, ordenações, atribuição de técnico e mudança de estado.
- **Ficha do pedido** — dados do cliente e do equipamento, fotografias,
  orçamento com histórico de valores, histórico de estados, renovação do PIN,
  contagem de consultas públicas.
- **Caixa de entrada** — pedidos de orçamento vindos do site, com conversão em
  pedido de reparação (transação com três defesas contra conversão duplicada).
- **Clientes** e **Equipamentos** — listagens com pesquisa, fichas com os
  pedidos de cada um.
- **Utilizadores** — só para administradores: criar contas, mudar cargo, ativar
  e desativar.
- **Conta** — dados próprios, mudança de palavra-passe e terminação das outras
  sessões.

---

## Estrutura do projecto

```
index.php              redirecciona para o site público
robots.txt
assets/css/            painel.css, front.css, formulario.css, login.css
backoffice/            área reservada (11 páginas)
frontoffice/           site público (12 ficheiros, incluindo front-comum.php)
nucleo/                código partilhado: conexão, sessão, texto, estados, solicitações
sql/                   esquema, migrações e contas iniciais
testes/correr.php      31 verificações automáticas
screenshots/           36 capturas de ecrã
uploads/               fotografias dos equipamentos (fora do repositório)
DESIGN.md              sistema visual: cor, tipografia, espaço, componentes
PRODUCT.md             produto, utilizadores, princípios
PROMPT-PROJETO.md      diário técnico: decisões, ambiente, verificado / não verificado
MANUAL.md              manual de utilização
RELATORIO.md           relatório do projecto
APRESENTACAO.md        guião da apresentação, diapositivo a diapositivo
```

Cada página do back office começa por carregar `nucleo/sessao.php` e
`nucleo/layout-painel.php`, e desenha-se com `painelAbrir()` — cabeçalho,
navegação, contadores e topo vivem num só sítio, para não haver sete cópias a
divergir.

---

## Base de dados

13 tabelas, InnoDB, `utf8mb4_unicode_ci`.

| Tabela | Para quê | Relações principais |
| --- | --- | --- |
| `utilizadores` | contas da oficina (Administrador / Técnico) | — |
| `clientes` | clientes da oficina (NIF único, 9 dígitos) | — |
| `equipamentos` | equipamento de um cliente | `cliente_id → clientes` (CASCADE) |
| `pedidos` | ordens de serviço, estado atual, orçamento, PIN | `equipamento_id → equipamentos` (CASCADE), `tecnico_id` e `registado_por → utilizadores` (SET NULL) |
| `historico_pedidos` | uma linha por mudança de estado | `pedido_id → pedidos` (CASCADE) |
| `historico_orcamentos` | cada valor de orçamento, com anterior e novo | `pedido_id → pedidos`, `tecnico_id → utilizadores` |
| `historico_pins` | quem renovou o PIN e quando (o PIN não é guardado) | `pedido_id → pedidos` |
| `historico_tecnicos` | atribuições e trocas de técnico | `pedido_id → pedidos`, três referências a `utilizadores` |
| `consultas_publicas` | uma linha por consulta por PIN (sem IP nem nome) | `pedido_id → pedidos` |
| `avisos_pin` | aviso de PIN sem consultas há mais de uma semana | `pedido_id → pedidos`, único por (pedido, dia) |
| `decisoes_orcamento` | aceitação ou recusa do orçamento pelo cliente | `pedido_id → pedidos` |
| `fotos_equipamento` | caminho das fotografias de um pedido | `pedido_id → pedidos` |
| `solicitacoes` | pedidos de orçamento do site | `pedido_id → pedidos` (SET NULL, único) |

Regras garantidas pela própria base, não só pelo PHP: NIF e PIN validados por
`CHECK` com expressão regular, orçamento nunca negativo, `data_conclusao` nunca
anterior a `data_entrada`, um pedido nunca é origem de duas solicitações, e dois
**triggers** registam automaticamente o estado inicial e cada mudança de estado
— seja qual for o caminho que a faça.

---

## Testes

Verificações puras (sem base de dados nem servidor):

```bash
php testes/correr.php
```

31 verificações: cortes de texto em bytes e em caracteres, formatação de
estados, normalização de contactos, validação de NIF e de PIN, cálculo de
percentagens e de escalões de entrega, e a lista de estados que podem ser
escolhidos à mão (que exclui «Convertida», porque esse só a conversão o atribui).

A bateria HTTP (login, permissões, cabeçalhos, páginas a 200, conversão) está em
`.localdb/testes-http.sh` e só existe no ambiente de desenvolvimento local — não
faz parte do repositório.

---

## Segurança

- Sessões próprias com `HttpOnly` e `SameSite=Lax`, regeneração do ID no login e
  versão de sessão por conta, para terminar sessões antigas.
- Token CSRF em todos os formulários que escrevem, comparado com
  `hash_equals()`.
- Todas as consultas usam PDO com parâmetros; não há uma única concatenação de
  valores em SQL.
- Saída sempre escapada (`htmlspecialchars` com `ENT_QUOTES`).
- Cabeçalhos `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Cache-Control` e `Content-Security-Policy` em todas as páginas.
- Permissões verificadas no servidor: o técnico não chega a
  `utilizadores.php` (303), mesmo escrevendo o URL à mão.
- Limite de pedidos por endereço no formulário público.

---

## Limitações conhecidas

- **Clientes e equipamentos não se editam nem se apagam.** Registam-se pela
  ficha de pedido novo, listam-se e pesquisam-se, mas não há ecrã de alteração.
- **O gráfico do painel carrega o Chart.js de um CDN** (`cdn.jsdelivr.net`,
  versão fixa e presa por `integrity`). É o único recurso externo; sem rede, o
  gráfico não desenha e a legenda com os totais mantém-se.
- **Não há envio de email.** O pedido de orçamento é lido na caixa de entrada
  da oficina; o cliente é contactado pelo telefone ou email que deixou.
- **A impressão do talão e a decisão do orçamento pelo cliente** continuam por
  testar com dados reais. O upload de fotografias foi testado e a ficha passou
  a mostrar a galeria, mas o limite de cinco ficheiros ainda não.
- **Sem paginação** nas listagens: foram pensadas para o volume de uma oficina.
- Casos-limite não testados: conversões simultâneas do mesmo pedido do site e
  texto com codificação inválida enviado por `POST` forjado.

---

## Documentação

- `MANUAL.md` — manual de utilização, tarefa a tarefa.
- `RELATORIO.md` — relatório do projecto: pesquisa, decisões, base de dados,
  testes e uso de IA.
- `DESIGN.md` — sistema visual.
- `PRODUCT.md` — produto e princípios.
- `PROMPT-PROJETO.md` — diário técnico com o que está verificado e o que não está.
- `screenshots/` — capturas de ecrã das várias revisões e tamanhos de ecrã.
