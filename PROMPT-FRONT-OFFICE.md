# Front office do FixIt — prompt para a skill `impeccable`

## Para ti: como usar

1. Abre um chat novo **na raiz deste projecto** (`CPWPROJ`) e cola tudo o que está entre `=== INÍCIO DO PROMPT ===` e `=== FIM DO PROMPT ===`.
2. A skill `impeccable` está **dentro do projecto**, em `.agents/skills/impeccable/` — não depende do chat nem de uma instalação global. Se um dia essa pasta faltar, o **ponto 13** traz o método em fallback, incluindo o sorteio das direcções e a revisão final.
3. O prompt já descreve a estrutura real das pastas, o código partilhado, o ambiente e os comandos de verificação. A ficha factual está fechada: nome, oferta, ausência de preços, prazos e garantias, e ausência de fotografias. Só a morada, o telefone, o email e o horário ficam `null` — preenche-os em `frontoffice/dados-oficina.php` quando os tiveres (Anexo B).
4. A versão curta está no fim, para quando não quiseres o brief todo.

---

=== INÍCIO DO PROMPT ===

# Missão

Constrói o **front office completo** do FixIt: o site público que o cliente vê antes, durante e depois de deixar um equipamento na oficina.

O back office já existe, está fechado e a funcionar. Não o redesenhes: a única alteração autorizada está no ponto 5.

Trabalhas com a skill **impeccable**. Ela não é decoração — define um processo (contexto → modo → direcção → contrato → construção → inspecção → revisão final → documentação) e uma linha de qualidade. Segue-o pela ordem e diz em cada fase o que fizeste. O resultado tem de parecer feito por alguém que conhece esta oficina, não por alguém que viu dez sites de reparações.

---

# 1. O produto e o estado actual

**FixIt** é uma oficina de assistência técnica em Portugal. O sistema já construído:

- **Back office** (`backoffice/`) em PHP: login, painel com métricas e gráfico, lista de pedidos com filtros e ordenações, ficha do pedido com histórico e orçamento, registo de pedido novo com fotografias, clientes, equipamentos, utilizadores e conta própria.
- **Consulta pública** (`frontoffice/index.php`): o cliente introduz o **PIN de 6 dígitos do talão de entrada** e vê em que fase está a reparação — percurso de 5 fases com datas, avaria descrita, orçamento, entrada e conclusão. Por segurança não mostra o nome do cliente.
- **Base de dados** MySQL: `clientes`, `equipamentos`, `pedidos` (estado: Recebido → Em Diagnostico → Em Reparacao → Pronto → Entregue), `historico_pedidos`, `historico_orcamentos`, `historico_pins`, `consultas_publicas`, `historico_tecnicos`, `avisos_pin`, `fotos_equipamento`.

```
CPWPROJ/
├── index.php                 redirecciona a raiz para frontoffice/index.php
├── frontoffice/
│   └── index.php             consulta pública por PIN  ← vai passar a ser a entrada do site
├── backoffice/
│   ├── login.php  logout.php  conta.php
│   ├── painel.php  pedidos.php  pedido.php  pedido-novo.php
│   └── clientes.php  equipamentos.php  utilizadores.php
├── nucleo/                   partilhado pelas duas superfícies
│   ├── conexao.php           obterConexao() — PDO singleton
│   ├── sessao.php            Sessao::iniciar/exigir/exigirAdministrador/entrar/sair, CSRF
│   ├── estado-pedido.php     AcaoEstadoPedido — mudança de estado partilhada
│   └── pin-consulta.php      gerarPinUnico()
├── assets/css/               painel.css (tokens + back office), formulario.css,
│                             login.css, publico.css
├── uploads/                  fotografias, protegidas por .htaccess
├── sql/                      fixit.sql + 6 migrações + utilizadores-iniciais.sql
└── screenshots/              capturas das revisões anteriores
```

## 1.1 Ficha fechada (decidida pelo dono da oficina)

Não perguntes isto outra vez, nem o apresentes como suposição. O que não está aqui não existe no site.

