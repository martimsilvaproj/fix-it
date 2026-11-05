-- =====================================================================
--  FixIt — migração: decisões do cliente sobre o orçamento
--
--  Cria apenas a tabela `decisoes_orcamento`, onde a consulta pública
--  regista o que o cliente decidiu depois de ver o valor: aceitar a
--  reparação ou recusá-la. Não altera pedidos, clientes nem o histórico
--  de estados.
--
--  Porque uma tabela nova e não uma coluna em `pedidos`:
--   • uma reparação pode ser reaberta e voltar a ter orçamento, por isso
--     interessa guardar a decisão de cada ciclo, com a data e o valor que
--     estava em cima da mesa quando o cliente decidiu;
--   • `pedidos.estado` continua a ser a fonte de verdade do estado atual.
--     Esta tabela é o registo de como o cliente o decidiu — e sobrevive
--     à mudança de estado seguinte, ao contrário de uma coluna que seria
--     reescrita.
--   • o valor fica congelado: se o orçamento mudar mais tarde, continua a
--     saber-se por quanto é que o cliente disse sim (ou não).
--
--  Regras:
--   • `valor` é o orçamento no momento da decisão. Nunca NULL: só se
--     decide com um valor à frente.
--   • ON DELETE CASCADE: eliminar o pedido leva as suas decisões.
--   • Sem UNIQUE (pedido_id): duas passagens pelo mesmo pedido (reparação
--     reaberta) dão origem a duas decisões, e a mais recente é a que vale.
--     Quem move o estado é sempre a consulta pública, que relê o estado
--     dentro da transacção antes de escrever.
--
--  Como importar (a partir da raiz do projecto):
--
--     mysql -u root fixit < sql/migracao-decisoes-orcamento.sql
--
--  Pode ser importado mais do que uma vez: a tabela é criada com
--  IF NOT EXISTS e não apaga registos.
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

CREATE TABLE IF NOT EXISTS `decisoes_orcamento` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `pedido_id`    INT UNSIGNED   NOT NULL,
    `decisao`      ENUM('aceite', 'recusado') NOT NULL,
    `valor`        DECIMAL(10, 2) NOT NULL,
    `registado_em` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_decisao_pedido` (`pedido_id`, `registado_em`),

    CONSTRAINT `fk_decisao_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `chk_decisao_valor`
        CHECK (`valor` >= 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- Confirmação: mostra o que ficou criado e quantas decisões já existem.
SELECT 'decisoes_orcamento' AS `tabela`,
       COUNT(*)             AS `decisoes_ja_guardadas`,
       COALESCE(SUM(`decisao` = 'aceite'), 0)   AS `aceites`,
       COALESCE(SUM(`decisao` = 'recusado'), 0) AS `recusadas`
FROM `decisoes_orcamento`;
