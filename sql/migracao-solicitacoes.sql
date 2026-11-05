-- =====================================================================
--  FixIt — pedidos de orçamento vindos do site público
-- ---------------------------------------------------------------------
--  Um pedido de orçamento NÃO é uma ordem de serviço: não escreve em
--  `pedidos`, não gera PIN e não reserva equipamento. Só nasce um pedido
--  no balcão, com o equipamento à frente e o PIN emitido.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-solicitacoes.sql
--
--  Pode ser importado mais de uma vez: o IF NOT EXISTS não mexe numa
--  tabela que já exista.
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

CREATE TABLE IF NOT EXISTS `solicitacoes` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`         VARCHAR(80)  NOT NULL,
    `contacto`     VARCHAR(120) NOT NULL,   -- telefone ou email, como o cliente escreveu
    `equipamento`  VARCHAR(60)  NOT NULL,   -- categoria da oferta: Portáteis, Desktops, ...
    `marca_modelo` VARCHAR(120) DEFAULT NULL,
    `descricao`    TEXT         NOT NULL,
    `estado`       ENUM('Nova', 'Em contacto', 'Convertida', 'Arquivada')
                   NOT NULL DEFAULT 'Nova',
    `criado_em`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_solicitacoes_estado` (`estado`, `criado_em`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
