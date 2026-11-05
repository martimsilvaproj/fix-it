-- =====================================================================
--  FixIt — Contas iniciais da área da oficina
-- ---------------------------------------------------------------------
--  A tabela `utilizadores` começa vazia no fixit.sql e o login.php só
--  aceita hashes de password_hash(). Este script cria as contas que
--  permitem entrar pela primeira vez.
--
--  Palavras-passe de demonstração (muda-as antes de usar a sério):
--     martim@fixit.pt  →  FixIt!2026      (Administrador)
--     ana@fixit.pt     →  Oficina!2026    (Tecnico)
--     rui@fixit.pt     →  Oficina!2026    (Tecnico)
--
--  Podes importar o ficheiro mais do que uma vez: o email é UNIQUE e o
--  INSERT IGNORE não mexe em contas já existentes nem repõe palavras-passe
--  que já tenhas mudado.
--
--  Para criar outra conta ou repor uma palavra-passe, gera o hash com o
--  PHP da máquina e cola-o no INSERT:
--
--     php -r "echo password_hash('a-tua-palavra-passe', PASSWORD_DEFAULT), PHP_EOL;"
--
--  O hash do PHP 8.4 começa por $2y$12$ (bcrypt, custo 12); qualquer hash
--  aceite por password_verify() serve, incluindo argon2id se o servidor
--  tiver a extensão sodium.
--
--  Como aplicar:
--     phpMyAdmin → base fixit → Importar → escolher este ficheiro
--  ou
--     mysql -u root fixit < sql/utilizadores-iniciais.sql
-- =====================================================================

SET NAMES utf8mb4;

USE `fixit`;

INSERT IGNORE INTO `utilizadores` (`nome`, `email`, `palavra_passe`, `cargo`) VALUES
    (
        'Martim Silva',
        'martim@fixit.pt',
        '$2y$12$f7zPSQP4Lc7VZPl/K7lFPOHHUw/KnwfqXFgwL9t5bcO8Zmei7cNo2',
        'Administrador'
    ),
    (
        'Ana Pinto',
        'ana@fixit.pt',
        '$2y$12$ZOtukquOWkPRM3TyZWj8zOSQqReXs8R7vOCebmAlzJzS.HQTqnMfG',
        'Tecnico'
    ),
    (
        'Rui Teixeira',
        'rui@fixit.pt',
        '$2y$12$OStMT7tqM6.1uQzhCeDpT.khITJ4O5jsQcw92evrZSKkC6x/yN48a',
        'Tecnico'
    );
