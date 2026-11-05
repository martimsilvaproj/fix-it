# PROMPT — FixIt: estado do projecto e próximo passo

Documento de handoff. Serve para colar num agente ou passar a um programador.
Descreve o que existe, as regras que não se quebram, o que está verificado e o
que falta. Data: Novembro de 2026.

---

## 1. O que é o FixIt

Sistema de uma oficina de **informática e telemóveis**, em duas metades:

- **Site público** (`frontoffice/`) — explica a oferta e deixa o cliente
  acompanhar a reparação com um **PIN de 6 dígitos**, sem conta e sem telefone.
- **Back office** (`backoffice/`) — software interno, atrás de login, onde a
  oficina regista clientes, equipamentos, pedidos, orçamentos e decisões.

O produto é o percurso: cinco fases (`Recebido` → `Em diagnóstico` →
`Em reparação` → `Pronto` → `Entregue`), um talão com PIN, e um orçamento que o
cliente aceita ou recusa. Aceitar leva o pedido a **Em reparação**; recusar
leva-o a **Pronto**, para levantar sem ser mexido.

---

## 2. Stack e restrições (não negociáveis)

- **PHP 8 puro**, PDO + MySQL. Sem Composer, sem npm, sem framework, sem build.
- **Sem CDN**, sem Google Fonts, sem imagens de terceiros. O JavaScript é
  inline, por página, e cada página funciona sem ele.
- Uma folha de estilos por superfície; nada de preprocessadores.
- Texto em **pt-PT**, segunda pessoa, frases curtas, zero superlativos.
- **Nunca inventar**: preços, prazos, garantias, moradas, telefones, avaliações,
  fotografias. Os contactos da oficina estão a `null` e os blocos desaparecem em
  vez de ficarem vazios.
- **Segurança**: prepared statements em tudo, CSRF em tudo o que muda estado,
  limite de tentativas por sessão **e** por endereço de rede, mensagens que não
  revelam se uma conta ou um pedido existe.

---

## 3. Estrutura real

```
index.php            redirecciona (302) para frontoffice/index.php
robots.txt
DESIGN.md            sistema visual
PRODUCT.md           ficha do produto
PROMPT-FRONT-OFFICE.md
assets/css/          formulario 835 · front 1356 · login 151 · painel 4622 · publico 840
frontoffice/         404, como-funciona, consulta, contacto, dados-oficina,
                     front-comum, index, orcamento, perguntas, privacidade,
                     servicos, sitemap
backoffice/          clientes, conta, equipamentos, login, logout, painel,
                     pedido-novo, pedido, pedidos, solicitacoes, utilizadores
nucleo/              conexao, estado-pedido, pin-consulta, sessao, texto
sql/                 fixit.sql + 9 migrações + utilizadores-iniciais
uploads/             fotografias dos pedidos
screenshots/         material de revisão
```

---

## 4. Site público, página a página

- **`index.php`** — a entrada: promessa, **campo do PIN na primeira dobra**
  (formulário que publica em `consulta.php`), as cinco fases numeradas `01`–`05`,
  a oferta, o bloco para empresas e o fecho. A régua do percurso anima com o
  scroll e fica estática com movimento reduzido.
- **`servicos.php`** — **abas por tipo de equipamento** (portáteis, desktops,
  telemóveis, tablets) com desenhos de linha em SVG escritos no documento,
  navegação por setas/`Home`/`End`, âncoras (`#telemoveis`) e *fallback* sem
  JavaScript: os quatro painéis ficam empilhados e legíveis.
- **`como-funciona.php`** — as cinco fases explicadas, o talão e o PIN, e o que
  acontece quando o orçamento não compensa.
- **`perguntas.php`** — 11 perguntas em `<details>`, com **filtro** no browser
  (ignora acentos, anuncia a contagem, esconde-se sem JavaScript).
- **`contacto.php`**, **`privacidade.php`** (inclui o registo da decisão do
  orçamento), **`404.php`**, **`sitemap.php`**.