| Facto | Decisão |
| --- | --- |
| Nome | **FixIt**, com o descritor «Assistência técnica». É o nome real, não provisório |
| Oferta | **Informática e telemóveis**: portáteis, desktops, telemóveis/smartphones e tablets. É o limite — não acrescentes consolas, impressoras, eletrodomésticos, TVs nem «todas as marcas» |
| O que não se repara | **Não há lista.** Não inventes exclusões; se o site não precisar de as enumerar, não as enumere |
| Preços | **Nenhum.** Sem valores, sem «a partir de», sem «orçamento grátis», sem desconto de diagnóstico |
| Prazos | **Nenhum.** Sem «24 horas», «no mesmo dia», «em 48h». Explica as fases sem prometer duração |
| Garantia | **Nada afirmado.** Não escrevas meses, condições nem cobertura |
| Morada, telefone, email, horário | **Por preencher** (`null` em `frontoffice/dados-oficina.php`). Ver a regra no ponto 5 |
| Fotografias | **Nenhuma.** Nem banco de imagens, nem pessoa a fingir de técnica, nem ilustração a imitar fotografia. O material é tipografia, marca, percurso e o talão como estrutura |

O negócio é pequeno e local: o site fala com quem já tem o equipamento na mão ou está a decidir se o leva à oficina. Não é uma campanha de marca.

**O mecanismo único deste produto, em uma frase:** o cliente vê o trabalho a acontecer — fase a fase, com data, orçamento e o que foi descrito na receção — em vez de telefonar para perguntar. A oficina mostra em vez de prometer.

Esse é o teu primeiro ecrã. Tudo o resto do site serve essa prova.

---

# 2. Enquadramento técnico (não negociável)

- **PHP 8.0+ puro**, `declare(strict_types=1)`, PDO com prepared statements, MySQL/MariaDB. Sem Composer, sem npm, sem framework, sem bundler, sem build step, sem SPA, sem React/Vue/Alpine/Tailwind. Cada página é um ficheiro PHP que inclui o que precisa e desenha o seu HTML.
- **Includes a partir de `nucleo/`**, com o caminho relativo à pasta da página:
  `require_once __DIR__ . '/../nucleo/conexao.php';` — e o mesmo para `sessao.php`, `estado-pedido.php` e `pin-consulta.php`. O `index.php` da raiz não inclui nada.
- **Estilos partilhados em `assets/css/`**, sempre com `../` a partir das páginas:
  `<link rel="stylesheet" href="../assets/css/painel.css">`. Uma página nova que escreva `assets/css/...` fica sem estilo.
- **Uploads em `uploads/` na raiz.** O `backoffice/pedido-novo.php` já usa `__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads'`. Mantém esse padrão.
- **Tokens e mundo visual vivem em `assets/css/painel.css`.** Qualquer alteração aí é aditiva: não renomeies variáveis existentes nem recalcules cores que o back office usa.
- **Voz, datas, escape e mensagens já têm convenção** nos ficheiros existentes: `h()`, `dataCurta()`, `euros()`, erros em português, `POST → Redirect → GET`, CSRF por sessão, limites de tentativas. Lê-os e continua-os; um ficheiro novo com outro estilo é uma regressão.
- **`equipamentos.tipo` é `VARCHAR(60)` de texto livre.** A base de dados **não** define um catálogo de serviços: a oferta vem do que está escrito no ponto 5, nunca do esquema.
- **Não há git, README, Composer nem pasta de testes** dentro do projecto. Não introduzas nenhum deles sem pedir.

---

# 3. Processo impeccable (por esta ordem)

