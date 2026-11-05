-- =====================================================================
--  FixIt — Migração: versão de sessão das contas
-- ---------------------------------------------------------------------
--  Usa este script numa base de dados fixit que já tem dados (não
--  executes o fixit.sql nesse caso: ele apaga as tabelas todas).
--
--  Acrescenta à tabela `utilizadores` uma coluna `sessao_versao` com um
--  valor por conta. Cada sessão guarda o valor que leu ao entrar; quando
--  alguém carrega em "Terminar outras sessões" em conta.php, é gravado
--  um valor novo, e todas as sessões que ainda têm o antigo são fechadas
--  no pedido seguinte. A sessão onde o botão foi carregado recebe o valor
--  novo e continua aberta.
--
--  Sem esta coluna o painel funciona e a mudança de palavra-passe
--  continua a fechar as outras sessões (essa verificação não depende
--  desta coluna); só o botão "Terminar outras sessões" fica indisponível
--  e explica porquê.
--
--  O ALTER é montado com PREPARE porque o MySQL não aceita
--  `ADD COLUMN IF NOT EXISTS` e o MariaDB aceita: assim o ficheiro pode
--  ser importado mais do que uma vez sem dar erro. A existência da coluna
--  é consultada em information_schema.COLUMNS, que os dois expõem.
--
--  Não altera dados: as contas existentes ficam com sessao_versao = ''
--  e as sessões abertas nesse momento continuam válidas.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → escolher este ficheiro
--  ou
--     mysql -u root fixit < sql/migracao-sessoes.sql
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

SET @existe := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'utilizadores'
       AND COLUMN_NAME = 'sessao_versao'
);

SET @migrar := IF(
    @existe = 0,
    'ALTER TABLE `utilizadores`
        ADD COLUMN `sessao_versao` CHAR(64) NOT NULL DEFAULT '''' AFTER `cargo`',
    'SELECT ''A coluna sessao_versao já existe. Nada a fazer.'' AS aviso'
);

PREPARE migrar FROM @migrar;
EXECUTE migrar;
DEALLOCATE PREPARE migrar;

-- Confirmação: a coluna tem de aparecer nesta listagem.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'utilizadores'
   AND COLUMN_NAME = 'sessao_versao';
