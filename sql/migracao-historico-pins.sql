-- =====================================================================
--  FixIt — Migração: histórico do PIN de consulta
-- ---------------------------------------------------------------------
--  Usa este script numa base de dados fixit que já tem dados (não
--  executes o fixit.sql nesse caso: ele apaga as tabelas todas).
--
--  Cria a tabela `historico_pins`, onde fica registado o primeiro PIN de
--  cada pedido e cada troca posterior: quem e quando. Não altera
--  pedidos, clientes nem o histórico de estados.
--
--  Cada linha é um talão emitido:
--   • motivo 'inicial'   -> o PIN gerado quando o pedido foi registado
--                           (pedido-novo.php).
--   • motivo 'renovacao' -> uma substituição (pedido.php, botão "Gerar
--                           PIN novo").
--  O código em si não é copiado para aqui: para saber que PIN abre o
--  pedido hoje, lê-se pedidos.pin_consulta. O histórico serve para saber
--  quem emitiu ou anulou um talão e quando.
--
--  Este ficheiro pode ser importado mais do que uma vez: a tabela é
--  criada com IF NOT EXISTS, a coluna motivo é acrescentada só se faltar
--  e nada é apagado. Numa base onde a tabela já existia sem a coluna
--  (a versão anterior desta migração), as linhas antigas ficam com
--  'renovacao' — eram todas substituições.
--
--  Sem esta tabela o pedido.php não consegue gerar um PIN novo (a troca
--  e o registo são a mesma transação) e mostra o aviso a pedir a
--  migração. O registo de um pedido novo não é bloqueado por falta da
--  tabela: nesse caso o pedido é criado e a falha fica no log.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → escolher este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-historico-pins.sql
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

CREATE TABLE IF NOT EXISTS `historico_pins` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id`    INT UNSIGNED NOT NULL,
    `tecnico_id`   INT UNSIGNED DEFAULT NULL,
    `motivo`       ENUM('inicial', 'renovacao') NOT NULL DEFAULT 'renovacao',
    `registado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_hist_pin_pedido` (`pedido_id`, `registado_em`),
    KEY `idx_hist_pin_tecnico` (`tecnico_id`),

    CONSTRAINT `fk_hist_pin_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_hist_pin_tecnico`
        FOREIGN KEY (`tecnico_id`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- 1. Numa tabela criada pela versão anterior desta migração falta a
--    coluna motivo. Acrescenta-se com o mesmo valor por omissão do
--    CREATE; numa tabela acabada de criar não altera nada.
SET @tem_motivo := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'historico_pins'
      AND COLUMN_NAME = 'motivo'
);

SET @sql := IF(@tem_motivo = 0,
    'ALTER TABLE `historico_pins` ADD COLUMN `motivo` ENUM(''inicial'', ''renovacao'') NOT NULL DEFAULT ''renovacao'' AFTER `tecnico_id`',
    'DO 0');

PREPARE alinhar FROM @sql;
EXECUTE alinhar;
DEALLOCATE PREPARE alinhar;

-- 2. Confirmação: uma linha com a estrutura criada (ou já existente) e
--    quantas linhas ficaram marcadas como PIN inicial.
SELECT 'historico_pins' AS `tabela`,
       COUNT(*)         AS `registos_ja_guardados`,
       COALESCE(SUM(`motivo` = 'inicial'), 0) AS `pins_iniciais`
FROM `historico_pins`;
