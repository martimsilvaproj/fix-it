-- =====================================================================
--  FixIt — Migração: histórico de alterações ao orçamento
-- ---------------------------------------------------------------------
--  Usa este script numa base de dados fixit que já tem dados (não
--  executes o fixit.sql nesse caso: ele apaga as tabelas todas).
--
--  Cria apenas a tabela `historico_orcamentos`, onde pedido.php regista
--  cada alteração ao valor do orçamento, com o valor anterior, o novo e
--  o técnico. Não altera pedidos, clientes nem o histórico de estados.
--
--  Cada linha representa uma alteração: valor_anterior e valor_novo são
--  opcionais. NULL à esquerda é "ainda por definir"; NULL à direita é
--  uma remoção (o orçamento volta a "A definir após diagnóstico").
--
--  Este ficheiro pode ser importado mais do que uma vez, sobre uma base
--  nova, sobre a tabela criada pela primeira versão da migração (onde
--  valor_novo era obrigatório e o CHECK só olhava para os valores um a
--  um) ou já corrigida. Não apaga registos.
--
--  No fim, o CHECK `chk_hist_orc_valores` fica igual ao do fixit.sql,
--  com as três condições: nenhum valor negativo, e pelo menos um dos
--  dois valores preenchido. Uma linha sem valor anterior nem novo não é
--  uma alteração e passa a ser recusada pela base, tal como no fixit.sql.
--
--  O DDL do fim é montado com PREPARE porque MySQL e MariaDB removeram
--  um CHECK com palavras diferentes — MySQL aceita `DROP CHECK`, o
--  MariaDB só aceita `DROP CONSTRAINT` — e o MySQL não aceita IF EXISTS
--  em nenhuma das duas. A existência é consultada em
--  information_schema.TABLE_CONSTRAINTS, que os dois expõem.
--
--  Se houver linhas sem valor nenhum (só possíveis por inserção manual:
--  o pedido.php grava sempre um dos valores), o ALTER não é executado e
--  o último SELECT mostra o aviso com a contagem, em vez de deixar a
--  tabela sem CHECK. O fixit.sql também não aceita essas linhas.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → escolher este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-historico-orcamentos.sql
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

CREATE TABLE IF NOT EXISTS `historico_orcamentos` (
    `id`             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `pedido_id`      INT UNSIGNED   NOT NULL,
    `valor_anterior` DECIMAL(10, 2) DEFAULT NULL,
    `valor_novo`     DECIMAL(10, 2) DEFAULT NULL,
    `tecnico_id`     INT UNSIGNED   DEFAULT NULL,
    `registado_em`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_hist_orc_pedido` (`pedido_id`, `registado_em`),
    KEY `idx_hist_orc_tecnico` (`tecnico_id`),

    CONSTRAINT `fk_hist_orc_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_hist_orc_tecnico`
        FOREIGN KEY (`tecnico_id`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT `chk_hist_orc_valores`
        CHECK ((`valor_novo` IS NULL OR `valor_novo` >= 0)
               AND (`valor_anterior` IS NULL OR `valor_anterior` >= 0)
               AND (`valor_novo` IS NOT NULL OR `valor_anterior` IS NOT NULL))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- 1. Torna a remoção possível numa tabela criada pela primeira versão
--    da migração. Numa tabela acabada de criar não altera nada.
ALTER TABLE `historico_orcamentos`
    MODIFY `valor_novo` DECIMAL(10, 2) DEFAULT NULL;

-- 2. Alinha o CHECK com o do fixit.sql. A cláusula é a mesma do CREATE
--    acima; o DROP só entra no ALTER quando a restrição já existe.
SET @chk := 'chk_hist_orc_valores';

SET @palavra := IF(VERSION() LIKE '%MariaDB%', 'CONSTRAINT', 'CHECK');

SET @existe := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'historico_orcamentos'
      AND CONSTRAINT_TYPE = 'CHECK'
      AND CONSTRAINT_NAME = @chk
);

SET @invalidas := (
    SELECT COUNT(*)
    FROM `historico_orcamentos`
    WHERE `valor_anterior` IS NULL AND `valor_novo` IS NULL
);

SET @sql := IF(@invalidas > 0, 'DO 0', CONCAT(
    'ALTER TABLE `historico_orcamentos` ',
    IF(@existe > 0, CONCAT('DROP ', @palavra, ' ', @chk, ', '), ''),
    'ADD CONSTRAINT ', @chk, ' CHECK (',
    '(`valor_novo` IS NULL OR `valor_novo` >= 0)',
    ' AND (`valor_anterior` IS NULL OR `valor_anterior` >= 0)',
    ' AND (`valor_novo` IS NOT NULL OR `valor_anterior` IS NOT NULL))'
));

PREPARE alinhar FROM @sql;
EXECUTE alinhar;
DEALLOCATE PREPARE alinhar;

-- 3. Só devolve uma linha quando há dados que impedem o CHECK novo.
SELECT 'ATENCAO' AS `aviso`,
       COUNT(*) AS `linhas_sem_valores`,
       'Estas linhas nao tem valor_anterior nem valor_novo: o CHECK ficou como estava. Corrige-as (valor anterior, valor novo, ou ambas as colunas) e volta a importar.' AS `o_que_fazer`
FROM `historico_orcamentos`
WHERE `valor_anterior` IS NULL AND `valor_novo` IS NULL
HAVING COUNT(*) > 0;
