# DESIGN.md — FixIt

Sistema visual do projecto. A paleta é a da empresa — o azul institucional que
já existia no painel — mas o **site público** desenha-a de outra maneira: papel
cinza-azulado, regras grossas, cantos vivos e números de boletim. O back office
é software interno e não aparece no site.

Fonte de verdade: `assets/css/front.css` (site), `assets/css/publico.css`
(consulta por PIN), `assets/css/painel.css` (tokens do painel, que o site não
altera). O `formulario.css` é partilhado; o site sobrepõe com `.site-body`.

---

## 1. Mundo: o talão de oficina

O site é o boletim que o cliente leva do balcão: regras, numeração, uma cor de
carimbo. Não há fotografia, ilustração nem personagem — e é deliberado. O que o
cliente vê na página é o mesmo que o PIN lhe devolve: o estado do equipamento,
por fases.

Consequências directas:

- cantos vivos (`border-radius: 0`) em tudo o que é do site;
- regras de 2px, nunca sombras difusas;
- fases numeradas `01`–`05` em monoespaçado;
- etiquetas em maiúsculas monoespaçadas, como cabeçalhos de impresso.

## 2. Cor

Fundo cinza-azulado, tinta, um azul de carimbo. Os valores são os da paleta da
casa (`painel.css`), repetidos aqui com âmbito `.site-body` para o site poder
andar sem tocar no painel.

| Token | Valor | Uso |
|---|---|---|
| `--b-papel` | `#F3F6F9` | fundo do site |
| `--b-papel-fundo` | `#E7EFF8` | reserva para blocos afundados |
| `--b-papel-campo` | `#FFFFFF` | fundo de campos |
| `--b-tinta` | `#101C28` | texto, regras, rodapé, sombra de carimbo |
| `--b-tinta-70` | `#37475A` | texto corrido |
| `--b-tinta-55` | `#5A6C80` | apoio (5.4:1 sobre o fundo) |
| `--b-regra` | `#E4E9F0` | separadores finos |
| `--b-carimbo` | `#1A5FA0` | numeração, etiquetas, marcas, botão primário |
| `--b-carimbo-vivo` | `#2472BC` | foco, sublinhado de destaque, barra de progresso |

Semânticos, também do painel: `--vermelho-fg #9B2C2C`, `--verde-fg #14603D`.

Duas regras: a cor nunca é o único sinal (o estado traz sempre a palavra) e o
azul pequeno é sempre `--b-carimbo`; o vivo só em traço, foco ou texto grande.

## 3. Tipografia

Duas famílias do sistema, sem pedidos de rede: sans (`--fonte`, do painel) para
o que se lê, monoespaçada (`--b-fonte-mono`) para o que se consulta.

| Papel | Tamanho | Detalhe |
|---|---|---|
| Título de entrada | `clamp(2.25rem, 1.1rem + 5.6vw, 5rem)` | 800, maiúsculas, `line-height .9`, `letter-spacing -.055em`, 22–26ch, `text-wrap: balance` |
| Título de secção | `clamp(1.375rem, 1.1rem + 1.2vw, 2.25rem)` | 800, maiúsculas, com marca de 30×4px em carimbo por cima |
| Entrada de texto | `1.0625rem` / 1.6 | máx. 46ch, separada do título por uma linha fina |
| Corpo | `1rem` / 1.6 | `--b-tinta-70` |
| Prosa | `1.0625rem` / 1.7 | máx. 68ch; `h2` é etiqueta mono de 12px em carimbo |
| Nome de fase | `1rem` | 800, maiúsculas |
| Número de fase | `1.625rem` (2.5rem em desktop) | mono, tabular, carimbo |
| Etiquetas e fita | `.6875rem`–`.75rem` | mono, `letter-spacing .1em`–`.2em`, maiúsculas |

Maiúsculas só em títulos, etiquetas e números. O texto corrido nunca grita.

## 4. Espaço, forma, elevação

- Escala: 4 · 6 · 10 · 14 · 20 · 22 · 26 · 34 · 46 · 56 px.
- Grelha: `1240px`, `padding-inline 20px`; leitura a 46–68ch.
- Sem raios. Sem sombras difusas.
- Elevação é uma **sombra de carimbo**: `6px 6px 0` em tinta, que cresce para
  `8px 8px` no hover e encolhe no `:active`, com `transform` de 2px no sentido
  oposto. Alvo mínimo de 44px.

## 5. Componentes

- `.site-topo` + `.site-marca` — cabeçalho de 2px em baixo, símbolo em caixa de
  34px, nome em maiúsculas 800, descritor em mono de 10px.
- `.fita` — faixa de tinta a toda a largura com a oferta em mono; envolve em
  ecrãs estreitos.
- `.site-nav` — mono maiúsculas; a página actual marca-se com linha de 2px por
  baixo (desktop) ou a cor do carimbo (telemóvel).
- `.faixa` / `.faixa__sobre` / `.faixa__titulo` / `.faixa__lede` — a entrada:
  etiqueta, título, texto sob uma linha fina.
