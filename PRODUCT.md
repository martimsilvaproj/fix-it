# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Dois públicos, confirmados pelo dono da oficina:

- **Particulares** com um portátil, desktop, smartphone ou tablet avariado. Chegam ao balcão com o equipamento na mão e uma pergunta prática: «isto tem arranjo, e quanto custa?».
- **Pequenas empresas e escritórios** com vários equipamentos. Precisam de fatura, de saber quando o equipamento volta e de não deixar o escritório parado.

Os dois usam o mesmo balcão e o mesmo talão. Não há contratos, SLA nem fluxo empresarial separado.

## Product Purpose

Registar uma reparação e deixar o cliente acompanhá-la sem telefonar. O sistema já faz isto no back office (registo, estados, orçamento, histórico) e na consulta pública por PIN. O site público existe para que quem ainda não é cliente perceba o que a oficina faz e peça orçamento, e para que quem já deixou um equipamento reconheça o percurso que vai acompanhar.

Sucesso: o visitante percebe em segundos que a oficina repara informática e telemóveis, e sai com um pedido de orçamento feito ou com a certeza de que pode trazer o equipamento ao balcão.

## Positioning

O cliente vê o trabalho a acontecer: fase a fase, com a data de entrada em cada fase, o orçamento e a avaria tal como foi descrita na receção, através do PIN de 6 dígitos do talão. Uma oficina vizinha copia a lista de serviços e os preços; não copia um percurso que já está a ser registado a cada mudança de estado.

## Operating Context

- O equipamento entra no balcão, é descrito e fotografado, e o cliente recebe um talão com um PIN de 6 algarismos.
- O percurso tem cinco fases: Recebido → Em diagnóstico → Em reparação → Pronto → Entregue.
- Cada mudança de estado fica registada com data e técnico; o orçamento tem histórico de valores.
- O orçamento é comunicado antes de reparar. Um pedido de orçamento feito no site **não** é uma ordem de serviço: um pedido só nasce no balcão, com equipamento à frente e PIN emitido.
- A oficina é local: o cliente traz e levanta o equipamento. Não há envio nem recolha.

## Capabilities and Constraints

**O que existe:** back office em PHP (login, painel com métricas e gráfico, lista de pedidos com filtros e ordenações, ficha com histórico e orçamento, registo de pedido novo com até 5 fotografias, clientes, equipamentos, utilizadores, conta); consulta pública por PIN; base MySQL com 13 tabelas, dois triggers de histórico, histórico de PINs, consultas públicas, trocas de técnico e avisos.

**Oferta confirmada:** informática e telemóveis — portáteis, desktops, smartphones e tablets. Nada além disso; não existe lista de exclusões.

**Factos por preencher (não fabricar):** morada, telefone, email, horário.

**Factos deliberadamente ausentes:** preços, prazos de reparação, garantia. O site não os promete.

**Stack:** PHP 8 puro com `declare(strict_types=1)`, PDO com prepared statements, MySQL/MariaDB. Sem Composer, sem npm, sem framework, sem build step. Código partilhado em `nucleo/`, estilos em `assets/css/`, uploads em `uploads/`, e `frontoffice/` + `backoffice/` como as duas superfícies. Páginas novas usam `__DIR__ . '/../nucleo/…'` e `../assets/css/…`.

## Brand Commitments

- Nome **FixIt**, descritor «Assistência técnica».
- Marca: cabeça de parafuso em cruz, desenhada em SVG de traço.
- Família cromática azul institucional (`--azul-900` #0C2038 a `--azul-50` #F2F7FC) sobre cinzas azulados.
- Tipografia de sistema, sem pedidos de rede.
- Voz sóbria e precisa, português de Portugal, segunda pessoa do singular na página pública; sem superlativos nem pontos de exclamação em série.
- Alvos de toque a partir de 44 px; movimento respeita `prefers-reduced-motion`.

## Evidence on Hand

- Base de dados com esquema real e dados de demonstração: pedidos com estado, orçamento, avaria descrita, datas e histórico por fase.
- O `frontoffice/index.php` actual é a prova viva do percurso: stepper de 5 fases com datas, detalhes e orçamento.
- `screenshots/` com capturas das revisões do back office a 320, 360, 390, 768, 1024 e 1440 px.

**Ausências que o trabalho futuro não pode fabricar:** não há fotografias da oficina, nem testemunhos de clientes, nem avaliações, nem logótipos de marcas assistidas, nem números de reparações, nem certificações.

## Product Principles

1. **Mostrar, não prometer.** O que se afirma no site tem de ser verificável no sistema ou no balcão.
2. **O talão é o contrato.** As palavras do site são as palavras do papel que o cliente leva: pedido, talão, PIN, orçamento, pronto, entregue.
3. **Quem já é cliente não é tratado como visitante.** O caminho para consultar o estado está sempre a um clique.
4. **Silêncio honesto vale mais do que um número.** Sem dado, o bloco desaparece — não se preenche com um valor plausível.
5. **O back office é a verdade.** O site não inventa um estado, um preço ou um prazo que o sistema não conhece.

## Accessibility & Inclusion

WCAG 2.2 AA: contraste de 4,5:1 no corpo, foco sempre visível, navegação por teclado completa, um `h1` por página, alvos a partir de 44 px, texto a 200% sem perda de conteúdo e movimento reduzido respeitado.
