-- =====================================================================
--  FixIt — caixa de entrada: conversão de um pedido do site em pedido
-- ---------------------------------------------------------------------
--  Acrescenta a `solicitacoes` o que falta para fechar o circuito entre
--  o formulário público e o balcão:
--
--    pedido_id      o pedido de reparação que nasceu desta solicitação.
--                   NULL enquanto está por converter. Índice UNIQUE: um
--                   pedido nunca é a origem de duas solicitações, e a
--                   base de dados recusa a segunda conversão mesmo que
--                   dois técnicos carreguem em «Abrir pedido» ao mesmo
--                   tempo.
--    convertida_em  quando foi convertida. A data da conversão não se
--                   deduz do pedido (podem passar dias) e o histórico da
--                   caixa de entrada precisa dela.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-solicitacoes-fluxo.sql
--
--  É idempotente: cada passo pergunta primeiro ao information_schema se
--  o objecto já existe. Pode ser importado sobre uma base já migrada sem
--  tocar em dados. O MySQL 8 não tem ADD COLUMN IF NOT EXISTS, daí a
--  dança com o PREPARE.
--
--  Ordem: correr depois de sql/migracao-solicitacoes.sql (é esse que
--  cria a tabela).
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

-- ---------------------------------------------------------------------
--  1. solicitacoes.pedido_id
-- ---------------------------------------------------------------------
SET @existe = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'solicitacoes'
       AND COLUMN_NAME  = 'pedido_id'
);

SET @sql = IF(
    @existe = 0,
    'ALTER TABLE `solicitacoes` ADD COLUMN `pedido_id` INT UNSIGNED DEFAULT NULL AFTER `estado`',
    'SELECT 1'
);

PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

-- ---------------------------------------------------------------------
--  2. solicitacoes.convertida_em
-- ---------------------------------------------------------------------
SET @existe = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'solicitacoes'
       AND COLUMN_NAME  = 'convertida_em'
);

SET @sql = IF(
    @existe = 0,
    'ALTER TABLE `solicitacoes` ADD COLUMN `convertida_em` DATETIME DEFAULT NULL AFTER `pedido_id`',
    'SELECT 1'
);

PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

-- ---------------------------------------------------------------------
--  3. Um pedido, uma solicitação
--  O UNIQUE é a última defesa contra a conversão duplicada: a página
--  bloqueia a linha com SELECT … FOR UPDATE e condiciona o UPDATE a
--  `pedido_id IS NULL`, mas dois pedidos HTTP em paralelo só ficam
--  mesmo impossíveis aqui.
-- ---------------------------------------------------------------------
SET @existe = (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'solicitacoes'
       AND INDEX_NAME   = 'uq_solicitacoes_pedido'
);

SET @sql = IF(
    @existe = 0,
    'ALTER TABLE `solicitacoes` ADD UNIQUE KEY `uq_solicitacoes_pedido` (`pedido_id`)',
    'SELECT 1'
);

PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

-- ---------------------------------------------------------------------
--  4. Chave estrangeira para pedidos
--  ON DELETE SET NULL: se um pedido for apagado, a solicitação não
--  desaparece com ele (é correspondência do cliente) — perde só a
--  ligação. O estado fica «Convertida», porque foi o que aconteceu;
--  quem precisar de a reabrir muda o estado à mão.
-- ---------------------------------------------------------------------
SET @existe = (
    SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME        = 'solicitacoes'
       AND CONSTRAINT_NAME   = 'fk_solicitacoes_pedido'
       AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);

SET @sql = IF(
    @existe = 0,
    'ALTER TABLE `solicitacoes`
       ADD CONSTRAINT `fk_solicitacoes_pedido`
       FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
       ON DELETE SET NULL
       ON UPDATE CASCADE',
    'SELECT 1'
);

PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

-- ---------------------------------------------------------------------
--  Conferência: deve devolver as duas colunas novas e o índice.
-- ---------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'solicitacoes'
 ORDER BY ORDINAL_POSITION;

SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'solicitacoes';