- `.percurso` — as cinco fases: regra de 2px em cima, colunas separadas por
  regra fina, `01`–`05` em carimbo. Em telemóvel vira lista com o número à
  esquerda. `.percurso__regua` é a barra de progresso de 2px.
- `.faixa__pin` — o PIN na primeira dobra: etiqueta mono, campo de 6 dígitos em
  monoespaçado com `letter-spacing .22em`, botão «Ver estado» ao lado. Envia
  para `consulta.php`; não há passo intermédio.
- `.oferta` — tabela sem preços: 2px em cima, linhas finas, tipo à esquerda.
- `.prosa` — páginas de texto.
- `.pergunta` — `<details>` nativo, `+`/`−` em carimbo mono, sem JavaScript.
- `.form-site` / `.campo` / `.entrada` — etiquetas mono maiúsculas, campos de
  1.5px em tinta sobre branco.
- `.site-aviso` / `.site-sucesso` — caixa fechada de 2px na cor do estado
  (vermelha ou verde), nunca um toast flutuante nem uma barra lateral.
- `.site-nav__grupo` / `.site-nav__submenu` — «O que reparamos» é um menu
  pendurado: em desktop uma caixa de 2px com sombra de carimbo debaixo do
  botão; em telemóvel uma lista indentada dentro do próprio menu. Abre por
  clique, fecha ao clicar fora ou com `Escape`.
- `.tipos` / `.tipos__botao` / `.tipos__painel` — abas por tipo de equipamento
  em `servicos.php`. A aba escolhida enche-se de tinta; o painel mostra o
  desenho à esquerda e a nota à direita. Sem JavaScript ficam os quatro
  painéis empilhados, todos legíveis.
- `.site-filtro` — campo de procura nas perguntas: filtra no browser, com a
  contagem em `role="status"`. Sem JavaScript o campo não aparece e a lista
  fica inteira. O nome é próprio de propósito: `.filtro` é a barra de filtros
  do painel, que é flex.
- `.site-rodape` — bloco de tinta, texto claro, ligações mono maiúsculas.
- `.decisao` — a caixa onde o cliente aceita ou recusa o orçamento, dentro do
  resultado da consulta: 2px em tinta, título em maiúsculas, dois botões do
  mesmo tamanho. Só aparece em «Em diagnóstico» com valor definido.

## 6. Consulta por PIN

`publico.css` desenha-se com os tokens do painel; o revestimento final
redefine-os dentro de `.pagina-publica` (radios a 0, sombras a nenhuma, azul do
painel no botão primário). A consulta pertence ao mesmo mundo do site sem
duplicar a folha.

A consulta imprime-se (§08 da folha): `@media print` esconde cabeçalho,
formulário e acções, e o resultado sai a preto sobre branco, com o cabeçalho do
talão (`#pedido` e a marca) impresso por cima. As fases e as fichas não se
cortam entre folhas. Sem bibliotecas: o botão só chama `window.print()`.

## 7. Movimento

- **A moldura não viaja.** Site e painel trocam de página com as transições de
  vista nativas, sem JavaScript. O que é igual nos dois documentos — cabeçalho,
  fita e rodapé no site; barra e barra lateral no painel — leva o mesmo nome e
  fica parado; só o conteúdo atravessa.
- **A folha sobe 6 px.** O conteúdo novo entra em 200ms
  (`cubic-bezier(.16,1,.3,1)`) depois de o antigo sair em 120ms. É o mesmo
  compasso dos dois lados, para o site e o painel lerem como a mesma casa.
- **Num recorte da mesma lista** — um filtro, outra página, outra ordem — a
  página não viaja: só a tabela se atravessa.
- **Ligações e controlos** trocam de cor e de fundo em 120ms. O botão primário
  é o único que muda de sombra: o carimbo a assentar.
- A barra do percurso preenche-se com o scroll — informação, não decoração.
- `prefers-reduced-motion: reduce`: barra em tinta lisa, sombra fixa em `4px`,
  transições a `.01ms` e a troca de página em corte seco — sem subida e sem
  desvanecimento, mas o conteúdo novo aparece na mesma. Nada desaparece.

## 8. Voz

Português de Portugal, segunda pessoa, frases curtas, zero superlativos.

- Diz o que se faz e o que acontece a seguir.
- Nunca promete preço, prazo, garantia, marca, certificação nem anos de casa.
- Contactos em falta não se inventam: o bloco desaparece em vez de ficar vazio.

## 9. Back office

O painel veste a mesma gramática desde o bloco 15 de `painel.css`: raios a zero,
regras de 2px em vez de sombras difusas, etiquetas mono maiúsculas, pílulas de
estado fechadas na própria cor e sombra de carimbo nos botões e nos cartões que
levam a algum lado. A barra lateral passou de azul-marinho a tinta.

A paleta não mudou — já era esta. O que mudou foi a forma, e nada disso toca a
geometria do painel: os componentes são os mesmos, só vestidos de outra maneira.

### A folha de serviço

