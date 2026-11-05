-- =====================================================================
--  FixIt — Migração: histórico de trocas de técnico
-- ---------------------------------------------------------------------
--  Usa este script numa base de dados fixit que já tem dados (não
--  executes o fixit.sql nesse caso: ele apaga as tabelas todas).
--
--  Cria a tabela `historico_tecnicos`, onde a lista de pedidos regista
--  cada atribuição ou troca de técnico: quem estava, quem ficou e quem
--  fez a troca, com data. Não altera pedidos, clientes, nem o histórico
--  de estados.
--
--  Cada linha é uma troca efectiva (o pedido.php não regista uma
--  atribuição repetida):
--   • tecnico_anterior NULL -> o pedido estava por atribuir.
--   • tecnico_novo NULL     -> o pedido ficou por atribuir.
--   • autor_id              -> conta com sessão iniciada que fez a troca.
--     Chama-se autor_id e não tecnico_id (como nas outras tabelas de
--     histórico) porque aqui já há duas colunas de técnicos: quem estava
--     e quem ficou.
--
--  Não há CHECK a exigir um dos lados preenchido, de propósito: as três
--  chaves estrangeiras usam ON DELETE SET NULL, e eliminar um técnico
--  que já foi o único de uma linha deixaria os dois lados NULL. Com um
--  CHECK, apagar a conta de um técnico falharia em vez de esvaziar a
--  coluna. O pedido.php grava sempre um dos lados.
--
--  Este ficheiro pode ser importado mais do que uma vez: a tabela é
--  criada com IF NOT EXISTS e nada é apagado.
--
--  Sem esta tabela a atribuição na lista continua a funcionar: fica sem
--  registo no histórico e a falha vai para o log, como acontece com o
--  histórico do orçamento e o dos PINs.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → escolher este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-historico-tecnicos.sql
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

CREATE TABLE IF NOT EXISTS `historico_tecnicos` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id`        INT UNSIGNED NOT NULL,
    `tecnico_anterior` INT UNSIGNED DEFAULT NULL,
    `tecnico_novo`     INT UNSIGNED DEFAULT NULL,
    `autor_id`         INT UNSIGNED DEFAULT NULL,
    `registado_em`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_hist_tec_pedido` (`pedido_id`, `registado_em`),
    KEY `idx_hist_tec_anterior` (`tecnico_anterior`),
    KEY `idx_hist_tec_novo` (`tecnico_novo`),
    KEY `idx_hist_tec_autor` (`autor_id`),

    CONSTRAINT `fk_hist_tec_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_hist_tec_anterior`
        FOREIGN KEY (`tecnico_anterior`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT `fk_hist_tec_novo`
        FOREIGN KEY (`tecnico_novo`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT `fk_hist_tec_autor`
        FOREIGN KEY (`autor_id`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- Confirmação: uma linha com a estrutura criada (ou já existente) e
-- quantas trocas já estão guardadas.
SELECT 'historico_tecnicos' AS `tabela`,
       COUNT(*)            AS `registos_ja_guardados`
FROM `historico_tecnicos`;