- **`orcamento.php`** — pedido de orçamento: CSRF de sessão `fixit_site`, isca
  anti-robô, limite de 5 por 10 minutos por IP, validação no servidor e
  `INSERT` em `solicitacoes`. Depois de enviar mostra uma **referência**
  (`ORC-2026-0042`), guardada na sessão.
- **`consulta.php`** — o coração:
  - PIN de 6 dígitos, 8 tentativas por sessão e 20 por IP a cada 10 minutos;
  - resultado guardado 30 minutos na sessão, nunca no URL;
  - percurso com datas por fase (histórico), **«A seguir»** por estado;
  - **decisão do orçamento**: caixa com o valor e dois botões (aceitar /
    recusar), CSRF próprio, estado relido com `FOR UPDATE` dentro da
    transacção, decisão gravada em `decisoes_orcamento` com o valor congelado;
  - **impressão**: `@media print` esconde interface e imprime o estado com
    cabeçalho de talão; fases e fichas não se cortam entre folhas;
  - não mostra o nome do cliente.

---

## 5. Back office

`login` (CSRF, 6 tentativas, `password_verify` sempre — mesmo sem conta —,
rehash automático), `painel` (métricas, gráfico por estado, pedidos em aberto,
trocas de técnico), `pedidos` (lista, filtros, ordenação, atribuição de técnico
na própria linha), `pedido` (ficha: estado, orçamento, PIN, histórico,
fotografias), `pedido-novo` (registo com cliente novo ou existente, upload de
fotografias, geração do PIN), `clientes`, `equipamentos`, `utilizadores`,
`conta`, `logout`.

**`solicitacoes.php`** é a caixa de entrada dos pedidos vindos do site: entra na
navegação como «Pedidos do site», com contador dos que estão por contactar; tem
pesquisa, filtros por estado, ficha de cada pedido, marcação de estado e a
**conversão em pedido de reparação** (cliente novo ou existente, equipamento,
PIN novo, tudo na mesma transacção e com protecção contra conversão duplicada).

---

## 6. Design: o talão azul, nas duas metades

Paleta da casa: `#1A5FA0` (carimbo), `#101C28` (tinta), `#F3F6F9` (papel),
`#37475A` / `#5A6C80` (texto), `#E4E9F0` (regra).

Gramática: **cantos vivos**, **regras de 2px**, **etiquetas mono maiúsculas**,
numeração `01`–`05`, **sombra de carimbo** (`6px 6px 0` em tinta). Sem
fotografia; os desenhos são SVG dentro do documento.

O back office veste a mesma gramática desde os blocos 15, 16 e 17 de
`painel.css`: barra lateral de tinta, tabelas com linha alternada e algarismos
tabulares, ficha com lombada e número em mono, PIN em mono espaçado, pílulas de
estado fechadas.

### A cascata é a parte que se erra

Ordem de carregamento: `painel.css` **primeiro**; `formulario.css` e
`login.css` **depois**; no site, `front.css` depois de `painel.css`; na
consulta, `publico.css` por último.

- Regras de **campo** e do **botão de entrada** vivem em `formulario.css` e
  `login.css` — em `painel.css` perderiam por ordem.
- O site protege-se com `.site-body`; a consulta com `.pagina-publica`.
- `painel.css` nunca é reescrito: acrescenta-se no fim, por blocos numerados.

---

## 7. Base de dados

`utilizadores`, `clientes`, `equipamentos`, `pedidos` (com `pin_consulta`,
`orcamento`, `estado`), `historico_pedidos` (alimentado por *triggers*),
`historico_orcamentos`, `historico_pins`, `historico_tecnicos`,
`fotos_equipamento`, `consultas_publicas`, `avisos_pin`, `solicitacoes`,
`decisoes_orcamento`.

