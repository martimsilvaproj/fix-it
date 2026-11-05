-- =====================================================================
--  FixIt — Dados fictícios de demonstração
-- ---------------------------------------------------------------------
--  Preenche a oficina com clientes, equipamentos, pedidos em várias
--  fases, orçamentos, decisões do cliente e pedidos do site, para a
--  demonstração à turma não correr sobre uma base quase vazia.
--
--  NÃO APAGA NADA. Todos os INSERT estão guardados por chaves naturais
--  (NIF do cliente, número de série do equipamento, PIN do pedido, par
--  pedido/estado no histórico), por isso pode ser importado sobre uma
--  base já com dados — e mais do que uma vez — sem duplicar linhas.
--
--  Como aplicar:
--     mysql -u root fixit < sql/dados-demonstracao.sql
--  ou phpMyAdmin → base fixit → Importar.
--
--  Os nomes, NIF, telefones e avarias são inventados. Os NIF respeitam
--  o CHECK da tabela (9 dígitos) e os PIN são únicos.
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

-- ---------------------------------------------------------------------
--  1. Clientes
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `clientes` (`nome`, `email`, `telefone`, `nif`, `criado_em`) VALUES
    ('Marta Ferreira',        'marta.ferreira@exemplo.pt',   '912 345 678', '245678901', '2026-08-12 10:20:00'),
    ('João Almeida',          'joao.almeida@exemplo.pt',     '934 112 907', '213456789', '2026-08-27 15:05:00'),
    ('Silva & Filhos, Lda',   'geral@silvafilhos.exemplo.pt','217 664 230', '507654321', '2026-09-03 09:40:00'),
    ('Rita Nunes',            NULL,                          '968 771 344', '226789012', '2026-09-18 18:12:00'),
    ('Paulo Cardoso',         'p.cardoso@exemplo.pt',        '916 208 554', '234567890', '2026-10-02 11:30:00'),
    ('Clínica Lumiar',        'manutencao@clinicalumiar.pt', '210 998 441', '509876543', '2026-10-14 14:55:00');

-- ---------------------------------------------------------------------
--  2. Equipamentos (ligados ao cliente pelo NIF)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Portátil', 'Lenovo IdeaPad 3 15IIL', 'PF2XK91A'
  FROM clientes c WHERE c.nif = '245678901';

INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Telemóvel', 'Samsung Galaxy A54', 'R58T91LMQ3P'
  FROM clientes c WHERE c.nif = '245678901';

INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Desktop', 'HP EliteDesk 800 G4', 'CZC8124K7T'
  FROM clientes c WHERE c.nif = '213456789';

INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Portátil', 'Dell Latitude 5420', 'J7K3M92'
  FROM clientes c WHERE c.nif = '507654321';

INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Desktop', 'Asus ExpertCenter D500', 'ASD500771'
  FROM clientes c WHERE c.nif = '507654321';

INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Tablet', 'iPad 9.ª geração', 'DMPX2L9KQ1'
  FROM clientes c WHERE c.nif = '226789012';

INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Portátil', 'MacBook Air M1', 'C02F9KQMD6M'
  FROM clientes c WHERE c.nif = '234567890';

INSERT IGNORE INTO `equipamentos` (`cliente_id`, `tipo`, `marca_modelo`, `numero_serie`)
SELECT c.id, 'Desktop', 'Lenovo ThinkCentre M720', 'PC1K9LM2'
  FROM clientes c WHERE c.nif = '509876543';

-- ---------------------------------------------------------------------
--  3. Pedidos
--  Três em trabalho, dois prontos, três entregues, dois recebidos.
--  O trigger `trg_pedidos_estado_inicial` regista sozinho a primeira
--  linha do histórico; as fases anteriores de cada pedido são
--  acrescentadas no passo 4, com datas coerentes.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'ana@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'martim@fixit.pt'),
       'Não arranca. A luz de carga acende mas o ecrã fica negro, já se tentou outro carregador.',
       'Em Reparacao', 89.90, '482913', '2026-10-27 09:15:00', NULL
  FROM equipamentos e WHERE e.numero_serie = 'PF2XK91A';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'rui@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'ana@fixit.pt'),
       'Ecrã rachado no canto inferior direito; o toque funciona mas há falhas na zona danificada.',
       'Em Diagnostico', NULL, '517204', '2026-11-02 16:40:00', NULL
  FROM equipamentos e WHERE e.numero_serie = 'R58T91LMQ3P';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'ana@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'martim@fixit.pt'),
       'Liga mas reinicia sozinho passados poucos minutos. Suspeita de sobreaquecimento.',
       'Pronto', 64.50, '630481', '2026-10-21 11:05:00', NULL
  FROM equipamentos e WHERE e.numero_serie = 'CZC8124K7T';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'rui@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'martim@fixit.pt'),
       'Bateria não carrega. Fica ligado à corrente e desliga-se assim que se tira o cabo.',
       'Entregue', 119.00, '294756', '2026-09-08 14:30:00', '2026-09-16 17:20:00'
  FROM equipamentos e WHERE e.numero_serie = 'J7K3M92';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'ana@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'ana@fixit.pt'),
       'Não dá imagem ao monitor externo pela porta HDMI. Já se testou outro cabo.',
       'Entregue', 45.00, '845130', '2026-09-22 10:10:00', '2026-10-01 12:45:00'
  FROM equipamentos e WHERE e.numero_serie = 'ASD500771';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'rui@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'martim@fixit.pt'),
       'Ecrã com riscos e bateria a durar menos de duas horas.',
       'Entregue', 95.00, '361927', '2026-08-19 15:00:00', '2026-08-28 11:15:00'
  FROM equipamentos e WHERE e.numero_serie = 'DMPX2L9KQ1';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'ana@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'martim@fixit.pt'),
       'Teclado com teclas que não respondem depois de um derrame de líquido.',
       'Em Reparacao', 78.30, '708254', '2026-10-30 08:50:00', NULL
  FROM equipamentos e WHERE e.numero_serie = 'C02F9KQMD6M';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       NULL,
       (SELECT id FROM utilizadores WHERE email = 'ana@fixit.pt'),
       'Computador da receção muito lento a abrir os programas. Pedido de limpeza e revisão.',
       'Recebido', NULL, '129683', '2026-11-04 17:25:00', NULL
  FROM equipamentos e WHERE e.numero_serie = 'PC1K9LM2';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       (SELECT id FROM utilizadores WHERE email = 'rui@fixit.pt'),
       (SELECT id FROM utilizadores WHERE email = 'martim@fixit.pt'),
       'Portátil com a dobradiça do lado esquerdo partida; a tampa abre desalinhada.',
       'Pronto', 52.00, '956072', '2026-10-24 13:35:00', NULL
  FROM equipamentos e WHERE e.numero_serie = 'PF2XK91A';

INSERT IGNORE INTO `pedidos`
    (`equipamento_id`, `tecnico_id`, `registado_por`, `descricao_avaria`,
     `estado`, `orcamento`, `pin_consulta`, `data_entrada`, `data_conclusao`)
SELECT e.id,
       NULL,
       (SELECT id FROM utilizadores WHERE email = 'martim@fixit.pt'),
       'Deixou de carregar depois de uma trovoada. Cheira a queimado junto à ficha.',
       'Recebido', NULL, '273418', '2026-11-05 09:05:00', NULL
  FROM equipamentos e WHERE e.numero_serie = 'C02F9KQMD6M';