1. **Contexto.** Corre `impeccable context` uma vez, com `--target frontoffice/index.php`, a partir da cópia vendorizada no projecto (`.agents/skills/impeccable/scripts/impeccable`, ou `impeccable.cmd` numa shell Windows sem `sh`). Se o launcher não estiver disponível, não pares nem inventes comandos: segue o **ponto 13** (fallback completo, sem launcher), di-lo na primeira resposta antes do tool call seguinte, e continua a ler o projecto directamente.
2. **`init`.** Não existe `PRODUCT.md`. Produz um, curto e factual: o que é o FixIt, quem o usa (cliente com um equipamento avariado; técnico no balcão), o que o site público tem de conseguir, o que não pode afirmar. Nada de missões e valores inventados.
3. **Modo por superfície.** O site público é **Persuade** (o visitante decide e age). A consulta por PIN é **Operate**: já existe, herda-se, não se reinventa. Escreve o modo no brief de cada superfície, não no global.
4. **`reference/new-work.md`, ramo «criar uma superfície inteira dentro de um mundo estabelecido».** O mundo do FixIt já existe: a marca é uma **cabeça de parafuso em cruz**, a família cromática é azul institucional sobre cinzas azulados, a tipografia é de sistema, a voz é sóbria e precisa, o alvo de toque é 44 px, o movimento respeita `prefers-reduced-motion`. **Herda esse mundo.** O site pode ter mais temperatura do que o painel — mais cor, mais escala, mais voz — mas é a mesma oficina, não outra marca. Se concluíres que a identidade tem de ser substituída, **pergunta**; não é decisão tua.
5. **Cinco a sete estruturas, depois o roll.** Deriva as estruturas da primeira página a partir do conteúdo e do comportamento do visitante e corre `impeccable concept-seed --scope surface --mode persuade`. Apresenta as cartas como a skill manda e **não escrevas código antes de o utilizador fechar uma**. Sem geração de imagens no ambiente, diz numa linha que a construção é code-led e segue.
6. **Contrato de direcção**, gravado no brief da superfície com a ferramenta da própria skill e não num ficheiro inventado: `impeccable surface-brief read frontoffice/index.php` antes de escrever e `impeccable surface-brief write frontoffice/index.php <ficheiro-do-contrato>` depois. Os seis blocos são THESIS, OWN-WORLD, STORY, FIRST VIEWPORT, FORM, FINISH, mais a referência de seed (`seed:` e 16 hex). Relê o brief e confirma que estão todos antes de construir.
7. **`reference/craft-floor.md`** imediatamente antes da primeira edição de UI. Não o anuncies; cumpre-o.
8. **Constrói** a direcção escolhida por inteiro. A primeira dobra é uma tese, não um cabeçalho. Prova, não afirmes.
9. **Inspecciona** a 1440, 390 e 320 px, e conduz todos os estados (vazio, erro, carregando, sucesso, texto longo). Duas rondas no máximo, correcções em lote.
10. `impeccable detect --json` nos ficheiros mudados; resolve o que for mecânico.
11. **Revisão final** com o `impeccable-finish-reviewer` e o pacote completo. Age sobre a disposição: `recapture`, `rebuild`, `ship` ou `fix`, e reporta a palavra e o âmbito reais.
12. **Documenta** com o `impeccable-documenter` (agente da skill): `DESIGN.md` com tokens reais **e** `.impeccable/design.json`. Prosa sozinha não conta como `DESIGN.md`.

Ferramenta ausente não é fase saltada em silêncio: di-lo e explica o que ficou por verificar.

---

# 4. A rota que esta categoria tem sempre — e o seu oposto previsível

**Rut A:** fundo escuro com gradiente, mockup de telemóvel inclinado, «orçamento grátis em 24h», três cartões iguais com ícone + título + texto, fila de logótipos de marcas, contador de anos, selo de garantia, fotografia de stock de alguém de bata a olhar para uma placa.

**Rut B (o oposto previsível):** papel creme, serifada de alto contraste, «artesanal», «feitо com cuidado», itálicos e um traço à mão.

Nenhum dos dois. Se alguém adivinhar o teu visual só pela categoria, ou só pela categoria mais a fuga a ela, ainda não decidiste nada.

**Material de partida (sementes, não a direcção):** o mundo do *cliente* que entra nesta oficina — o talão de entrada com picotado e numeração, o carimbo com a data, a fila de espera ao balcão, a prateleira de caixas numeradas, a etiqueta colada ao equipamento, a chamada «já está pronto», a garantia escrita à mão, a fatura. Pelo menos três famílias materiais diferentes. A direcção sai do roll.

---

# 5. Âmbito: rotas e trabalho

`frontoffice/index.php` é hoje a consulta. Passa a ser a **entrada do site**, e a consulta muda para `frontoffice/consulta.php`. O `index.php` da raiz já redirecciona para `frontoffice/index.php` — não lhe toques.