**Migrações que podem faltar na base instalada:** `migracao-solicitacoes.sql`,
`migracao-solicitacoes-fluxo.sql` (colunas `pedido_id`/`convertida_em` e o índice
único que impede duas conversões), `migracao-decisoes-orcamento.sql` e
`migracao-sessoes.sql`. Todas são idempotentes e estão também em `fixit.sql` para
uma instalação de raiz. O código tolera a ausência das que acrescentam colunas
(mostra menos, não rebenta), mas sem elas não há decisão do orçamento nem caixa
de entrada.

**Ordem de importação** — a única que funciona, porque umas dependem das outras:

```
fixit.sql  →  utilizadores-iniciais.sql  →  migracao-solicitacoes.sql
           →  migracao-solicitacoes-fluxo.sql  →  todas as outras
```

A `fluxo` altera a tabela que a `solicitacoes` cria. `migracao-sessoes.sql` e
`migracao-decisoes-orcamento.sql` não tinham `USE `fixit`;` e, importadas de uma
consola sem base seleccionada, falhavam com «No database selected» — corrigido
nesta etapa, tal como a fluxo, que também foi corrida duas vezes para provar que
é idempotente.

---

## 8. Ambiente desta máquina

- PHP 8.4.27 em `C:\Users\Martim Silva\AppData\Local\Temp\php-win\php.exe`
  (o caminho tem espaço: `%20` **falha**, usar aspas).
- **As extensões existem, mas não carregavam**: o PHP não carrega nenhum
  `php.ini`, por isso `pdo_mysql`, `mbstring`, `fileinfo`, `gd` e `exif` estavam
  em `…\php-win\ext` sem estarem activas. `.localdb/php-dev.ini` activa-as e é
  passado com `php -c`. Não é código da aplicação e não deve ser usado em
  produção — num servidor normal o `php.ini` já as traz.
- **MariaDB 11.4.5 portátil em `.localdb/`** (não versionado, só ferramenta de
desenvolvimento): dados em `.localdb/data`, porto 3306, `root` sem palavra-passe
  — os valores por omissão de `nucleo/conexao.php`. Arrancar e parar está em
  `.localdb/LEIA-ME.md`; o servidor web é
  `php -c .localdb/php-dev.ini -S 127.0.0.1:8141 -t .`.
- **Cuidado com servidores duplicados**: dois `php -S` no mesmo porto ficam os
  dois à escuta e o pedido cai no antigo (que pode não ter as extensões). Antes
  de diagnosticar, confirmar com `netstat -ano | grep 8141` que só há um PID.
- O painel de preview tem ~431px: capturas acima disso saem cortadas; usar
  `resize {fill:true}` e, para ecrã largo, medir estilos calculados.

---

## 9. O que está verificado e o que não está

**Verificado com a base a correr, nesta máquina:**

- `php -l` em `backoffice/*.php`, `nucleo/*.php`, `frontoffice/*.php`, `index.php`
  e `testes/correr.php` — sem erros.
- `php testes/correr.php` → **31 verificações, saída 0**, tanto com `mbstring`
  como sem ela (o teste passou a cobrir as duas implementações do corte).
- Circuito completo no browser: pedido em `frontoffice/orcamento.php` →
  referência **ORC-2026-0004**; caixa de entrada com contagem («1 por
  contactar»), filtros por estado e pesquisa; ficha da solicitação com o
  preenchimento automático a partir do que o cliente escreveu; conversão com
  NIF → `pedido.php?id=1`.
- Confirmado na base: `solicitacoes` passou a `Convertida` com `pedido_id=1` e
  `convertida_em`; nasceram `clientes`, `equipamentos` e `pedidos` (PIN 942822,
  estado «Recebido», `registado_por` = conta que converteu); `historico_pedidos`
  registou «Recebido» e `historico_pins` guardou o PIN.
- Login com conta real aceite (POST 303 → painel); painel, pedidos do site e
  ficha do pedido a renderizar.

