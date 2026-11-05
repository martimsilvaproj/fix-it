# Apresentação — FixIt

Guião para a apresentação à turma (10–12 minutos + perguntas). Cada secção tem
o que dizer e o que mostrar no ecrã.

---

## Diapositivo 1 — A oficina e o problema (1 min)

**Dizer:** «A FixIt é uma oficina de bairro que repara portáteis, desktops,
telemóveis e tablets. O cliente traz o equipamento ao balcão e volta para o
levantar. O problema não é reparar — é o telefone a tocar a perguntar “já
está?”.»

**Mostrar:** o site público (`/`).

---

## Diapositivo 2 — A solução em uma frase (30 s)

**Dizer:** «Cada equipamento entra com um talão e um PIN de seis dígitos. O
cliente consulta o estado quando quiser, e a oficina tem o registo completo de
cada fase.»

**Mostrar:** a secção «Já tens PIN?» da página inicial.

---

## Diapositivo 3 — Quem usa e o que faz (1 min)

Quatro utilizadores, quatro necessidades: cliente particular, pequena empresa,
técnico e administrador.

**Mostrar:** o manual aberto na tabela de utilizadores, ou o menu do back office
com os itens que cada perfil vê.

---

## Diapositivo 4 — Demonstração: registar um pedido (2 min)

1. Painel → **+ NOVO PEDIDO**.
2. Cliente existente (Marta Ferreira) e equipamento.
3. Descrição da avaria com as palavras do cliente.
4. Gravar → aparece o **PIN**.

**Dizer:** «Este PIN é o que o cliente leva no talão. É o único dado de que
precisa para acompanhar a reparação.»

---

## Diapositivo 5 — Demonstração: o percurso (2 min)

1. Abrir a ficha do pedido e mudar o estado para **Em Diagnóstico**.
2. Noutro separador, abrir `/frontoffice/consulta.php` e introduzir o PIN.
3. Mostrar a fase nova com a data, e o orçamento.

**Dizer:** «A mudança que fiz há dez segundos já está do lado do cliente. Não há
telefonema nem email.»

---

## Diapositivo 6 — Demonstração: orçamento e decisão (1,5 min)

1. Escrever o orçamento na ficha.
2. Na consulta pública, o cliente **aceita**.
3. Voltar à ficha: o pedido passou a **Em Reparação**, e o histórico diz quem
   decidiu e com que valor.

**Dizer:** «A decisão fica registada com o valor que estava à frente do cliente.
Se o valor mudar depois, a decisão anterior continua legível.»

---

## Diapositivo 7 — Demonstração: pedido do site (1,5 min)

1. No site, **Pedir orçamento** com dados de exemplo.
2. No back office, **Pedidos do site** → abrir → **Abrir pedido**.
3. Mostrar que a conversão só pode acontecer uma vez.

**Dizer:** «Um pedido do site não é uma ordem de serviço: não gera PIN nem
reserva equipamento. Só quando o cliente traz a máquina é que nasce o pedido.»

---

## Diapositivo 8 — A base de dados (2 min)

**Mostrar:** o diagrama de relações (clientes → equipamentos → pedidos, e as
tabelas de histórico à volta).

**Dizer:** «Treze tabelas. Três decisões que vale a pena explicar:

- O estado actual fica em `pedidos`; o percurso fica em `historico_pedidos`, uma
  linha por mudança, escritas por triggers — nenhuma página as insere à mão.
- Quando um técnico é eliminado, o pedido **não** desaparece: `SET NULL`, porque
  o pedido é histórico e tem valor de garantia.
- O histórico de PINs não guarda o código. Guarda quem o trocou e quando — um
  código que já não abre nada não deve ficar registado.»

---

## Diapositivo 9 — Interface (1,5 min)

**Mostrar:** painel a 1440 px e a 390 px, lado a lado.

**Dizer:** «A identidade vem do talão: cantos rectos, réguas de 2 px,
monoespaçadas em maiúsculas, sombra de carimbo. Sem imagens decorativas, sem
bibliotecas, sem fontes externas. O menu recolhe em ecrã estreito e as tabelas
passam a cartões.»

**Mostrar:** a lista sem resultados, para provar que o estado vazio explica o que
fazer.

---

## Diapositivo 10 — Testes (1,5 min)

**Mostrar:** a saída de `php testes/correr.php` («Tudo certo: 50 verificações»).

**Dizer:** «50 verificações automáticas, mais uma bateria HTTP contra o servidor
a correr: dez páginas a 200 sem avisos, técnico bloqueado em Utilizadores,
cabeçalhos de segurança em todas as páginas, conversão duplicada recusada.

E o que **não** está testado, que também é preciso dizer: o upload de
fotografias, a impressão do talão e as conversões simultâneas.»

---

## Diapositivo 11 — Uso de IA (1,5 min)

**Dizer:** «Usei um assistente de código para refactorizar a moldura das páginas,
rever segurança e escrever documentação. Dois exemplos concretos e um erro:

- A moldura estava repetida em sete páginas; passou a uma função única, com
  comparação do HTML antes e depois para garantir que nada mudava.
- O limite do formulário público contava as tentativas falhadas — cinco gralhas
  bloqueavam o cliente dez minutos. Passou a contar só pedidos gravados.
- E um erro meu: uma substituição automática sugerida pela IA estragou dois
  ficheiros. O `php -l` apanhou-os e corrigi à mão. A lição foi verificar sempre
  depois de editar em lote.»

---

## Diapositivo 12 — Limitações e próximos passos (1 min)

**Dizer:** «O que falta, por ordem de valor: editar clientes e equipamentos já
registados; tirar o gráfico da dependência de um CDN; exportar a lista para CSV;
avisar o cliente por email quando o orçamento sai.»

**Fechar:** «O que o sistema garante é simples: o cliente sabe sempre em que
ponto está, e a oficina sabe sempre quem fez o quê e quando.»

---

## Preparação para perguntas

| Pergunta provável | Resposta curta |
| --- | --- |
| Porque PHP e não um framework? | O ambiente escolar já tem PHP e MySQL; sem framework a instalação é copiar a pasta, e não há dependências a explicar. |
| Porque um PIN e não conta de cliente? | O cliente não quer criar conta para saber se o portátil está pronto. O PIN está no talão e não precisa de email. |
| Como impedes SQL injection? | Todas as consultas usam PDO com parâmetros; não há concatenação de valores em SQL. |
| Como impedes conversões duplicadas? | Três defesas: `SELECT … FOR UPDATE`, `UPDATE` condicionado a `pedido_id IS NULL` e índice `UNIQUE`. |
| O que acontece se apagares um técnico? | O pedido mantém-se, fica sem técnico (`SET NULL`), e o histórico sobrevive. |
| Porque não há paginação? | O volume é o de uma oficina. Está identificado como melhoria, não como esquecimento. |
| Que parte fez a IA? | Refactorização, revisão de segurança e documentação. As decisões de produto e a validação foram minhas — e há um exemplo de uma sugestão que recusei. |
