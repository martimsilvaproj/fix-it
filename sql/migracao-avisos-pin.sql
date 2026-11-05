-- =====================================================================
--  FixIt — Migração: avisos quando o cliente não abre o PIN
-- ---------------------------------------------------------------------
--  Usa este script numa base de dados fixit que já tem dados (não
--  executes o fixit.sql nesse caso: ele apaga as tabelas todas).
--
--  Faz duas coisas:
--   1. Acrescenta pedidos.registado_por — quem registou o pedido, lido
--      da sessão em pedido-novo.php. Até aqui ninguém guardava isso: o
--      tecnico_id é o responsável pela reparação, não quem abriu a
--      ficha, e na criação está sempre NULL.
--   2. Cria a tabela avisos_pin, onde o painel escreve um aviso por
--      pedido e por dia a quem registou o pedido, quando o cliente não
--      abre o PIN há mais de uma semana.
--
--  Os pedidos que já existem ficam com registado_por NULL: não há
--  maneira de saber quem os registou, e inventar um autor seria pior do
--  que assumir que não se sabe. Sem autor não há destinatário, e por
--  isso esses pedidos não geram avisos — a contagem a âmbar da lista
--  continua a assinalá-los.
--
--  Este ficheiro pode ser importado mais do que uma vez: a coluna só é
--  criada se faltar (verificação em information_schema) e a tabela usa
--  IF NOT EXISTS. Nada é apagado.
--
--  Sem a tabela dos avisos o resto funciona: o painel mostra as outras
--  secções, não cria avisos e a falha vai para o log — como acontece
--  com o histórico dos PINs, o dos orçamentos e o das trocas.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → escolher este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-avisos-pin.sql
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

-- ---------------------------------------------------------------------
--  1. pedidos.registado_por
--
--  O ALTER só corre se a coluna faltar: um ADD COLUMN a seco falharia
--  na segunda importação. O SQL é montado numa variável e executado com
--  PREPARE, que é a forma que funciona tanto em MySQL como em MariaDB
--  sem depender de sintaxe própria de cada um.
-- ---------------------------------------------------------------------
SET @falta_coluna := (
    SELECT COUNT(*) = 0
      FROM `information_schema`.`COLUMNS`
     WHERE `TABLE_SCHEMA` = DATABASE()
       AND `TABLE_NAME`   = 'pedidos'
       AND `COLUMN_NAME`  = 'registado_por'
);

SET @sql := IF(
    @falta_coluna,
    'ALTER TABLE `pedidos`
        ADD COLUMN `registado_por` INT UNSIGNED DEFAULT NULL,
        ADD KEY `idx_pedidos_registado_por` (`registado_por`),
        ADD CONSTRAINT `fk_pedidos_registado_por`
            FOREIGN KEY (`registado_por`) REFERENCES `utilizadores` (`id`)
            ON DELETE SET NULL
            ON UPDATE CASCADE',
    'DO 0'
);

PREPARE alterar FROM @sql;
EXECUTE alterar;
DEALLOCATE PREPARE alterar;

-- ---------------------------------------------------------------------
--  2. avisos_pin
--
--  Um aviso por pedido e por dia, garantido pela chave única: é ela que
--  torna a verificação repetível sem o aviso se repetir.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `avisos_pin` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id`       INT UNSIGNED NOT NULL,
    `destinatario_id` INT UNSIGNED DEFAULT NULL,
    `dia`             DATE         NOT NULL,
    `criado_em`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_avisos_pin_pedido_dia` (`pedido_id`, `dia`),
    KEY `idx_avisos_pin_destinatario` (`destinatario_id`, `criado_em`),

    CONSTRAINT `fk_avisos_pin_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_avisos_pin_destinatario`
        FOREIGN KEY (`destinatario_id`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- Confirmação: quantos pedidos ficaram com autor conhecido e quantos
-- avisos já estão guardados.
SELECT 'pedidos'     AS `tabela`,
       COUNT(*)      AS `linhas`,
       SUM(`registado_por` IS NOT NULL) AS `com_autor_conhecido`
FROM `pedidos`;

SELECT 'avisos_pin' AS `tabela`,
       COUNT(*)     AS `linhas`,
       MAX(`dia`)   AS `ultimo_dia`
FROM `avisos_pin`;