| Rota | Modo | Trabalho do visitante | Conteúdo obrigatório |
| --- | --- | --- | --- |
| `frontoffice/index.php` | Persuade | Perceber em 5 segundos o que é isto, que é para ele, e agir | Tese do produto, a oferta real, como funciona em três passos, prova do percurso, contacto (se existir), acção principal |
| `frontoffice/consulta.php` | Operate | Saber onde está o equipamento | Recebe **todo** o código de `frontoffice/index.php` actual: PIN, limites, sessão de 30 min, PRG, percurso com datas, detalhes, `noindex` |
| `frontoffice/servicos.php` | Persuade | Confirmar se reparam o que ele tem | Informática (portáteis, desktops) e telemóveis (smartphones, tablets) — e só isso. O que trazer e o que acontece a cada tipo de avaria, sem prometer prazo nem preço |
| `frontoffice/como-funciona.php` | Persuade | Saber o que vai acontecer ao seu equipamento | As cinco fases do lado do cliente, o que é o PIN e o talão, o que fazer se o orçamento não compensar |
| `frontoffice/orcamento.php` | Persuade | Pedir orçamento sem ir ao balcão | Formulário real que grava e confirma |
| `frontoffice/contacto.php` | Persuade | Encontrar a oficina e falar com alguém | Morada, horário e contactos **só se existirem** em `dados-oficina.php`; com eles a `null`, o formulário de orçamento é o caminho e não há mapa |
| `frontoffice/perguntas.php` | Read | Tirar a dúvida que o está a travar | 8 a 12 perguntas reais: PIN perdido, orçamento recusado, prazos, garantia, dados pessoais |
| `frontoffice/privacidade.php` | Read | Perceber o que fazem com os seus dados | Base legal, o que o formulário guarda, durante quanto tempo, como pedir eliminação |
| `frontoffice/404.php` | — | Não ficar preso | Voz da casa, caminho de volta |
| `robots.txt`, `sitemap.xml` | — | — | Front indexável; `backoffice/` e `consulta.php` fora |

Ficheiros partilhados novos, e só estes:

- `frontoffice/front-comum.php` — marca, `<head>` (título, descrição, canonical, Open Graph), cabeçalho, rodapé, sprite SVG e helpers. Cada página faz `require_once __DIR__ . '/front-comum.php'` e desenha o miolo. Funções e `echo`, nunca um motor de templates.
- `frontoffice/dados-oficina.php` — **a única fonte de verdade factual**: um array `$oficina` com comentários a dizer o que cada campo alimenta. Já decidido: `nome` = «FixIt», `descritor` = «Assistência técnica», `oferta` = informática e telemóveis, `precos` = `null`, `prazos` = `null`, `garantia` = `null`. Por preencher, a `null`: `morada`, `telefone`, `email`, `horario`, `mapa`. Regra dos campos vazios: a página **omite** o bloco — nunca um vazio, nunca um «a confirmar» visível ao cliente. Com os contactos a `null`, `contacto.php` apresenta o formulário de orçamento como o caminho para falar com a oficina, e nada mais. Nenhum destes valores volta a ser escrito à mão em HTML.
- `assets/css/front.css` — a folha do site. A consulta mantém `publico.css`.
- `sql/migracao-solicitacoes.sql` — tabela dos pedidos de orçamento.
- `assets/js/` **não** existe e não deve passar a existir: o JavaScript pequeno fica inline na página, como no resto do projecto.

**O único trabalho autorizado no back office:** `backoffice/solicitacoes.php`, atrás de `Sessao::exigir()`, que lista os pedidos de orçamento recebidos (mais recentes primeiro), marca «em contacto» e «arquivada». Reutiliza os componentes do painel, inclui `__DIR__ . '/../nucleo/sessao.php'` e liga `../assets/css/painel.css`. Sem direcção visual própria, sem métricas novas. Se preferires não tocar no back office, então **não entregues o formulário** — um formulário que não vai a lado nenhum é pior do que não o ter, e tens de o dizer.

---

# 6. A acção principal tem de funcionar

1. **Consultar o estado** — herda-se sem perder nenhuma defesa: 8 tentativas por sessão, 20 por IP em 10 minutos, mensagem única para PIN errado, resultado em sessão de 30 min, PIN nunca no URL, sem nome do cliente.
2. **Pedir orçamento** — nova, e tem de gravar:
   - `POST` com token CSRF de sessão, validação no servidor de todos os campos, erro por campo e resumo de erros focável quando há mais de um.
   - Limite por IP no mesmo padrão do `frontoffice/index.php` actual (ficheiro em `sys_get_temp_dir()`, nome = hash do IP, janela de 10 minutos) e um honeypot invisível. Sem CAPTCHA, sem serviço externo.
   - Grava em `solicitacoes` numa transação, com `estado = 'Nova'`, e responde `303` para `orcamento.php?enviado=1` (PRG).
   - **Nunca escreve em `pedidos`**: um pedido de orçamento não é uma ordem de serviço. Um pedido nasce no balcão, com equipamento à frente e PIN emitido.
   - Nenhum dado pessoal em logs, URLs ou mensagens de erro. Ligação a `privacidade.php` junto ao botão, sem consentimento pré-marcado.
   - Se a base de dados estiver em baixo, a página diz que não conseguiu e o que fazer — nunca um «obrigado» falso.