O painel veste-se como uma folha de serviço impressa. Cada painel abre com uma
faixa de tinta: título a branco em maiúsculas, uma marca azul de 20×4 px a
anteceder e a descrição em tinta suave. É o único sítio do painel onde a tinta
funciona como fundo — e é isso que separa um formulário de um cartão qualquer.

O papel tem uma pontuação quase invisível (um ponto de 1 px a cada 22 px), que
só se lê nas calhas entre os cartões. O cabeçalho de página ganha uma marca azul
de 34×5 px acima do título, o título sobe de tamanho e a régua de 2 px fecha o
bloco. Os selos das métricas passam a quadrados de tinta com a figura em papel,
como um carimbo; a cor do estado vive na etiqueta da nota, onde há texto para a
explicar. O carimbo dos botões baixou de 6 para 4 px.

Uma excepção deliberada: quando o cabeçalho do painel também traz a ordenação ou
os filtros, a faixa desaparece e fica papel. Um fundo escuro atrás de chips e
selects esconde exactamente aquilo que se pode tocar. O `:has()` faz a escolha;
navegadores sem ele ficam com a faixa, que continua legível.

### Os dois temas

O painel tem duas luzes e é a mesma casa nas duas. «Papel» é a folha clara, a de
origem. «Tinta» é a mesma folha à noite: papel escuro, texto claro, o mesmo
traço de 2px e a mesma sombra de carimbo, agora preta. Não muda um único
componente de sítio — mudam os valores dos tokens, e a página acompanha.

Três tokens existem para isso: `--traco` desenha as réguas, `--carimbo` faz a
sombra e `--realce-suave` é o fundo das linhas sob o cursor. No tema claro valem
exactamente o que valiam antes do tema existir, por isso a folha clara não
mudou de aspecto.

O botão está na barra de topo e na entrada, e a escolha guarda-se no browser
(`localStorage`), não na conta: quem partilha o computador da receção não muda o
ecrã do colega. O atributo é escrito antes do primeiro desenho, para a página
não aparecer clara e escurecer a seguir. O item activo da barra lateral deixou
de ser uma placa azul preenchida — era a única superfície assim no painel — e
passou a régua branca à esquerda com um véu claro, como uma marca de papel.

Duas notas de implementação: `painel.css` é a primeira folha a carregar, por isso
as regras de campo vivem em `formulario.css` e a do botão de entrada em
`login.css` — é aí que fecham a cascata. E `consulta.php` continua a sobrepor-se
dentro de `.pagina-publica`, para a consulta pública não herdar o painel.

## 10. Limites

- Sem fotografia. As oficinas não têm imagem própria e o `PRODUCT.md`
  proíbe inventá-la. O que existe são desenhos: as quatro abas de
  `servicos.php` escrevem o SVG dentro do documento, e o talão da página
  inicial e o detalhe do PIN são ficheiros em `assets/img/` servidos pelo
  próprio site — nenhum ficheiro externo, nenhum CDN, nada que falhe
  offline. A moldura (`figura`) repete a gramática do papel: regra de 2px,
  cantos vivos e sombra de carimbo.
- O cabeçalho é fixo (`position: sticky`), para o PIN e o pedido estarem sempre
  ao alcance depois do scroll.
- Sem animação de entrada de página: tem de abrir depressa num telemóvel antigo.
- Abaixo de 380px a assinatura fica só com o nome, para o cabeçalho não partir.
- **O site não liga ao back office.** O painel é software interno da empresa e
  não tem porta de entrada pública; só o PIN do talão abre o estado do pedido.
- O painel e o site partilham a paleta **e** a gramática (ver §10), mas não o
  conteúdo: o painel é software interno e não tem porta de entrada pública.

## 10. Acessibilidade medida, não prometida

O `PRODUCT.md` promete alvos a partir de 44 px e contraste WCAG AA. Estas são
as decisões que fazem disso uma afirmação verificável:

- **Alvos de 44 px em todos os controlos.** Botões, botão de ícone, ligações do
  menu, marca, pílulas da fila, ligação do gráfico, acções de tabela e o campo
  de técnico dentro da lista. A densidade do talão perde 4 a 12 px por peça; o
  polegar ganha o dobro.
- **Números tabulares.** Contadores, PIN, orçamento, IDs e valores de tabela
  usam `font-variant-numeric: tabular-nums`: os dígitos não dançam quando o
  valor muda e as colunas alinham à direita sem truques.
- **Cursor de texto na paleta.** `caret-color` no azul institucional, em vez do
  azul do sistema.
- **Selecção de texto** definida por superfície (`::selection` no painel, no
  site e nas páginas públicas).
- **Foco sempre visível** e alvos de teclado na ordem do documento; o menu
  lateral abre e fecha com Escape.
- **Movimento** respeita `prefers-reduced-motion` — três blocos, incluindo a
  animação do gráfico.

A auditoria corre no browser a 390 e a 1440 px: sem transbordo horizontal, um
`h1` por página, imagens com `alt`, nenhum texto abaixo de 4,5:1 (3:1 em texto
grande) e nenhum controlo abaixo de 44 px. Ligações dentro de frases ficam com
o alvo do próprio texto, como a WCAG 2.5.8 admite para ligações em linha.
