-- =====================================================================
--  FixIt — Migração: contagem de consultas públicas ao PIN
-- ---------------------------------------------------------------------
--  Usa este script numa base de dados fixit que já tem dados (não
--  executes o fixit.sql nesse caso: ele apaga as tabelas todas).
--
--  Cria apenas a tabela `consultas_publicas`, onde index.php regista
--  cada consulta pública bem-sucedida, para a ficha do pedido poder
--  mostrar quantas vezes o PIN atual foi usado. Não altera pedidos,
--  clientes nem os históricos existentes.
--
--  A tabela guarda só o pedido e o instante — nunca o IP, o nome de
--  quem consultou nem o PIN. A ficha conta as linhas com data posterior
--  à emissão do PIN atual (a última de historico_pins), por isso gerar
--  um PIN novo recomeça a contagem sem apagar nada.
--
--  Este ficheiro pode ser importado mais do que uma vez: a tabela é
--  criada com IF NOT EXISTS e não apaga registos.
--
--  Sem esta tabela a consulta pública continua a funcionar; apenas não
--  fica registada (a falha vai para o log) e a ficha do pedido não
--  mostra a contagem.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → escolher este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-consultas-publicas.sql
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

CREATE TABLE IF NOT EXISTS `consultas_publicas` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id`    INT UNSIGNED NOT NULL,
    `registado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_consultas_pedido` (`pedido_id`, `registado_em`),

    CONSTRAINT `fk_consultas_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- Confirmação: uma linha com a tabela criada (ou já existente).
SELECT 'consultas_publicas' AS `tabela`,
       COUNT(*)             AS `consultas_ja_guardadas`
FROM `consultas_publicas`;