---

# 7. O que não pode ser inventado

Fechado. Não escrevas, não sugiras em comentários, não ponhas em `placeholder`:

morada · telefone · email · horário · anos de existência · número de reparações · marcas assistidas · preços · prazos de reparação · garantias · avaliações ou nomes de clientes · nomes ou fotografias de técnicos · certificações · parcerias · taxa de sucesso · tempo médio de resposta.

**Não pares para perguntar: a ficha do ponto 1.1 está fechada.** Se um facto faltar, tens duas saídas e nenhuma terceira: escrever copy que não precise dele, ou deixar o bloco fora da página. Nunca um número plausível, nunca um «a confirmar» visível ao cliente.

Fotografias: **não há nenhumas.** Constrói sem fotografia — tipografia, marca, percurso e o talão como estrutura. Sem banco de imagens, sem pessoa a fingir de técnica, sem ilustração desenhada a imitar fotografia. Se mais tarde o dono entregar imagens reais, entram por `frontoffice/dados-oficina.php`.

---

# 8. Identidade, dentro do mundo que já existe

- **Intocável:** a marca (parafuso em cruz), o nome, o descritor «Assistência técnica», a família cromática azul, a voz.
- **Aberto:** a estratégia de cor do site pode subir para *Committed* (uma cor saturada a ocupar regiões inteiras) ou *Full palette*. O back office fica *Restrained*; o site pode não ficar. Escolhe a estratégia antes das cores e escreve uma frase de cena física (quem, onde, com que luz) que a force.
- **Tipografia:** a família de sistema continua válida. Uma voz de display própria tem de ser um `woff2` servido do projecto, nunca `fonts.googleapis.com` nem CDN. Uma display de sistema (Impact, Arial Black) como voz principal é falha.
- **Movimento:** um momento autoral, orquestrado. O percurso do PIN é o candidato natural — prova que ajuda a compreender; se for enfeite, corta.
- **Estados de browser** fazem parte do design: selecção, caret, foco, números tabulares, scrollbars. Nada fica com a cor por omissão do browser.

Proibições que a skill impõe, sem excepção: gradiente em texto; vidro/blur decorativo; `border-left` colorida acima de 1px; sombra dura deslocada; cartões iguais de ícone + título + texto como estrutura; **`kicker`/eyebrow acima de títulos**; números de secção 01/02/03; emoji como ícone; ilustração em estilo esboço; grelhas ou riscas decorativas como fundo.

---

# 9. Voz

Português de Portugal, segunda pessoa do singular, como a consulta já faz («Confirma os 6 dígitos»). Frases curtas, verbos concretos. O vocabulário é o do balcão e o do talão — **pedido, talão de entrada, PIN, orçamento, oficina, pronto, entregue** — e o site usa exactamente as palavras que o cliente lê no papel, senão ele não reconhece o próprio pedido.

Nunca: «soluções», «inovador», «de confiança há X anos», «rápido e eficaz», «os melhores», «paixão por tecnologia», pontos de exclamação em série. Um erro nomeia o problema e a saída. Um botão nomeia a acção («Pedir orçamento», não «Enviar»). Um título carrega-se sozinho, sem etiqueta por cima.

---

# 10. Acessibilidade, responsividade, desempenho

- WCAG 2.2 AA: contraste de corpo ≥ 4,5:1 e de texto grande ≥ 3:1; foco sempre visível a partir da paleta; alvos ≥ 44 px; menu móvel operável por teclado, com foco preso enquanto aberto e devolvido ao fechar; `aria-current` na navegação; um só `h1` por página; `prefers-reduced-motion` respeitado.
- Formulário: `label` real (nunca `placeholder` como rótulo), `autocomplete` e `inputmode` correctos, erro ligado por `aria-describedby`, resumo de erros focável, estado de envio anunciado.
- Larguras: **320, 360, 390, 768, 1024, 1440**. Texto a 200% sem overflow. Palavras sem espaços quebram com `overflow-wrap: anywhere` — o projecto já trata isto, não é para perder.
- Desempenho: sem CDN, sem fontes de terceiros, sem bibliotecas de JS. CSS numa folha, imagens com `width`/`height` e `loading="lazy"` abaixo da dobra, zero layout shift.

---

# 11. Critérios de aceitação