**Não verificado:** impressão do talão, envio de fotografias, decisão do
orçamento pelo cliente, percurso até «Entregue», e duas conversões em paralelo
(as três defesas estão no código e o índice UNIQUE foi criado, mas não houve um
teste com dois pedidos simultâneos).

---

## 10. O que melhorar, por prioridade

1. ~~Ligar a base e correr o circuito completo.~~ **Feito em parte**: MariaDB
   portátil em `.localdb/` e o circuito pedido no site → caixa de entrada →
   pedido de reparação verificado ponta a ponta. Falta o resto do percurso:
   diagnóstico → decisão pelo PIN → estado a mudar → entrega.
2. ~~Dar entrada à caixa de entrada na navegação, com contagem.~~ **Feito**: item
   «Pedidos do site» com contador em todas as páginas do painel.
3. **Fotografia no pedido de orçamento** — a oficina já fotografa o estado na
   recepção; o cliente poder enviar a avaria de casa evita uma deslocação.
   Exige validação de tipo e tamanho e ficheiros fora do alcance directo.
4. **Fila de espera no painel** — pedidos à espera da decisão do cliente, com o
   valor e há quanto tempo esperam.
5. ~~Testes — não existe um único.~~ **Feito no núcleo**: 31 verificações em
   `testes/correr.php`, sem base de dados. Falta cobrir `pin-consulta.php` e
   `estado-pedido.php`.
6. **Acessibilidade** — revisão de contraste e teclado, sobretudo nas pílulas de
   estado e nas tabelas, depois das mudanças de forma.
7. **Cabeçalhos e cookies** — **feito**: as nove páginas da oficina enviam
   `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`,
   `Cache-Control: private, no-store` e **CSP**, a partir de uma só função
   (`Sessao::cabecalhosSeguranca()`); o site público e a consulta têm a mesma
   CSP em `pagina_inicio()` e em `consulta.php`. Falta confirmar o `Secure` do
   cookie em HTTPS e, para apertar mais, trocar o `'unsafe-inline'` por nonces
   por pedido.
8. **O gráfico do painel vem de CDN — confirmado.** `painel.php` carrega
   Chart.js 4.4.4 de `cdn.jsdelivr.net`, contra a regra «sem CDN». Está fixo numa
   versão e preso por `integrity="sha384-…"`, e a CSP autoriza esse domínio por
   isso mesmo. É a única dependência externa do projecto e fica como **decisão
   pendente**: remover o gráfico (a legenda e o número do centro já dizem tudo),
   guardar o ficheiro em `assets/js/` (continua a ser uma biblioteca) ou manter
   como está.
9. ~~Runbook~~ **Feito**: `.localdb/LEIA-ME.md` (arrancar/parar a base e o
   servidor) e a secção 8 acima, com a ordem de importação e as variáveis
   `FIXIT_DB_*` documentadas em `nucleo/conexao.php`.

---

## 11. Regras para quem continua

- **A moldura do painel vive em `nucleo/layout-painel.php`.** Numa página nova:
  `require_once` do layout, `painelAbrir(...)` e só depois o `<main>`. Nunca
  repetir `<head>`, sprite, barra lateral ou barra de topo numa página — foi
  essa repetição que fez o item «Definições» apontar para um ficheiro
  inexistente em sete sítios ao mesmo tempo.
- Duas páginas têm layout focado, de propósito, e não usam o include:
  `solicitacoes.php` e `pedido-novo.php`.
- Não chamar `mb_*` directamente. Usar `fixitTamanho()` / `fixitCortar()`.
- Regras novas: painel → fim de `painel.css`; formulário → `formulario.css`;
  entrada → `login.css`; site → `front.css` com `.site-body`; consulta →
  `publico.css` com `.pagina-publica`.
- Verificar sempre com `php -l`, `curl` (200 e zero avisos) e
  `impeccable detect` a devolver `[]`. Quando não há captura possível, medir
  estilos calculados em vez de afirmar que está bem.
