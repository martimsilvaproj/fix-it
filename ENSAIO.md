# Ensaio da apresentação — FixIt

Guião cronometrado para treinar a demonstração antes de a fazer à turma.
Tempo-alvo: **10 a 12 minutos**. Tudo o que aqui aparece existe na base de
demonstração; nada tem de ser criado de novo.

---

## Antes de começar (5 minutos antes, não à frente da turma)

- [ ] Servidor a responder: abrir <http://127.0.0.1:8141/> e ver a página inicial.
- [ ] Base a responder: entrar em `/backoffice/login.php` com
      `martim@fixit.pt` / `FixIt!2026`. Se o login falhar, é a base que está em baixo.
- [ ] **Duas abas abertas**, por esta ordem: (1) site público, (2) oficina.
- [ ] Zoom do browser a 100% e janela maximizada.
- [ ] Notificações do sistema desligadas (Windows: Assistência de foco).
- [ ] Fechar tudo o que possa mostrar mensagens: email, chat, atualizações.
- [ ] Saber de cor **um PIN**: `482913` (Marta Ferreira, Lenovo IdeaPad, em reparação).

**Estado esperado da base:** 11 pedidos, 7 clientes, 9 equipamentos, 1 fotografia.

---

## 0:00 – 1:00 · A empresa e o problema

**No ecrã:** a página inicial do site (aba 1).

**Diz:** «A FixIt é uma oficina de bairro. O cliente traz o equipamento, descreve
a avaria e volta para o levantar. O problema não é reparar — é o telefone a
tocar a perguntar "já está?". A aplicação resolve isso com um PIN de seis
dígitos no talão.»

**Gestos:** apontar para a caixa «JÁ TENS PIN?» e para as cinco fases
(RECEBIDO → ENTREGUE) que estão na página.

---

## 1:00 – 2:00 · O percurso visto pelo cliente

**No ecrã:** aba 1 → `Consulta` (`/frontoffice/consulta.php`).

**Gestos:** escrever `482913` → **CONSULTAR ESTADO**.

**Diz:** «Sem conta, sem password, sem email: seis dígitos e o cliente vê o
estado real, a data de cada fase, a avaria tal como foi descrita e o orçamento.»

**Não fazer:** não escrever um PIN errado aqui — a mensagem é a mesma, mas gasta
tempo e não acrescenta.

---

## 2:00 – 3:00 · A oficina por dentro

**No ecrã:** aba 2 → login → **Painel**.

**Diz:** «Do lado da oficina, o painel responde a "como está a oficina agora?":
11 pedidos, 8 ativos, 7 clientes, a divisão por estado e a fila de trabalho.»

**Gestos:** apontar para os três cartões e para o gráfico. **Não clicar** nas
fatias se o tempo estiver curto — cada clique abre uma lista nova.

---

## 3:00 – 5:00 · Registar um pedido (o momento principal)

**No ecrã:** **+ NOVO PEDIDO**.

1. Cliente: escolher **Marta Ferreira** na lista.
2. Equipamento: tipo `Portátil`, marca `HP Pavilion 14`, série `ENSAIO-1`.
3. Avaria: escrever uma frase com as palavras do cliente — por exemplo
   «Não liga. A luz acende e o ecrã fica negro.»
4. **REGISTAR PEDIDO**.

**Diz:** «O PIN é gerado aqui e é o que o cliente leva no talão. A partir deste
momento ele pode acompanhar sozinho.»

**Se falhar:** recarregar a página e voltar a submeter. Se o erro for num campo,
lê-lo em voz alta — é a validação a funcionar, não uma avaria.

**Limpar depois:** este pedido fica na base. Antes da apresentação real,
apagá-lo (ou usar sempre o mesmo número de série e apagá-lo no fim).

---

## 5:00 – 6:30 · Mudar o estado e ver o reflexo

**No ecrã:** a ficha do pedido que acabou de criar → **Estado** → `Em Diagnóstico`
→ **GUARDAR ESTADO**.

**Gestos:** mudar para a aba 1, consultar o PIN novo, mostrar a fase com data.

**Diz:** «A mudança que fiz há dez segundos já está do lado do cliente. Não há
telefonema nem email.»

---

## 6:30 – 8:00 · Orçamento e decisão do cliente

1. Na ficha (aba 2), escrever `89,90` em **Orçamento** → guardar.
2. Aba 1: consultar o PIN outra vez → o valor aparece com **aceitar** e **recusar**.
3. Carregar em **aceitar**.
4. Aba 2: recarregar a ficha → o pedido está **Em Reparação**, e o histórico diz
   quem decidiu e com que valor.

**Diz:** «A decisão fica registada com o valor que estava à frente do cliente.
Se o valor mudar, a decisão anterior continua legível.»

---

## 8:00 – 9:00 · O pedido que veio do site

**No ecrã:** **Pedidos do site** (caixa de entrada).

**Diz:** «Um pedido do site não é uma ordem de serviço: não gera PIN nem reserva
equipamento. Só quando o cliente traz a máquina é que nasce o pedido.»

**Gestos:** abrir a solicitação que está em «Nova» e mostrar o botão
**Abrir pedido** — **não converter** durante a apresentação, para não deixar
lixo na base.

---

## 9:00 – 10:00 · Os dados por trás

**No ecrã:** a ficha de um pedido, a zona de **Histórico**, e a galeria de
**Fotografias**.

**Diz:** «Cada mudança de estado é escrita por um trigger na base, não pela
página: nenhum caminho escapa ao histórico. E a fotografia da receção fica
ligada ao pedido.»

---

## 10:00 – 11:00 · Testes e o que falta

**Diz:** «31 verificações automáticas e uma bateria HTTP contra o servidor.
E o que **não** está feito, porque também é preciso dizê-lo: clientes e
equipamentos ainda não se editam, e o gráfico depende de um CDN.»

---

## Perguntas — respostas de 20 segundos

| Pergunta | Resposta |
| --- | --- |
| Porque PHP puro? | O ambiente escolar tem PHP e MySQL; sem framework, instalar é copiar a pasta. |
| Porque PIN e não conta? | O cliente não quer criar conta para saber se o portátil está pronto. |
| SQL injection? | PDO com parâmetros em todas as consultas; zero concatenações. |
| Conversão duplicada? | Três defesas: `FOR UPDATE`, `UPDATE` condicionado, índice único. |
| Apagar um técnico? | O pedido fica sem técnico, não desaparece. |
| O que fez a IA? | Refactorização, revisão de segurança e documentação; houve uma sugestão que recusei. |

---

## Erros que já conheço (e como sair deles em 5 segundos)

| Sintoma | Causa | Saída |
| --- | --- | --- |
| «Email ou palavra-passe incorretos» | Sessão expirada | Entrar outra vez |
| Página volta ao login | Sessão terminada | Entrar outra vez |
| «Falta corrigir alguns campos» | Campo inválido | Ler o campo assinalado |
| Gráfico em branco | Sem rede para o CDN | Falar da legenda ao lado, que tem os mesmos números |
| Site não abre | Servidor parado | Ver `README.md`, secção «Instalar» |