- [ ] Todas as rotas do ponto 5 existem, respondem 200 e são alcançáveis a partir do menu e do rodapé.
- [ ] `frontoffice/consulta.php` mantém **todas** as defesas do `frontoffice/index.php` actual: 8 tentativas por sessão, 20 por IP/10 min, mensagem única, sessão de 30 min, PRG, sem nome do cliente, `noindex`.
- [ ] O `index.php` da raiz continua a redireccionar para `frontoffice/index.php`, que agora é o site.
- [ ] O formulário de orçamento grava uma linha em `solicitacoes` e o registo aparece em `backoffice/solicitacoes.php`; com CSRF inválido não grava; acima do limite mostra o bloqueio com os minutos restantes.
- [ ] `frontoffice/dados-oficina.php` é a única fonte de morada, telefone, horário e oferta; nenhum desses valores está escrito à mão no HTML.
- [ ] Nenhuma afirmação da lista fechada do ponto 7 aparece no site.
- [ ] Nenhum texto de preenchimento ficou no código (procurar «lorem», «exemplo», «X anos», «TODO»).
- [ ] Back office intacto: login, painel, lista, uma ficha e a atribuição de um técnico continuam a funcionar; `assets/css/painel.css` só cresceu de forma aditiva.
- [ ] Todas as páginas novas usam `../assets/css/…` e `__DIR__ . '/../nucleo/…'`; uma página sem estilo é falha.
- [ ] 320, 390 e 1440 px sem scroll horizontal e sem sobreposição, incluindo o menu móvel aberto.
- [ ] `robots.txt` e `sitemap.xml` existem; o back office e a consulta não são indexáveis.
- [ ] Zero dependências novas: `grep` por `cdn`, `googleapis`, `unpkg`, `jsdelivr` no que criaste devolve zero.

---

# 12. Verificação e relatório

**Ambiente real deste projecto** (não inventes outro):

- A skill está vendorizada: `.agents/skills/impeccable/` (`SKILL.md`, `reference/` com 36 ficheiros, `agents/` com 5, `scripts/` com 11). O launcher `.agents/skills/impeccable/scripts/impeccable` arranca e responde `4.0.0`, com `context`, `concept-seed`, `surface-brief` e `detect` disponíveis. Usa esta cópia, não uma instalação global, e não peças ao utilizador para instalar nada.
- O PHP **não está no PATH**. O binário é `C:\Users\Martim Silva\AppData\Local\Temp\php-win\php.exe` (PHP 8.4.27).
- Servidor de desenvolvimento: `<php> -d display_errors=1 -S 127.0.0.1:<porta> -t "C:\Users\Martim Silva\Desktop\CPWPROJ"`.
- O MySQL/MariaDB portátil **pode estar desligado**. Sem ele o back office mostra o estado de erro e não consegues testar login nem PIN. Liga-o antes de dizeres que algo funciona; se não conseguires, diz que não correu.
- O harness de testes vive **fora** do projecto, em `%TEMP%\fixit-auth` (`resetar.sh`, `correr-todas.sh`, suítes `teste-*.sh`, servidor em 8126). Foi escrito para a estrutura de pastas antiga: as suítes vão falhar até serem ajustadas. Não as apresentes como prova sem as correr.

**Obrigatório antes de dizer que está feito:**

- `php -l` em todos os ficheiros novos e alterados.
- Conduzir o site a sério: consultar um PIN, enviar o formulário, provocar um erro, abrir o menu no telemóvel.
- Capturas `.impeccable/review/desktop.png` (1440, página inteira), `mobile.png` (390) e uma a 320. Abre cada ficheiro e confirma que mostra o que o nome promete.
- Disposição real do `impeccable-finish-reviewer` e `DESIGN.md` escrito a partir do que ficou construído.

**No fim, responde com:** o que o site é agora e qual foi a direcção escolhida (três linhas); as rotas criadas e o que cada uma faz; o que é **real** e o que está **por preencher** em `frontoffice/dados-oficina.php`; o que mudou no back office (idealmente só `solicitacoes.php`); a tabela do ponto 11 com o resultado de cada linha; a disposição do revisor e o âmbito dela; e o que fica em dívida.

---

# 13. Se a skill não estiver disponível (fallback completo)

Este ponto existe para o prompt funcionar sozinho. Se a skill `impeccable` estiver instalada e o launcher resolver, ignora-o e segue os pontos 3 a 12. Se não estiver, **não pares** e não imites comandos que não tens: aplica o que está aqui e di-lo na primeira resposta — «a skill não está disponível; estou a usar o fallback do ponto 13». O que se perde é o sorteio aleatório do launcher; o que não se perde é o método nem o piso de qualidade.