- Migrações: pedir autorização, nunca apagar dados, manter `fixit.sql` em sincronia.
- **Não dizer que algo está testado quando a base não correu.** Dizer o que foi
  verificado e o que ficou por verificar, com o motivo.

---

## 12. Prompt curto para arrancar

> Estás no projecto FixIt (PHP 8 puro, PDO/MySQL, sem framework nem build; site
> público em `frontoffice/`, back office privado em `backoffice/`, núcleo em
> `nucleo/`). Lê `PROMPT-PROJETO.md` e `DESIGN.md` antes de tocar em nada.
> A estética é o «talão azul»: cantos vivos, regras de 2px, etiquetas mono
> maiúsculas, sombra de carimbo. A cascata manda: `painel.css` carrega primeiro,
> `formulario.css` e `login.css` fecham, e o site/consulta protegem-se com
> `.site-body` e `.pagina-publica`. Não uses `mb_*` sem `fixitTamanho()` /
> `fixitCortar()`, não inventes factos (preços, prazos, contactos, imagens) e não
> digas que algo está testado se o MySQL não correu. Verifica com `php -l`,
> `curl` e `impeccable detect`. O ambiente local — MariaDB portátil em
> `.localdb/`, `php-dev.ini` com as extensões e o servidor em
> `php -c .localdb/php-dev.ini -S 127.0.0.1:8141 -t .` — está na secção 8 e em
> `.localdb/LEIA-ME.md`.

---

## 13. Etapa concluída: caixa de entrada ligada ao painel

O que passou a existir (código escrito, revisto e **verificado com a base a
correr**, ao contrário do que se supunha quando a etapa começou):

- **Navegação**: «Pedidos do site» em todas as páginas do painel, com contador
dos que estão por contactar. Saiu o item «Definições», que apontava para
`definicoes.php` — um ficheiro que nunca existiu.
- **`backoffice/solicitacoes.php`** reescrita: pesquisa por nome, contacto,
equipamento ou avaria; filtros por estado com contagens; ficha de cada pedido;
marcação de estado; e **abertura do pedido de reparação** a partir da ficha.
- **`nucleo/solicitacoes.php`**: contagem para o menu, leitura de contactos
(«912 345 678», «+351…», email) e a conversão — cliente novo ou existente,
equipamento, PIN novo e pedido em «Recebido», tudo numa transacção.
- **`sql/migracao-solicitacoes-fluxo.sql`**: `pedido_id`, `convertida_em`,
índice UNIQUE e chave estrangeira. Idempotente e **importada**; coluna e índice
confirmados em `information_schema`.
- **`testes/correr.php`**: 31 verificações sem base de dados (`php
testes/correr.php`), sobre texto, contactos e estados — a passar **com e sem**
`mbstring`.

### Duplicados e concorrência

Três barreiras, da mais fraca para a mais forte: `SELECT … FOR UPDATE` sobre a
solicitação; `UPDATE … WHERE pedido_id IS NULL` com verificação de
`rowCount()`; e o índice UNIQUE em `pedido_id`. Dois técnicos a carregar em
«Abrir pedido» ao mesmo tempo resultam num pedido, não em dois.

### Etapas seguintes, ainda por fazer

Cada uma acrescenta tabelas novas. Ficaram por fazer nesta ronda, mas já não
estão bloqueadas: a base corre nesta máquina, portanto o caminho de verificação
existe. Ordem sugerida:

1. **Pesquisa global** e indicadores novos no painel (orçamentos à espera de
decisão, solicitações pendentes, avisos).
2. **Stock e fornecedores** — peças, movimentos, stock mínimo, associação às
reparações, preços de aquisição.
3. **Agenda** e **notificações internas**.
4. **Relatórios** com exportação CSV e PDF.

A ordem deixou de estar bloqueada: a moldura do painel já está extraída para
`nucleo/layout-painel.php` (ver a nota abaixo), portanto a pesquisa global e as
notificações passam a ser **uma edição, não sete**.

### Moldura partilhada (feita)

