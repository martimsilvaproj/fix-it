-- =====================================================================
--  FixIt — Sistema de Gestão de Assistência Técnica
--  Script de criação da base de dados relacional
-- ---------------------------------------------------------------------
--  Motor alvo : MySQL 8.0.16+ (ou MariaDB 10.4+)
--  Charset    : utf8mb4 (suporta acentos, emojis e caracteres especiais)
--  Collation  : utf8mb4_unicode_ci (comparações sem distinção de
--               maiúsculas/minúsculas, ordenação correta em português)
--  Motor de tabelas: InnoDB (obrigatório para chaves estrangeiras e
--               transações com COMMIT / ROLLBACK)
--
--  NOTA IMPORTANTE: o bloco "RESET" abaixo APAGA as tabelas existentes.
--  Executa este script apenas na instalação inicial ou em ambiente de
--  desenvolvimento. Em produção, nunca o executes sobre dados reais.
-- =====================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `fixit`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `fixit`;

-- ---------------------------------------------------------------------
--  RESET (ordem inversa das dependências para respeitar as FK)
-- ---------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `fotos_equipamento`;
DROP TABLE IF EXISTS `avisos_pin`;
DROP TABLE IF EXISTS `consultas_publicas`;
DROP TABLE IF EXISTS `historico_tecnicos`;
DROP TABLE IF EXISTS `historico_pins`;
DROP TABLE IF EXISTS `historico_orcamentos`;
DROP TABLE IF EXISTS `historico_pedidos`;
DROP TABLE IF EXISTS `decisoes_orcamento`;
DROP TABLE IF EXISTS `pedidos`;
DROP TABLE IF EXISTS `equipamentos`;
DROP TABLE IF EXISTS `clientes`;
DROP TABLE IF EXISTS `utilizadores`;

SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
--  1. UTILIZADORES
--  Contas de acesso à área reservada (administradores e técnicos).
--  A palavra-passe NUNCA é guardada em texto simples: este campo
--  recebe o resultado de password_hash() do PHP (bcrypt ou argon2id),
--  daí os 255 caracteres de margem. O login.php confirma-a com
--  password_verify().
--
--  Este script cria a tabela, não as contas: para entrar pela primeira
--  vez importa também sql/utilizadores-iniciais.sql.
-- =====================================================================
CREATE TABLE `utilizadores` (
    `id`            INT UNSIGNED                          NOT NULL AUTO_INCREMENT,
    `nome`          VARCHAR(120)                          NOT NULL,
    `email`         VARCHAR(190)                          NOT NULL,
    `palavra_passe` VARCHAR(255)                          NOT NULL,
    `cargo`         ENUM('Administrador', 'Tecnico')      NOT NULL DEFAULT 'Tecnico',
    -- "Terminar sessões" em conta.php grava aqui um valor novo; as sessões
    -- antigas guardam o anterior e caem no pedido seguinte.
    `sessao_versao` CHAR(64)                              NOT NULL DEFAULT '',
    `criado_em`     DATETIME                              NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    -- O email é o identificador de login: não pode repetir-se.
    UNIQUE KEY `uq_utilizadores_email` (`email`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- =====================================================================
--  2. CLIENTES
--  Clientes da oficina. O NIF é único e validado por CHECK:
--  9 dígitos, tal como o NIF português.
--  O email é opcional (muitos clientes só dão telefone), mas quando
--  existe tem de ser único; o MySQL permite vários NULL numa coluna
--  UNIQUE, logo o índice não bloqueia registos sem email.
-- =====================================================================
CREATE TABLE `clientes` (
    `id`        INT UNSIGNED              NOT NULL AUTO_INCREMENT,
    `nome`      VARCHAR(120)              NOT NULL,
    `email`     VARCHAR(190)              DEFAULT NULL,
    `telefone`  VARCHAR(20)               NOT NULL,
    `nif`       CHAR(9)                   NOT NULL,
    `criado_em` DATETIME                  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_clientes_nif` (`nif`),
    UNIQUE KEY `uq_clientes_email` (`email`),

    CONSTRAINT `chk_clientes_nif_digitos`
        CHECK (`nif` REGEXP '^[0-9]{9}$')
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- =====================================================================
--  3. EQUIPAMENTOS
--  Cada equipamento pertence a um cliente. Se o cliente for eliminado,
--  todos os seus equipamentos (e, em cascata, os pedidos e as fotos)
--  são removidos — ON DELETE CASCADE.
--  O número de série é opcional porque equipamentos antigos/genéricos
--  podem não o ter, mas quando existe é único.
-- =====================================================================
CREATE TABLE `equipamentos` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `cliente_id`   INT UNSIGNED   NOT NULL,
    `tipo`         VARCHAR(60)    NOT NULL,   -- Portátil, Desktop, Impressora, ...
    `marca_modelo` VARCHAR(120)   NOT NULL,
    `numero_serie` VARCHAR(100)   DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_equipamentos_numero_serie` (`numero_serie`),
    KEY `idx_equipamentos_cliente` (`cliente_id`),      -- acelera listagens por cliente

    CONSTRAINT `fk_equipamentos_cliente`
        FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- =====================================================================
--  4. PEDIDOS
--  Ordens de serviço. Regras de negócio garantidas na própria BD:
--   • equipamento_id  -> CASCADE: sem equipamento não há pedido.
--   • tecnico_id      -> SET NULL: se a conta do técnico for eliminada,
--     o pedido NÃO pode desaparecer (é histórico e tem valor legal /
--     de garantia); fica apenas sem técnico atribuído. Por isso a
--     coluna aceita NULL.
--   • registado_por   -> quem registou o pedido, lido da sessão em
--     pedido-novo.php. É distinto de tecnico_id: quem abre a ficha não
--     é necessariamente quem fica responsável por ela. Também SET NULL
--     (as mesmas razões do tecnico_id) e NULL nos pedidos anteriores a
--     esta coluna, que por isso não têm autor conhecido.
--   • pin_consulta    -> 6 dígitos, único, para consulta pública do
--     estado do pedido sem login.
--   • orcamento       -> DECIMAL para valores monetários exactos
--     (nunca FLOAT, que introduz erros de arredondamento). NULL até
--     o diagnóstico estar concluído.
--   • data_conclusao  -> opcional e nunca anterior à data de entrada.
-- =====================================================================
CREATE TABLE `pedidos` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `equipamento_id`   INT UNSIGNED     NOT NULL,
    `tecnico_id`       INT UNSIGNED     DEFAULT NULL,
    `registado_por`    INT UNSIGNED     DEFAULT NULL,
    `descricao_avaria` TEXT             NOT NULL,
    `estado`           ENUM('Recebido', 'Em Diagnostico', 'Em Reparacao', 'Pronto', 'Entregue')
                                        NOT NULL DEFAULT 'Recebido',
    `orcamento`        DECIMAL(10, 2)   DEFAULT NULL,
    `pin_consulta`     CHAR(6)          NOT NULL,
    `data_entrada`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `data_conclusao`   DATETIME         DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pedidos_pin` (`pin_consulta`),
    KEY `idx_pedidos_equipamento` (`equipamento_id`),
    KEY `idx_pedidos_tecnico` (`tecnico_id`),
    KEY `idx_pedidos_estado` (`estado`),          -- filtros do painel de técnicos
    KEY `idx_pedidos_registado_por` (`registado_por`),  -- avisos ao autor

    CONSTRAINT `fk_pedidos_equipamento`
        FOREIGN KEY (`equipamento_id`) REFERENCES `equipamentos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_pedidos_tecnico`
        FOREIGN KEY (`tecnico_id`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT `fk_pedidos_registado_por`
        FOREIGN KEY (`registado_por`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT `chk_pedidos_pin_digitos`
        CHECK (`pin_consulta` REGEXP '^[0-9]{6}$'),

    CONSTRAINT `chk_pedidos_orcamento_positivo`
        CHECK (`orcamento` IS NULL OR `orcamento` >= 0),

    CONSTRAINT `chk_pedidos_datas_coerentes`
        CHECK (`data_conclusao` IS NULL OR `data_conclusao` >= `data_entrada`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- =====================================================================
--  5. HISTORICO_PEDIDOS
--  Percurso do pedido: uma linha por cada passagem de estado, com data
--  e técnico. `pedidos.estado` continua a ser o estado ATUAL (a fonte
--  de verdade do painel); esta tabela é o registo de como lá chegou.
--   • Não há UNIQUE (pedido, estado): uma reparação pode ser reaberta
--     e voltar a uma fase anterior. O stepper mostra a passagem mais
--     recente em cada fase; o histórico guarda todas.
--   • tecnico_id -> SET NULL: identifica quem registou a transição, mas
--     o histórico sobrevive à eliminação da conta desse técnico.
--   • registado_em -> data/hora da transição (na criação é igual a
--     pedidos.data_entrada).
--  As linhas são criadas pelos triggers no fim deste script; nenhuma
--  página precisa de as inserir à mão.
-- =====================================================================
CREATE TABLE `historico_pedidos` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `pedido_id`    INT UNSIGNED     NOT NULL,
    `estado`       ENUM('Recebido', 'Em Diagnostico', 'Em Reparacao', 'Pronto', 'Entregue')
                                    NOT NULL,
    `tecnico_id`   INT UNSIGNED     DEFAULT NULL,
    `registado_em` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_historico_pedido` (`pedido_id`, `registado_em`),
    KEY `idx_historico_tecnico` (`tecnico_id`),

    CONSTRAINT `fk_historico_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT `fk_historico_tecnico`
        FOREIGN KEY (`tecnico_id`) REFERENCES `utilizadores` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- =====================================================================
--  5b. HISTORICO_ORCAMENTOS
--  Cada alteração ao valor do orçamento, com o valor que estava, o novo
--  valor e quem a fez. Tabela separada de historico_pedidos porque os
--  dois registos têm campos diferentes (o estado não tem valor, o
--  orçamento não tem ENUM) e porque a consulta pública percorre o
--  histórico de estados sem ter de filtrar linhas de outro tipo.
--   • Os dois valores são opcionais: NULL à esquerda significa "ainda
--     por definir" e NULL à direita significa que o orçamento foi
--     removido (o cliente volta a ver "A definir após diagnóstico").
--   • tecnico_id -> SET NULL: a alteração não desaparece se a conta do
--     técnico for eliminada; fica sem autor identificado.
--   • As linhas são inseridas por pedido.php, na mesma transação que
--     atualiza pedidos.orcamento.
-- =====================================================================
CREATE TABLE `historico_orcamentos` (
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


-- =====================================================================
--  5c. HISTORICO_PINS
--  Cada vez que o PIN de consulta de um pedido é substituído, com quem
--  o trocou e quando. Tabela separada porque a troca não tem estado nem
--  valor: só autor e data.
--   • O PIN em si NÃO fica aqui. O histórico serve para saber quem
--     anulou um talão e quando, não para guardar códigos que já não
--     abrem nada. O PIN válido é sempre pedidos.pin_consulta.
--   • motivo distingue o primeiro talão ('inicial', gravado por
--     pedido-novo.php ao registar o pedido) de cada substituição
--     ('renovacao', gravada por pedido.php). O valor por omissão é
--     'renovacao' para que uma linha sem motivo nunca seja confundida
--     com o PIN original.
--   • tecnico_id -> SET NULL: o registo sobrevive à eliminação da conta
--     de quem fez a troca.
--   • As linhas são inseridas na mesma transação que cria o pedido ou
--     que atualiza pedidos.pin_consulta: ou ficam as duas, ou nenhuma.
-- =====================================================================
CREATE TABLE `historico_pins` (
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


-- =====================================================================
--  5d. CONSULTAS_PUBLICAS
--  Uma linha por consulta pública bem-sucedida (index.php), para a ficha
--  do pedido poder contar quantas vezes o PIN atual foi usado.
--   • Não guarda IP, nome nem o PIN: só o pedido e o instante. O que se
--     mostra é uma contagem, não um registo de quem consultou.
--   • A contagem do "PIN atual" é feita por pedido.php a partir da data
--     da última linha de historico_pins: ao gerar um PIN novo, o
--     contador recomeça do zero sem apagar nada.
--   • ON DELETE CASCADE: eliminar o pedido leva as suas consultas.
-- =====================================================================
CREATE TABLE `consultas_publicas` (
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


-- =====================================================================
--  5e. HISTORICO_TECNICOS
--  Cada atribuição ou troca de técnico de um pedido, feita na lista de
--  pedidos: quem estava, quem ficou e quem fez a troca, com data.
--   • tecnico_anterior NULL -> o pedido estava por atribuir.
--     tecnico_novo NULL     -> o pedido ficou por atribuir.
--   • autor_id é quem fez a troca (conta com sessão iniciada). Chama-se
--     autor_id e não tecnico_id — como nas outras tabelas de histórico —
--     porque esta já tem duas colunas de técnicos.
--   • Não há CHECK a exigir um dos lados preenchido: as três chaves
--     estrangeiras de utilizadores usam SET NULL, e apagar um técnico
--     que era o único de uma linha deixaria os dois lados NULL. Com um
--     CHECK, eliminar essa conta falharia. O pedido.php grava sempre um
--     dos lados.
--   • ON DELETE CASCADE no pedido: eliminar o pedido leva as suas trocas.
--   • As linhas são inseridas por pedidos.php, na mesma transação que
--     atualiza pedidos.tecnico_id.
-- =====================================================================
CREATE TABLE `historico_tecnicos` (
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


-- =====================================================================
--  5f. AVISOS_PIN
--  Aviso a quem registou o pedido quando o cliente não abre o PIN há
--  mais de uma semana: uma linha por pedido e por dia.
--   • A chave única (pedido_id, dia) é o que faz o "uma vez por dia em
--     vez de repetir": a verificação pode correr quantas vezes for —
--     e corre a cada abertura do painel —, que a mesma linha não nasce
--     duas vezes no mesmo dia, nem com duas pessoas a abrir o painel ao
--     mesmo tempo.
--   • dia é a data do aviso, separada de criado_em de propósito: é a
--     chave da unicidade e não muda com o fuso do servidor.
--   • destinatario_id é quem registou o pedido no momento do aviso;
--     SET NULL, para o aviso sobreviver à eliminação dessa conta (fica
--     sem destinatário, e por isso sem ninguém a quem ser mostrado).
--   • ON DELETE CASCADE no pedido: um pedido eliminado leva os seus
--     avisos, incluindo os de dias anteriores.
--   • As linhas são criadas pelo painel.php e só por ele; nenhuma outra
--     página escreve aqui.
-- =====================================================================
CREATE TABLE `avisos_pin` (
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


-- =====================================================================
--  5d. DECISOES_ORCAMENTO
--  O que o cliente decidiu depois de ver o valor, registado pela consulta
--  pública (consulta.php) a partir do PIN.
--   • decisao = 'aceite'   -> o pedido passa a «Em Reparação».
--   • decisao = 'recusado' -> o pedido passa a «Pronto»: o equipamento
--     fica a aguardar levantamento SEM ter sido mexido.
--   • valor congela o orçamento que estava à frente do cliente no
--     momento da decisão; se o valor mudar depois, a decisão anterior
--     continua a poder ler-se com o número com que foi tomada.
--   • Sem UNIQUE (pedido_id): uma reparação reaberta volta a ter
--     orçamento e uma segunda decisão. A mais recente é a que vale.
--   • Sem tecnico_id: quem decide é o cliente, não um utilizador do
--     painel. A transição de estado fica no historico_pedidos pelo
--     trigger, sem autor identificado.
-- =====================================================================
CREATE TABLE `decisoes_orcamento` (
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


-- =====================================================================
--  6. FOTOS_EQUIPAMENTO
--  Fotografias anexadas a um pedido (avaria, estado na entrega, etc.).
--  Guarda apenas o CAMINHO relativo do ficheiro; o ficheiro em si
--  fica no disco do servidor (ex.: /uploads/pedidos/12/avaria.jpg).
--  Eliminar o pedido remove automaticamente os seus registos de fotos.
-- =====================================================================
CREATE TABLE `fotos_equipamento` (
    `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `pedido_id`   INT UNSIGNED   NOT NULL,
    `caminho_foto` VARCHAR(255)  NOT NULL,
    `descricao`   VARCHAR(255)   DEFAULT NULL,
    `data_upload` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_fotos_pedido` (`pedido_id`),

    CONSTRAINT `fk_fotos_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- =====================================================================
--  7. TRIGGERS DO HISTÓRICO
--  Automáticos de propósito: qualquer caminho que crie ou mude o estado
--  de um pedido (pedido-novo.php, um painel futuro, uma correção manual
--  no phpMyAdmin) deixa rasto. O PHP só lê esta tabela.
--  Requerem o DELIMITER, porque o corpo tem mais de uma instrução.
-- =====================================================================
DELIMITER $$

DROP TRIGGER IF EXISTS `trg_pedidos_estado_inicial`$$
CREATE TRIGGER `trg_pedidos_estado_inicial`
AFTER INSERT ON `pedidos`
FOR EACH ROW
BEGIN
    INSERT INTO `historico_pedidos` (`pedido_id`, `estado`, `tecnico_id`, `registado_em`)
    VALUES (NEW.`id`, NEW.`estado`, NEW.`tecnico_id`, NEW.`data_entrada`);
END$$


DROP TRIGGER IF EXISTS `trg_pedidos_estado_alterado`$$
CREATE TRIGGER `trg_pedidos_estado_alterado`
AFTER UPDATE ON `pedidos`
FOR EACH ROW
BEGIN
    IF NEW.`estado` <> OLD.`estado` THEN
        INSERT INTO `historico_pedidos` (`pedido_id`, `estado`, `tecnico_id`, `registado_em`)
        VALUES (NEW.`id`, NEW.`estado`, NEW.`tecnico_id`, NOW());
    END IF;
END$$

DELIMITER ;