## 13.1 Os dois ruts, escritos antes de desenhar

Escreve numa linha cada um os dois ruts do ponto 4. Servem para os reconheceres se aparecerem na tua própria lista.

## 13.2 Sete candidatos, pelo menos três famílias

A partir do mundo do **cliente** — artefactos, lugares, rituais e tradições gráficas que ele conhece de cor, não os instrumentos que o técnico opera — lista **sete** sistemas visuais concretos. Cada um leva duas linhas: porque ressoa com o mecanismo do produto, e como se parece (chão, cores principais, vozes tipográficas, o sinal que o torna reconhecível). Regras: pelo menos três famílias materiais diferentes; os dois ruts do ponto 4 ficam de fora; ordena por ressonância, não por facilidade.

## 13.3 O sorteio, sem o script

Não escolhas tu o favorito — é precisamente essa escolha que produz o resultado de categoria. Usa uma regra determinística que qualquer pessoa pode repetir e verificar:

1. numera os sete pela ordem em que os escreveste;
2. calcula `crc32` do nome curto de cada um;
3. ordena por esse valor, do menor para o maior;
4. os **três primeiros** são a mão. O primeiro constrói-se; os outros dois ficam como alternativas completas.

Apresenta os três em cartões de igual peso — tese, paleta, materiais, primeira dobra, risco honesto — e **espera que o utilizador feche um**. Sem esse fecho não há código. Regista no brief: `seed: none (unavailable) — mão por crc32`, para não fingires uma referência que não existe.

## 13.4 Julgar antes de construir

Para cada um dos três, responde a duas perguntas: **o público reconhece-se nisto?** e **o produto fica mais claro?** Quem ganhe numa e perca na outra continua em jogo como alternativa; quem perca nas duas sai. Se a mão inteira perder nas duas, o problema está na lista dos sete — volta a 13.2, não amolece a escolha.

## 13.5 Contrato de direcção

Grava em `frontoffice/BRIEF.md` — é o brief da superfície, na falta da ferramenta da skill — com seis blocos, ~150 palavras no total:

- **THESIS** — a ideia que esta superfície possui e o arranjo de categoria que recusa.
- **OWN-WORLD** — paleta e linguagem de componentes, reconhecíveis com todo o conteúdo removido.
- **STORY** — o que o visitante entende, acredita e faz.
- **FIRST VIEWPORT** — composição exacta: o que está onde, a que escala, e onde pousa a acção principal.
- **FORM** — a forma escolhida, a posição dela na lista dos sete, e a mão que a escolheu.
- **FINISH** — literalmente: «unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance».

Se um bloco se ler como intenção ou humor, ainda não está decidido.

## 13.6 Piso de qualidade

Proibido, sem excepção: gradiente em texto; vidro ou blur decorativo; `border-left` colorida acima de 1px; sombra dura deslocada; cartões iguais de ícone + título + texto como estrutura da página; etiqueta ou kicker acima de um título; números de secção; emoji como ícone; ilustração em estilo esboço; riscas ou grelhas decorativas como fundo; display de sistema como voz principal; modo claro ou escuro escolhido por categoria.

Verifica no render, tudo de uma vez: contraste de 4,5:1 no corpo; sombras com deslocamento e desfoque; mais espaço acima de um título do que abaixo; medida de 65 a 75 caracteres; um único momento de movimento, com ease-out exponencial e conteúdo visível por omissão; estados hover, disabled, loading, error e empty; selecção de texto, caret, foco, scrollbar e números tabulares tirados da paleta; e toda a cobertura do ponto 5 encontrável em segundos.

## 13.7 Revisão final sem o agente revisor

Depois de parares de construir, faz uma passagem separada com o papel de **revisor**, não de autor:

1. abre as três capturas (1440, 390, 320) e conduz os estados, à procura de sobreposição, corte e transbordo;
2. responde por escrito a dez perguntas: o primeiro ecrã diz o que é isto? mostra a acção? a oferta é verdadeira? há alguma afirmação inventada? o foco é visível? o teclado chega a tudo? alguma palavra sai do contentor? o vazio explica o que fazer? o erro diz como sair? o back office ficou intacto?
3. cada resposta «não» é uma correcção, não uma nota de rodapé;
4. só depois escreve o `DESIGN.md` com os tokens reais — cores, espaçamentos, raios, tipografia — e o que cada um governa.