As sete páginas do painel (painel, pedidos, pedido, clientes, equipamentos,
utilizadores, conta) deixaram de repetir o `<head>`, o sprite de ícones, a barra
lateral e a barra de topo. Cada uma tem agora, no topo, o `require_once` do
layout e uma chamada:

```php
painelAbrir('pedidos', $tituloRecorte, 'Descrição…', [
    ['Painel', 'painel.php'],
    [$tituloRecorte, null],
], $erroDados, false, $pedidosAtivos);
```

O que se ganhou, além das ~1 450 linhas que desapareceram: um único sprite com
todos os ícones (deixou de haver risco de uma página usar `#icone-x` que só
existe noutra), a navegação num só sítio, e o contador de pedidos em aberto
consistente em todas as páginas — antes só aparecia em três.

Como foi verificado: HTML das sete páginas capturado antes e depois, texto
extraído e comparado. As únicas diferenças são o comentário da tipografia (que
passou a existir em todas) e o contador que faltava. Zero avisos PHP em todas.
`painel.php` foi reescrito de 2 176 para 1 946 linhas, `pedidos.php` de 2 752
para 2 534. Os originais ficaram em `.localdb/antes-*.php.bak`.

### O que a base a correr revelou

Cinco defeitos que só apareciam com o ambiente completo, todos corrigidos:

- `migracao-sessoes.sql` e `migracao-decisoes-orcamento.sql` não tinham
  `USE `fixit`;` e falhavam com «No database selected».
- O teste do corte de texto só passava porque `mbstring` faltava: afirmava bytes
  e, com a extensão, o corte é em caracteres. O caminho sem `mbstring` passou a
  ser uma função própria (`fixitCortarBytes()`), testada em qualquer máquina,
  e o contrato de `fixitCortar()` ficou escrito.
- Dois `php -S` no mesmo porto punham a aplicação a falar com o servidor antigo,
  sem extensões — daí «could not find driver» com tudo instalado. Ficou
  registado em `.localdb/LEIA-ME.md`.
- **As páginas autenticadas não enviavam cabeçalhos de segurança.** Só o
  `login.php` mandava `X-Frame-Options: DENY`, `nosniff` e `Referrer-Policy`; o
  painel, os pedidos e as fichas não, o que deixava o back office embutível num
  `<iframe>` de outro site. Passou a haver uma função única —
  `Sessao::cabecalhosSeguranca()` — chamada pelas nove páginas; verificado com
  `curl -D` em cada uma.
- **O limite de pedidos castigava quem se enganava.** O contador subia antes de
  o formulário ser validado, por isso seis tentativas erradas bloqueavam o
  endereço dez minutos sem uma única linha gravada. Passou a subir só depois do
  `INSERT`; testado: seis envios inválidos e um válido seguido dão 303, com o
  contador a 1.

### Testes contra o servidor a correr

`bash .localdb/testes-http.sh` — 14 verificações, sai com 0 quando tudo se
comporta. Cobre o que o teste puro não alcança: conversão repetida da mesma
solicitação, token CSRF inválido, «Convertida» marcada à mão, NIF inválido, e a
conversão no cliente que já existe (o equipamento tem de ficar nesse cliente e
não pode nascer outro).

Duas falsas alarmes que este trabalho produziu, e que valem a pena recordar
porque parecem defeitos e não são:

- **A lista de solicitações não tem formulários** quando o único pedido já está
  convertido — logo não há token CSRF para ler. O token tem de vir da ficha
  (`?ver=ID`). Sem isso, todos os POSTs parecem «sessão expirada».
- **O terminal do Windows não transporta UTF-8** de forma fiável: «não» chega
  como «n�o». Isso faz o servidor recusar um tipo de equipamento que a própria
  lista oferece, e a descrição fica com bytes inválidos. Com percent-encoding
  (`Telem%C3%B3veis`) passa. No browser nunca acontece — o que confirma que o
  problema é do transporte, não da aplicação.
