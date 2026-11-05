<?php
declare(strict_types=1);

/**
 * conexao.php — Ligação à base de dados FixIt através de PDO.
 *
 * Responsabilidades deste ficheiro:
 *   1. Abrir UMA única ligação por pedido HTTP (padrão singleton, para
 *      não criar ligações repetidas quando é incluído em vários sítios).
 *   2. Configurar o PDO para falhar de forma explícita (exceções) em vez
 *      de devolver "false" silenciosamente.
 *   3. Garantir que todos os resultados vêm como arrays associativos.
 *   4. Nunca revelar credenciais nem detalhes técnicos ao utilizador.
 *
 * Uso típico numa página:
 *
 *     require_once __DIR__ . '/conexao.php';
 *     $pdo = obterConexao();
 *     $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE estado = :estado');
 *     $stmt->execute([':estado' => 'Recebido']);
 *     $pedidos = $stmt->fetchAll();
 *
 * Requisitos: PHP 8.0+ com a extensão pdo_mysql ativa.
 */

// Fuso horário alinhado com o servidor MySQL, para que datas geradas em
// PHP e datas geradas por CURRENT_TIMESTAMP sejam comparáveis.
date_default_timezone_set('Europe/Lisbon');

/**
 * Devolve a ligação PDO à base de dados, criando-a apenas na primeira
 * chamada e reutilizando-a nas seguintes.
 *
 * @throws RuntimeException Se a ligação falhar (o detalhe técnico fica
 *                          apenas no log do servidor).
 */
function obterConexao(): PDO
{
    // Ligação guardada entre chamadas (static mantém o valor na função).
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // -----------------------------------------------------------------
    // Configuração da ligação.
    //
    // Em ambiente escolar (XAMPP/WAMP) os valores por omissão costumam
    // ser suficientes: utilizador "root" e palavra-passe vazia.
    // Em produção, define as variáveis de ambiente FIXIT_DB_* no servidor
    // e nunca deixes credenciais escritas no código.
    // -----------------------------------------------------------------
    $configuracao = [
        'host'          => getenv('FIXIT_DB_HOST') ?: '127.0.0.1',
        'porta'         => (int) (getenv('FIXIT_DB_PORTA') ?: 3306),
        'base_dados'    => getenv('FIXIT_DB_NOME') ?: 'fixit',
        'utilizador'    => getenv('FIXIT_DB_UTILIZADOR') ?: 'root',
        'palavra_passe' => getenv('FIXIT_DB_PALAVRA_PASSE') ?: '',
        'charset'       => 'utf8mb4',
    ];

    // DSN: identifica o driver (mysql), o servidor, a base de dados e o
    // charset. Declarar o charset aqui evita "mojibake" nos acentos.
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $configuracao['host'],
        $configuracao['porta'],
        $configuracao['base_dados'],
        $configuracao['charset']
    );

    // -----------------------------------------------------------------
    // Opções do PDO:
    //  - ERRMODE_EXCEPTION : qualquer erro SQL lança PDOException, o que
    //    impede a aplicação de continuar com dados inválidos.
    //  - FETCH_ASSOC       : fetch()/fetchAll() devolvem ['coluna' => valor].
    //  - EMULATE_PREPARES=false : usa prepared statements nativos do MySQL
    //    (mais seguros contra injeção de SQL e com tipos corretos).
    //  - STRINGIFY_FETCHES=false : INT e DECIMAL chegam como int/float,
    //    não como texto.
    //  - ERRMODE já é suficiente; não usamos silêncio em lado nenhum.
    // -----------------------------------------------------------------
    $opcoes = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_PERSISTENT         => false,
    ];

    try {
        $pdo = new PDO(
            $dsn,
            $configuracao['utilizador'],
            $configuracao['palavra_passe'],
            $opcoes
        );
    } catch (PDOException $erro) {
        // O detalhe real (host, utilizador, mensagem do MySQL) vai para o
        // log do servidor; o utilizador recebe apenas uma mensagem neutra.
        error_log(
            '[FixIt][BD] Falha na ligação: ' . $erro->getMessage()
        );

        // A exceção original é anexada (3.º argumento) para quem estiver a
        // depurar conseguir ver a causa, sem a mostrar no browser.
        throw new RuntimeException(
            'Não foi possível estabelecer ligação à base de dados. Tenta novamente mais tarde.',
            0,
            $erro
        );
    }

    return $pdo;
}