Isto não substitui o revisor da skill; substitui-o quando ele não existe, e tens de dizer que foi assim.

=== FIM DO PROMPT ===

---

## Anexo A — Versão curta (para colar num chat)

> Constrói o **front office completo** do FixIt neste projecto. Estrutura: `frontoffice/` (público), `backoffice/` (oficina, fechado), `nucleo/` (conexao.php, sessao.php, estado-pedido.php, pin-consulta.php), `assets/css/` partilhado, `uploads/` na raiz. PHP 8 puro, PDO/MySQL, sem framework, sem build, sem CDN. Includes com `__DIR__ . '/../nucleo/…'`, estilos com `../assets/css/…`. Segue as convenções e os tokens de `assets/css/painel.css`.
>
> Usa a skill **impeccable** por esta ordem: `context` → `init` (não há `PRODUCT.md`) → modo por superfície (**Persuade** no site, **Operate** na consulta por PIN) → `new-work` no ramo «superfície nova dentro de um mundo estabelecido» → cinco a sete estruturas e `concept-seed --scope surface --mode persuade`, com cartas apresentadas antes de qualquer código → contrato de direcção (`impeccable surface-brief write`) → `craft-floor` → construção → inspecção a 1440/390/320 → `detect` → `finish-reviewer` → `DESIGN.md`.
>
> Herda o mundo: marca do parafuso em cruz, família azul, tipografia de sistema, voz sóbria em pt-PT na segunda pessoa, alvos de 44 px, `prefers-reduced-motion`. O site pode ter mais temperatura do que o painel, mas é a mesma oficina. Se achares que a identidade deve ser substituída, pergunta.
>
> Tese: **o cliente vê o trabalho a acontecer, fase a fase, com o PIN do talão** — a oficina mostra em vez de prometer. Isso é a primeira dobra. Evita o hero escuro com mockup e três cartões de ícone, e o seu oposto creme + serifada + «artesanal».
>
> `frontoffice/index.php` passa a site público; a consulta muda para `frontoffice/consulta.php` com **todas** as defesas actuais. Mais `servicos.php`, `como-funciona.php`, `orcamento.php`, `contacto.php`, `perguntas.php`, `privacidade.php`, `404.php`, `robots.txt`, `sitemap.xml`, `front-comum.php`, `dados-oficina.php`, `assets/css/front.css` e `sql/migracao-solicitacoes.sql`.
>
> O formulário de orçamento tem de **gravar** (`solicitacoes`, CSRF, validação por campo, limite por IP com hash, honeypot, PRG) e ser lido em `backoffice/solicitacoes.php` — a única alteração permitida no back office. Um pedido de orçamento nunca escreve em `pedidos`.
>
> Ficha fechada: **FixIt**; repara **informática e telemóveis** (portáteis, desktops, smartphones, tablets) e nada além disso; **sem preços, prazos nem garantia afirmados**; **sem fotografias** (nem stock, nem ilustração). Morada, telefone, email e horário ficam `null` em `frontoffice/dados-oficina.php` e os blocos vazios são omitidos. **Não pares para perguntar** e não inventes nenhum destes factos.
>
> Verificação: o PHP está em `%TEMP%\php-win\php.exe` (fora do PATH) e o MySQL portátil pode estar desligado. `php -l` em tudo, servidor local, consulta de PIN, envio do formulário, capturas a 1440/390/320, `detect`, revisor, `DESIGN.md`, e o que está por preencher em `dados-oficina.php`.

## Anexo B — O que ainda falta (e é só isto)

O agente **não** vai parar para perguntar: a ficha do ponto 1.1 está fechada e o prompt proíbe-o de inventar o que falta. Fica apenas isto, para preencheres quando quiseres:

| Campo em `frontoffice/dados-oficina.php` | Estado |
| --- | --- |
| `morada`, `telefone`, `email`, `horario` | `null` — as páginas omitem os blocos até os preencheres |
| `precos`, `prazos`, `garantia` | `null` por decisão — o site não promete nenhum |
| `oferta` | já escrita no prompt: informática e telemóveis. Acrescentar categorias é editar uma linha desse ficheiro |

Se um dia quiseres afirmar preços, prazos ou garantia, muda o valor nesse ficheiro e o site passa a mostrá-lo. Não é preciso tocar em HTML — e é por isso que o prompt proíbe escrever esses valores no meio das páginas.
