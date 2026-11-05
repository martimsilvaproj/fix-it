# Manual de utilização — FixIt

Este manual explica como usar a aplicação FixIt no dia a dia da oficina: entrar,
registar um pedido, acompanhá-lo, responder a um pedido de orçamento do site e
gerir clientes, equipamentos e contas.

Está escrito para quem trabalha ao balcão. Não pressupõe conhecimentos de
informática além de saber usar um browser.

---

## 1. Antes de começar

### O que precisas

- Um browser actualizado (Chrome, Edge, Firefox ou Safari).
- O endereço da aplicação. Se estiver a correr no teu computador, é
  <http://127.0.0.1:8141/>.
- Uma conta com palavra-passe, criada pelo administrador da oficina.

### Os dois lados da aplicação

| Endereço | Para quem | Serve para |
| --- | --- | --- |
| `/` ou `/frontoffice/index.php` | Clientes | Ver o que a oficina faz, pedir orçamento e consultar o estado por PIN |
| `/backoffice/login.php` | Equipa da oficina | Registar e acompanhar o trabalho |

O site público não mostra nada do back office: quem entra no site só vê a
oficina e, com um PIN válido, o estado do seu próprio pedido.

---

## 2. Entrar na oficina

1. Abre `/backoffice/login.php`.
2. Escreve o **email** e a **palavra-passe**.
3. Carrega em **ENTRAR**.

Se a palavra-passe estiver errada, a página diz «Email ou palavra-passe
incorretos» e não revela qual dos dois está mal — é de propósito, para não dar
pistas a quem tenta adivinhar.

![Ecrã de entrada na oficina](screenshots/manual-conta.png)

Depois de entrares, o canto superior direito mostra as tuas iniciais e o botão
**SAIR**. O botão de sair termina a sessão nesse computador.

### Esqueceste-te da palavra-passe?

Não há recuperação por email. Pede a um administrador que use
**Utilizadores → Repor palavra-passe** na tua conta.

---

## 3. O painel

O painel é a primeira página depois do login e responde à pergunta «como está a
oficina agora?».

![Painel com pedidos em curso](screenshots/manual-painel.png)

De cima para baixo:

- **Total de pedidos**, **Pedidos ativos** e **Clientes registados**, cada um
  com a variação da semana ou do mês.
- **Pedidos por estado** — um gráfico de anéis com todos os pedidos e uma
  legenda por estado. Clicar num estado abre a lista já filtrada.
- A **fila de trabalho**, com os pedidos que estão à espera de alguém.
- **Avisos**: pedidos cujo PIN não é consultado há mais de uma semana.

Em ecrãs estreitos o menu lateral recolhe para um botão **MENU** no topo.

![Painel num telemóvel](screenshots/painel-mobile.png)

---

## 4. Registar um pedido novo

Um pedido só nasce no balcão, com o equipamento à frente — é o que garante o
PIN e o talão.

1. No painel, carrega em **+ NOVO PEDIDO**.
2. Escolhe um cliente que já existe ou escreve os dados de um novo.
3. Preenche o equipamento: tipo, marca e modelo; o número de série é opcional.
4. Descreve a avaria **como o cliente a contou**. Esta descrição é o que ele vai
   ler na consulta pública.
5. Anexa até **5 fotografias** do estado do equipamento (opcional, mas é o que
   protege a oficina numa discussão sobre riscos).
6. Carrega em **REGISTAR PEDIDO**.

No fim, a aplicação mostra o **PIN de 6 dígitos** e a referência do pedido.
Escreve o PIN no talão que entregas ao cliente: sem ele não há consulta pública.

![Pedido registado](screenshots/06-pedido-criado.png)

### Se um campo estiver mal preenchido

A página não perde o que escreveste. O campo errado fica assinalado a vermelho,
com a razão ao lado (por exemplo «Indica um NIF com 9 dígitos»), e o botão de
gravar só funciona quando tudo estiver correcto.

![Erro de validação no painel](screenshots/painel-erro.png)

---

## 5. Acompanhar os pedidos

### A lista

**Pedidos** mostra todos os pedidos, do mais recente para o mais antigo.

![Lista de pedidos](screenshots/pedidos-1440.png)

- A **pesquisa** procura ao mesmo tempo no nome do cliente, no NIF, no PIN e no
  número de série.
- Os **filtros** por estado e por técnico podem ser combinados; a lista diz
  quantos resultados encontrou.
- Clicar numa linha abre a ficha do pedido.

Quando não há resultados, a lista explica o que fazer em vez de ficar vazia:

![Sem resultados](screenshots/pedidos-sem-resultados.png)

### A ficha do pedido

![Ficha de um pedido](screenshots/pedido-1440.png)

A ficha reúne tudo o que se sabe do pedido:

- **Cliente e equipamento**, com o contacto para ligar.
- **Percurso** — as cinco fases com a data em que cada uma aconteceu.
- **Orçamento** — valor actual e histórico de todos os valores anteriores.
- **Histórico de estados**, com quem fez cada mudança.
- **PIN** — o actual, quantas vezes foi consultado e o botão para o renovar.

### Mudar o estado

1. Abre a ficha do pedido.
2. Em **Estado**, escolhe a fase seguinte.
3. Confirma.

O percurso é sempre para a frente: um pedido não volta sozinho a uma fase
anterior. Cada mudança fica registada com data e com o nome de quem a fez, e o
cliente vê-a na consulta pública no instante seguinte.

### Atribuir um técnico

Na lista de pedidos, cada linha tem o técnico responsável. Escolhe outro no
campo respectivo e a troca fica registada no histórico do pedido.

---

## 6. Orçamentos

1. Na ficha, escreve o valor em **Orçamento** e grava.
2. O cliente vê o valor na consulta por PIN e pode **aceitar** ou **recusar**.
3. Aceitar passa o pedido a **Em Reparação**; recusar passa-o a **Pronto**, para
   levantar sem ter sido mexido.
4. Cada alteração de valor fica no histórico, com data e autor — se o valor
   mudar depois de uma decisão, a decisão anterior continua legível com o número
   com que foi tomada.

Para retirar um orçamento (por exemplo, porque o diagnóstico mostrou que não
compensa), apaga o valor. O cliente volta a ver «A definir após diagnóstico».

![Orçamento preenchido](screenshots/orcamento-confirmar-1440.png)

---

## 7. Pedidos de orçamento vindos do site

Quem visita o site pode pedir orçamento. Esse pedido **não** é uma ordem de
serviço: não gera PIN nem reserva equipamento. Aparece na **caixa de entrada**.

![Caixa de entrada](screenshots/manual-caixa-entrada.png)

1. Abre **Pedidos do site** no menu.
2. Lê o pedido e contacta o cliente pelo telefone ou email indicado.
3. Marca o estado: **Nova → Em contacto → Convertida** (ou **Arquivada**, se não
   avançar).
4. Quando o cliente trouxer o equipamento, carrega em **Abrir pedido**: a
   aplicação leva os dados para o formulário de pedido novo, e tu só tens de
   confirmar o equipamento e emitir o PIN.

Uma solicitação só pode ser convertida **uma vez**. Se dois técnicos
carregarem ao mesmo tempo, o segundo recebe um aviso em vez de criar um pedido
duplicado.

---

## 8. Clientes e equipamentos

**Clientes** lista quem já passou pela oficina, com pesquisa por nome, email,
telefone, NIF e telefone.

![Clientes](screenshots/clientes-1440.png)

Ao abrir um cliente vês o contacto, o NIF e todos os equipamentos e pedidos
dele — útil quando alguém liga a perguntar «o que é que eu já aqui deixei?».

**Equipamentos** lista as máquinas, com pesquisa por marca, modelo, tipo, número
de série e nome do cliente.

![Equipamentos](screenshots/equipamentos-1440.png)

> **Limitação actual:** os dados de clientes e equipamentos não podem ser
> alterados depois de registados. Se um telefone estiver errado, o caminho é
> registar o pedido com o contacto correcto na descrição. Esta é a evolução
> mais pedida e está identificada no relatório.

---

## 9. Utilizadores (só administradores)

Só um administrador vê esta página no menu; a um técnico ela nem aparece, e
escrever o endereço à mão devolve-o ao painel.

![Utilizadores](screenshots/manual-utilizadores.png)

- **Nova conta** — nome, email, cargo e palavra-passe inicial.
- **Editar** — mudar nome, email ou cargo.
- **Repor palavra-passe** — para quem se esqueceu.
- **Terminar sessões** — fecha todas as sessões abertas dessa conta menos a
  actual.

Um técnico pode fazer tudo o que é trabalho de oficina — pedidos, clientes,
equipamentos, orçamentos — mas não mexe em contas.

---

## 10. A tua conta

**A minha conta** muda a tua própria palavra-passe e mostra quantas sessões
estão abertas.

![A minha conta](screenshots/manual-conta.png)

Ao mudar a palavra-passe, as sessões abertas noutros computadores são
terminadas; esta continua aberta.

---

## 11. O que o cliente vê

### Consulta por PIN

O cliente abre `/frontoffice/consulta.php`, escreve os 6 dígitos do talão e vê o
percurso do pedido, com datas, a avaria tal como foi descrita e o orçamento.

![Consulta por PIN](screenshots/stepper-1280.png)

Um PIN inválido não revela se o pedido existe: a resposta é sempre a mesma.

### PIN perdido

Se o cliente perder o PIN, gera-se outro na ficha do pedido. O PIN antigo deixa
de funcionar imediatamente; o histórico guarda a troca, mas não guarda o código.

---

## 12. Problemas frequentes

| O que acontece | Porque acontece | O que fazer |
| --- | --- | --- |
| «Email ou palavra-passe incorretos» | Dados errados ou conta desactivada | Confirmar o email; pedir reposição ao administrador |
| A página volta ao login sozinha | Sessão expirada por inactividade ou terminada noutro lado | Entrar outra vez |
| «Demasiados pedidos deste endereço» | Mais de 5 pedidos de orçamento em 10 minutos a partir do mesmo IP | Esperar 10 minutos |
| O gráfico do painel não aparece | Sem acesso à Internet para carregar a biblioteca do gráfico | Usar a legenda ao lado do gráfico, que tem os mesmos números |
| A consulta diz que o PIN não existe | PIN trocado ou mal copiado | Confirmar o PIN novo na ficha do pedido |
| «Falta corrigir alguns campos» | Validação do formulário | Ver os campos assinalados a vermelho |
| Um pedido não pode ser convertido | Já foi convertido antes | Abrir o pedido que nasceu dessa solicitação |

---

## 13. Boas práticas no balcão

- **Descreve a avaria com as palavras do cliente.** É o texto que ele vai ler na
  consulta e o que evita discussões à entrega.
- **Fotografa o equipamento à entrada.** Riscos e mossas ficam documentados.
- **Entrega o PIN por escrito.** Sem ele o cliente não consegue acompanhar.
- **Regista o orçamento antes de reparar.** É a regra da casa e está no site.
- **Muda o estado no momento em que acontece.** O cliente vê a mudança no
  instante; um estado atrasado gera telefonemas.
