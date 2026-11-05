<?php
declare(strict_types=1);

/**
 * pedido-novo.php — Registo de uma nova ordem de reparação.
 *
 * Fluxo:
 *   1. GET  → mostra o formulário (clientes carregados da base de dados).
 *   2. POST → valida tudo, grava cliente/equipamento/pedido/fotografias
 *             numa transação e redireciona para ?criado=<id> (PRG, para
 *             que um "refresh" não crie pedidos duplicados).
 *   3. GET ?criado=<id> → ecrã de sucesso com o PIN gerado.
 *
 * Segurança aplicada:
 *   • Todas as consultas usam prepared statements (sem interpolação de SQL).
 *   • Token CSRF de sessão em todos os POST.
 *   • Uploads validados pelo conteúdo real (getimagesize), com lista de
 *     MIME permitidos, limite de tamanho, nome aleatório e pasta protegida
 *     por uploads/.htaccess.
 *   • Erros técnicos vão para o log; o utilizador vê mensagens claras.
 *   • Só entra quem tem sessão: sessao.php faz a verificação no topo.
 */

require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/pin-consulta.php';
require_once __DIR__ . '/../nucleo/texto.php';

// Registo de pedidos é trabalho de oficina, não uma página pública:
// sem sessão válida, Sessao::exigir() manda para o login.php e termina o
// pedido antes de qualquer validação ou gravação.
Sessao::iniciar();

// Cabeçalhos de segurança: esta página tem layout próprio, por isso não
// passa pelo layout-painel.php que os envia pelas outras.
Sessao::cabecalhosSeguranca();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador = Sessao::exigir();

// =====================================================================
//  CONFIGURAÇÃO
// =====================================================================
const MAX_FOTOS        = 5;
const MAX_BYTES_FOTO   = 5 * 1024 * 1024;   // 5 MB por ficheiro
const MAX_DESCRICAO    = 5000;               // caracteres
const ESTADO_INICIAL   = 'Recebido';

/** MIME real => extensão canónica usada no nome do ficheiro. */
const TIPOS_FOTO = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];


// =====================================================================
//  FUNÇÕES AUXILIARES
// =====================================================================

/** Escapa texto para HTML. Usar sempre que se imprime dados do utilizador. */
function h(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Lê um campo de texto do POST, já sem espaços nas pontas. */
function valorPost(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/** Formata um valor monetário para leitura (ex.: 45,90 €). */
function euros(float $valor): string
{
    return number_format($valor, 2, ',', ' ') . ' €';
}

/** Devolve a classe de erro de um campo, para o HTML do formulário. */
function classeCampo(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? ' campo--invalido' : '';
}

/** Atributo aria-invalid apenas quando o campo tem erro. */
function ariaInvalido(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? ' aria-invalid="true"' : '';
}

/** Parágrafo de erro de um campo (sempre presente, oculto se não houver erro). */
function erroCampo(array $erros, string $campo): string
{
    if (isset($erros[$campo])) {
        return '<p class="campo__erro" id="erro-' . h($campo) . '">'
            . h($erros[$campo]) . '</p>';
    }

    return '<p class="campo__erro" id="erro-' . h($campo) . '" hidden></p>';
}

/** Token CSRF da sessão, criado na primeira utilização. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_pedido'])) {
        $_SESSION['csrf_pedido'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_pedido'];
}

/**
 * Normaliza $_FILES['fotos'] (input múltiplo) numa lista simples.
 *
 * @return array<int, array{name:string,tmp_name:string,error:int,size:int}>
 */
function normalizarFotos(): array
{
    if (!isset($_FILES['fotos']) || !is_array($_FILES['fotos']['name'])) {
        return [];
    }

    $lista = [];

    foreach ($_FILES['fotos']['name'] as $indice => $nome) {
        $lista[] = [
            'name'     => (string) $nome,
            'tmp_name' => (string) ($_FILES['fotos']['tmp_name'][$indice] ?? ''),
            'error'    => (int) ($_FILES['fotos']['error'][$indice] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($_FILES['fotos']['size'][$indice] ?? 0),
        ];
    }

    return $lista;
}

/**
 * Valida uma fotografia recebida.
 *
 * @return array{ignorar?:bool,erro?:string,ext?:string}
 */
function validarFotografia(array $foto): array
{
    if ($foto['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ignorar' => true];
    }

    if ($foto['error'] === UPLOAD_ERR_INI_SIZE || $foto['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['erro' => 'O ficheiro excede o limite de tamanho configurado no servidor.'];
    }

    if ($foto['error'] !== UPLOAD_ERR_OK) {
        return ['erro' => 'O envio falhou (código ' . $foto['error'] . '). Tenta novamente.'];
    }

    if (!is_uploaded_file($foto['tmp_name'])) {
        return ['erro' => 'O ficheiro recebido não é válido.'];
    }

    if ($foto['size'] <= 0) {
        return ['erro' => 'O ficheiro está vazio.'];
    }

    if ($foto['size'] > MAX_BYTES_FOTO) {
        return ['erro' => 'Cada fotografia pode ter no máximo 5 MB.'];
    }

    // getimagesize lê a assinatura do ficheiro: um .php renomeado para .jpg
    // falha aqui, mesmo que o browser tenha enviado image/jpeg.
    $informacao = @getimagesize($foto['tmp_name']);

    if ($informacao === false) {
        return ['erro' => 'O ficheiro não é uma imagem válida.'];
    }

    $mime = (string) ($informacao['mime'] ?? '');

    if (!isset(TIPOS_FOTO[$mime])) {
        return ['erro' => 'Formato não permitido. Usa JPG, PNG ou WebP.'];
    }

    return ['ext' => TIPOS_FOTO[$mime]];
}

/** Converte "8M", "512K", etc. em bytes (para ler os limites do php.ini). */
function limiteEmBytes(string $valor): int
{
    $valor = trim($valor);

    if ($valor === '') {
        return 0;
    }

    $numero = (int) $valor;
    $unidade = strtolower(substr($valor, -1));

    return match ($unidade) {
        'g' => $numero * 1024 * 1024 * 1024,
        'm' => $numero * 1024 * 1024,
        'k' => $numero * 1024,
        default => $numero,
    };
}

/**
 * Traduz uma violação de chave única numa mensagem de erro de campo.
 *
 * @return array{0:string,1:string}|null [campo, mensagem]
 */
function traduzirDuplicado(PDOException $erro): ?array
{
    $codigoSql = (int) ($erro->errorInfo[1] ?? 0);

    if ($erro->getCode() !== '23000' || $codigoSql !== 1062) {
        return null;
    }

    $mensagem = $erro->getMessage();

    return match (true) {
        str_contains($mensagem, 'uq_clientes_nif')
            => ['nif', 'Já existe um cliente com este NIF.'],
        str_contains($mensagem, 'uq_clientes_email')
            => ['email', 'Já existe um cliente com este email.'],
        str_contains($mensagem, 'uq_equipamentos_numero_serie')
            => ['numero_serie', 'Já está registado um equipamento com este número de série.'],
        str_contains($mensagem, 'uq_pedidos_pin')
            => ['pin', 'O PIN gerado colidiu com outro pedido. Tenta novamente.'],
        default => null,
    };
}


// =====================================================================
//  LIGAÇÃO À BASE DE DADOS
// =====================================================================
$pdo = null;
$erroLigacao = '';

try {
    $pdo = obterConexao();
} catch (RuntimeException $excecao) {
    // O detalhe já ficou no error_log dentro de conexao.php.
    $erroLigacao = 'Não foi possível ligar à base de dados. Confirma que o MySQL está '
        . 'a correr e que a base de dados "fixit" foi criada.';
}


// =====================================================================
//  ESTADO DA PÁGINA
// =====================================================================
$clientes = [];

if ($pdo instanceof PDO) {
    try {
        $consulta = $pdo->prepare('SELECT id, nome, nif FROM clientes ORDER BY nome');
        $consulta->execute();
        $clientes = $consulta->fetchAll();
    } catch (PDOException $excecao) {
        error_log('[FixIt][pedido-novo] ' . $excecao->getMessage());
        $erroLigacao = 'A base de dados responde, mas falta a estrutura do projeto. '
            . 'Importa o ficheiro sql/fixit.sql no phpMyAdmin e recarrega a página.';
    }
}

$temClientes = count($clientes) > 0;

$valores = [
    'modo_cliente'     => $temClientes ? 'existente' : 'novo',
    'cliente_id'       => '',
    'nome'             => '',
    'email'            => '',
    'telefone'         => '',
    'nif'              => '',
    'tipo'             => '',
    'marca_modelo'     => '',
    'numero_serie'     => '',
    'descricao_avaria' => '',
    'orcamento'        => '',
];

$erros           = [];
$erroGeral       = '';
$pedidoCriado    = null;
$pedidoInexistente = false;

$csrf = csrfToken();


// =====================================================================
//  ECRÃ DE SUCESSO (GET ?criado=<id>)
// =====================================================================
if ($pdo instanceof PDO && isset($_GET['criado'])) {
    $idCriado = filter_input(INPUT_GET, 'criado', FILTER_VALIDATE_INT);

    if (!is_int($idCriado) || $idCriado <= 0) {
        $pedidoInexistente = true;
    } else {
        try {
            $consulta = $pdo->prepare(
                'SELECT p.id, p.pin_consulta, p.estado, p.orcamento, p.data_entrada,
                        c.nome AS cliente_nome, c.nif AS cliente_nif,
                        e.tipo, e.marca_modelo, e.numero_serie,
                        (SELECT COUNT(*) FROM fotos_equipamento f WHERE f.pedido_id = p.id) AS total_fotos
                   FROM pedidos p
             INNER JOIN equipamentos e ON e.id = p.equipamento_id
             INNER JOIN clientes c    ON c.id = e.cliente_id
                  WHERE p.id = ?
                  LIMIT 1'
            );
            $consulta->execute([$idCriado]);
            $pedidoCriado = $consulta->fetch() ?: null;
        } catch (PDOException $excecao) {
            error_log('[FixIt][pedido-novo] ' . $excecao->getMessage());
            $pedidoCriado = null;
        }

        if ($pedidoCriado === null) {
            $pedidoInexistente = true;
        }
    }
}


// =====================================================================
//  PROCESSAMENTO DO FORMULÁRIO (POST)
// =====================================================================
$limitePost = limiteEmBytes((string) ini_get('post_max_size'));
$envioDemasiadoGrande = $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST)
    && $limitePost > 0
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limitePost;

if ($envioDemasiadoGrande) {
    $erroGeral = 'O envio excedeu o limite do servidor (' . ini_get('post_max_size')
        . '). Reduz o número ou o tamanho das fotografias.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $tokenRecebido = valorPost('csrf_token');

    if (!hash_equals($_SESSION['csrf_pedido'] ?? '', $tokenRecebido)) {
        $erroGeral = 'A sessão expirou por segurança. Recarrega a página e tenta novamente.';
    } else {
        // -------------------------------------------------------------
        // 1. Ler valores submetidos
        // -------------------------------------------------------------
        foreach (array_keys($valores) as $chave) {
            $valores[$chave] = valorPost($chave);
        }

        if ($valores['modo_cliente'] !== 'novo') {
            $valores['modo_cliente'] = 'existente';
        }

        $idCliente = 0;

        // -------------------------------------------------------------
        // 2. Cliente (existente ou novo)
        // -------------------------------------------------------------
        if ($valores['modo_cliente'] === 'existente') {
            $idCliente = (int) filter_var($valores['cliente_id'], FILTER_VALIDATE_INT);

            if ($idCliente <= 0) {
                $erros['cliente_id'] = 'Escolhe o cliente a quem pertence o equipamento.';
            } else {
                $consulta = $pdo->prepare('SELECT 1 FROM clientes WHERE id = ? LIMIT 1');
                $consulta->execute([$idCliente]);

                if ($consulta->fetchColumn() === false) {
                    $erros['cliente_id'] = 'O cliente escolhido já não existe. Atualiza a página.';
                }
            }
        } else {
            // Nome
            if (fixitTamanho($valores['nome']) < 2) {
                $erros['nome'] = 'Indica o nome completo do cliente.';
            } elseif (fixitTamanho($valores['nome']) > 120) {
                $erros['nome'] = 'O nome pode ter no máximo 120 caracteres.';
            }

            // Email (opcional, mas único quando existe)
            if ($valores['email'] !== '') {
                if (!filter_var($valores['email'], FILTER_VALIDATE_EMAIL)) {
                    $erros['email'] = 'Indica um email válido, por exemplo nome@exemplo.pt.';
                } elseif (fixitTamanho($valores['email']) > 190) {
                    $erros['email'] = 'O email é demasiado longo.';
                } else {
                    $consulta = $pdo->prepare('SELECT 1 FROM clientes WHERE email = ? LIMIT 1');
                    $consulta->execute([$valores['email']]);

                    if ($consulta->fetchColumn() !== false) {
                        $erros['email'] = 'Já existe um cliente registado com este email.';
                    }
                }
            }

            // Telefone
            if (!preg_match('/^[0-9+()\s.-]{6,20}$/', $valores['telefone'])) {
                $erros['telefone'] = 'Indica um telefone com 6 a 20 caracteres (ex.: 912 345 678).';
            }

            // NIF: 9 dígitos, único
            if (!preg_match('/^\d{9}$/', $valores['nif'])) {
                $erros['nif'] = 'O NIF tem de ter exatamente 9 dígitos.';
            } else {
                $consulta = $pdo->prepare('SELECT 1 FROM clientes WHERE nif = ? LIMIT 1');
                $consulta->execute([$valores['nif']]);

                if ($consulta->fetchColumn() !== false) {
                    $erros['nif'] = 'Já existe um cliente registado com este NIF.';
                }
            }
        }

        // -------------------------------------------------------------
        // 3. Equipamento
        // -------------------------------------------------------------
        if ($valores['tipo'] === '') {
            $erros['tipo'] = 'Indica o tipo de equipamento (portátil, desktop, impressora…).';
        } elseif (fixitTamanho($valores['tipo']) > 60) {
            $erros['tipo'] = 'O tipo pode ter no máximo 60 caracteres.';
        }

        if ($valores['marca_modelo'] === '') {
            $erros['marca_modelo'] = 'Indica a marca e o modelo do equipamento.';
        } elseif (fixitTamanho($valores['marca_modelo']) > 120) {
            $erros['marca_modelo'] = 'A marca e o modelo podem ter no máximo 120 caracteres.';
        }

        if (fixitTamanho($valores['numero_serie']) > 100) {
            $erros['numero_serie'] = 'O número de série pode ter no máximo 100 caracteres.';
        } elseif ($valores['numero_serie'] !== '') {
            $consulta = $pdo->prepare(
                'SELECT 1 FROM equipamentos WHERE numero_serie = ? LIMIT 1'
            );
            $consulta->execute([$valores['numero_serie']]);

            if ($consulta->fetchColumn() !== false) {
                $erros['numero_serie'] = 'Já está registado um equipamento com este número de série.';
            }
        }

        // -------------------------------------------------------------
        // 4. Avaria e orçamento
        // -------------------------------------------------------------
        $tamanhoDescricao = fixitTamanho($valores['descricao_avaria']);

        if ($tamanhoDescricao < 10) {
            $erros['descricao_avaria'] = 'Descreve a avaria com pelo menos 10 caracteres.';
        } elseif ($tamanhoDescricao > MAX_DESCRICAO) {
            $erros['descricao_avaria'] = 'A descrição pode ter no máximo '
                . number_format(MAX_DESCRICAO, 0, ',', ' ') . ' caracteres.';
        }

        $orcamento = null;

        if ($valores['orcamento'] !== '') {
            $limpo = str_replace([' ', "\u{00A0}", '€', ','], ['', '', '', '.'], $valores['orcamento']);

            if (!is_numeric($limpo)) {
                $erros['orcamento'] = 'Indica um valor em euros, por exemplo 45,90.';
            } else {
                $valorNum = (float) $limpo;

                if ($valorNum < 0 || $valorNum > 9999999.99) {
                    $erros['orcamento'] = 'O orçamento tem de estar entre 0 € e 9 999 999,99 €.';
                } else {
                    // Guardado como DECIMAL(10,2) através de string segura.
                    $orcamento = number_format($valorNum, 2, '.', '');
                }
            }
        }

        // -------------------------------------------------------------
        // 5. Fotografias
        // -------------------------------------------------------------
        $ficheiros = normalizarFotos();
        $fotosValidas = [];

        if (count($ficheiros) > MAX_FOTOS) {
            $erros['fotos'] = 'Podes anexar no máximo ' . MAX_FOTOS . ' fotografias.';
        } else {
            foreach ($ficheiros as $indice => $ficheiro) {
                $resultado = validarFotografia($ficheiro);

                if (!empty($resultado['ignorar'])) {
                    continue;
                }

                if (isset($resultado['erro'])) {
                    $erros['fotos'] = 'Fotografia ' . ($indice + 1) . ': ' . $resultado['erro'];
                    break;
                }

                $fotosValidas[] = $ficheiro + ['ext' => $resultado['ext']];
            }
        }

        // -------------------------------------------------------------
        // 6. Gravação (tudo ou nada)
        // -------------------------------------------------------------
        if ($erros === [] && $erroGeral === '') {
            $ficheirosMovidos = [];

            try {
                $pdo->beginTransaction();

                if ($valores['modo_cliente'] === 'novo') {
                    $inserir = $pdo->prepare(
                        'INSERT INTO clientes (nome, email, telefone, nif)
                         VALUES (?, ?, ?, ?)'
                    );
                    $inserir->execute([
                        $valores['nome'],
                        $valores['email'] !== '' ? $valores['email'] : null,
                        $valores['telefone'],
                        $valores['nif'],
                    ]);
                    $idCliente = (int) $pdo->lastInsertId();
                }

                $inserir = $pdo->prepare(
                    'INSERT INTO equipamentos (cliente_id, tipo, marca_modelo, numero_serie)
                     VALUES (?, ?, ?, ?)'
                );
                $inserir->execute([
                    $idCliente,
                    $valores['tipo'],
                    $valores['marca_modelo'],
                    $valores['numero_serie'] !== '' ? $valores['numero_serie'] : null,
                ]);
                $idEquipamento = (int) $pdo->lastInsertId();

                $pin = gerarPinUnico($pdo);

                // tecnico_id fica NULL: o pedido ainda não tem técnico
                // atribuído (aparece como "Por atribuir" no painel).
                //
                // registado_por fica com quem tem a sessão aberta: é quem
                // abre a ficha, e é a essa pessoa que o painel avisa
                // quando o cliente não abre o PIN durante uma semana. Não
                // é o mesmo que ficar responsável pelo pedido — isso
                // continua a ser o tecnico_id, atribuído depois, na lista.
                //
                // A coluna vem da migração dos avisos. Numa base por
                // migrar o pedido tem de continuar a ser registado — é o
                // fluxo principal da oficina —, por isso a coluna só entra
                // no INSERT se existir, e a ausência vai para o log.
                $temAutorConhecido = true;

                try {
                    $pdo->query('SELECT registado_por FROM pedidos LIMIT 1');
                } catch (PDOException $excecao) {
                    $temAutorConhecido = false;
                    error_log('[FixIt][Pedido novo][Avisos] ' . $excecao->getMessage());
                }

                $inserir = $pdo->prepare(
                    'INSERT INTO pedidos
                        (equipamento_id, tecnico_id, descricao_avaria, estado, orcamento, pin_consulta'
                    . ($temAutorConhecido ? ', registado_por' : '') . ')
                     VALUES (?, NULL, ?, ?, ?, ?'
                    . ($temAutorConhecido ? ', ?' : '') . ')'
                );

                $parametrosPedido = [
                    $idEquipamento,
                    $valores['descricao_avaria'],
                    ESTADO_INICIAL,
                    $orcamento,
                    $pin,
                ];

                if ($temAutorConhecido) {
                    $parametrosPedido[] = (int) $utilizador['id'];
                }

                $inserir->execute($parametrosPedido);
                $idPedido = (int) $pdo->lastInsertId();

                // Primeiro talão do pedido: fica no histórico com autor e
                // data, na mesma transação. Se a tabela ainda não existir
                // (migração por importar), o pedido é registado à mesma e
                // a falha fica no log — ao contrário da troca de PIN, que
                // é recusada sem rasto, aqui o talão emitido é o que o
                // cliente leva e não há histórico anterior a proteger.
                try {
                    registarPinNoHistorico(
                        $pdo,
                        $idPedido,
                        (int) $utilizador['id'],
                        'inicial'
                    );
                } catch (PDOException $erroHistoricoPin) {
                    error_log('[FixIt][pedido-novo][PIN] ' . $erroHistoricoPin->getMessage());
                }

                if ($fotosValidas !== []) {
                    $pastaRelativa = 'uploads/pedidos/' . $idPedido;
                    $pastaAbsoluta = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads'
                        . DIRECTORY_SEPARATOR . 'pedidos' . DIRECTORY_SEPARATOR . $idPedido;

                    if (!is_dir($pastaAbsoluta)
                        && !mkdir($pastaAbsoluta, 0755, true)
                        && !is_dir($pastaAbsoluta)
                    ) {
                        throw new RuntimeException(
                            'Não foi possível criar a pasta de uploads. '
                            . 'Verifica as permissões de escrita na pasta /uploads.'
                        );
                    }

                    $inserirFoto = $pdo->prepare(
                        'INSERT INTO fotos_equipamento (pedido_id, caminho_foto, descricao)
                         VALUES (?, ?, ?)'
                    );

                    foreach ($fotosValidas as $foto) {
                        // Nome aleatório + extensão canónica: o nome original
                        // nunca chega ao sistema de ficheiros.
                        $nomeFicheiro = bin2hex(random_bytes(16)) . '.' . $foto['ext'];
                        $destino = $pastaAbsoluta . DIRECTORY_SEPARATOR . $nomeFicheiro;

                        if (!move_uploaded_file($foto['tmp_name'], $destino)) {
                            throw new RuntimeException('Falha ao mover uma fotografia para uploads.');
                        }

                        @chmod($destino, 0644);
                        $ficheirosMovidos[] = $destino;

                        $inserirFoto->execute([
                            $idPedido,
                            $pastaRelativa . '/' . $nomeFicheiro,
                            fixitCortar(basename($foto['name']), 255),
                        ]);
                    }
                }

                $pdo->commit();

                // Padrão PRG: o browser volta a pedir a página por GET.
                header('Location: pedido-novo.php?criado=' . $idPedido, true, 303);
                exit;
            } catch (Throwable $excecao) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Se o commit falhou, os ficheiros já movidos ficariam órfãos.
                foreach ($ficheirosMovidos as $caminho) {
                    @unlink($caminho);
                }

                error_log('[FixIt][pedido-novo] ' . $excecao->getMessage());

                $duplicado = $excecao instanceof PDOException
                    ? traduzirDuplicado($excecao)
                    : null;

                if ($duplicado !== null && $duplicado[0] !== 'pin') {
                    $erros[$duplicado[0]] = $duplicado[1];
                } else {
                    $erroGeral = $duplicado !== null
                        ? $duplicado[1]
                        : 'Não foi possível registar o pedido. Nenhuma alteração foi guardada. '
                          . 'Tenta novamente.';
                }
            }
        }
    }
}

// Nome a mostrar no ecrã de sucesso (com NIF quando existe).
$tituloPagina = $pedidoCriado ? 'Pedido registado' : 'Novo pedido';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($tituloPagina) ?> — FixIt</title>
    <meta name="description" content="Registo de uma nova ordem de reparação na oficina FixIt.">
    <meta name="theme-color" content="#0C2038">

    <link rel="stylesheet" href="../assets/css/painel.css">
    <link rel="stylesheet" href="../assets/css/formulario.css">
</head>
<body class="pagina-formulario">

<a class="saltar-conteudo" href="#conteudo">Saltar para o conteúdo</a>

<!-- =================================================================
     ÍCONES (traço 1.7, 24x24, sem dependências externas)
     ================================================================= -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <g id="icone-seta-esquerda" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5"/>
            <path d="m11 6-6 6 6 6"/>
        </g>
        <g id="icone-alerta" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4.5 21 20H3L12 4.5Z"/>
            <path d="M12 10v4"/>
            <path d="M12 17h.01"/>
        </g>
        <g id="icone-check" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="8.25"/>
            <path d="m8.4 12.2 2.4 2.4 4.8-5"/>
        </g>
        <g id="icone-foto" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3.5" y="4.5" width="17" height="15" rx="2.2"/>
            <circle cx="8.75" cy="9.75" r="1.5"/>
            <path d="m4 16.5 4.7-4.7 3 3L15 11l5 5"/>
        </g>
        <g id="icone-copiar" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="9" y="9" width="11" height="11" rx="2"/>
            <path d="M15 6.5A2.5 2.5 0 0 0 12.5 4h-6A2.5 2.5 0 0 0 4 6.5v6A2.5 2.5 0 0 0 6.5 15"/>
        </g>
        <g id="icone-fechar" fill="none" stroke="currentColor" stroke-linecap="round">
            <path d="m6 6 12 12M18 6 6 18"/>
        </g>
        <g id="icone-mais" fill="none" stroke="currentColor" stroke-linecap="round">
            <path d="M12 5.25v13.5M5.25 12h13.5"/>
        </g>
    </defs>
</svg>

<!-- =================================================================
     BARRA SUPERIOR
     ================================================================= -->
<header class="topo-formulario">
    <div class="topo-formulario__interior">
        <a class="marca marca--clara" href="painel.php">
            <span class="marca__simbolo" aria-hidden="true">
                <!-- Marca: cabeça de parafuso em cruz. -->
                <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round">
                    <circle cx="12" cy="12" r="8.25"/>
                    <path d="M12 7.5v9M7.5 12h9"/>
                </svg>
            </span>
            <span class="marca__texto">
                <span class="marca__nome">FixIt</span>
                <span class="marca__descricao">Assistência técnica</span>
            </span>
        </a>

        <ol class="migalhas migalhas--sempre" aria-label="Localização">
            <li><a href="painel.php">Painel</a></li>
            <li aria-current="page">Novo pedido</li>
        </ol>

        <a class="botao botao--fantasma" href="painel.php">
            <svg aria-hidden="true"><use href="#icone-seta-esquerda"/></svg>
            <span>Voltar ao painel</span>
        </a>
    </div>
</header>

<main class="formulario-principal" id="conteudo" tabindex="-1">

<?php if ($erroLigacao !== ''): ?>
    <!-- =============================================================
         SEM LIGAÇÃO À BASE DE DADOS
         ============================================================= -->
    <div class="alerta alerta--erro" role="alert">
        <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
        <div>
            <p class="alerta__titulo">Serviço indisponível</p>
            <p class="alerta__texto"><?= h($erroLigacao) ?></p>
        </div>
    </div>

<?php elseif ($pedidoCriado): ?>
    <!-- =============================================================
         SUCESSO
         ============================================================= -->
    <section class="sucesso" aria-labelledby="titulo-sucesso">
        <span class="sucesso__marca" aria-hidden="true">
            <svg><use href="#icone-check"/></svg>
        </span>

        <h1 id="titulo-sucesso">Pedido #<?= (int) $pedidoCriado['id'] ?> registado</h1>
        <p class="sucesso__texto">
            O equipamento entrou na oficina com o estado
            <strong><?= h($pedidoCriado['estado']) ?></strong>.
            Entrega o PIN abaixo ao cliente: é com ele que consulta o andamento
            do pedido, sem precisar de conta.
        </p>

        <div class="pin">
            <p class="pin__rotulo">PIN de consulta</p>
            <p class="pin__valor" id="pin-valor"><?= h($pedidoCriado['pin_consulta']) ?></p>
            <button class="botao botao--fantasma pin__copiar" type="button" id="copiar-pin">
                <svg aria-hidden="true"><use href="#icone-copiar"/></svg>
                <span id="copiar-pin-texto" aria-live="polite">Copiar PIN</span>
            </button>
        </div>

        <p class="pin__nota">
            Guarda ou imprime este código agora e entrega-o ao cliente. Sem ele,
            a consulta pública do estado do pedido não é possível.
        </p>

        <dl class="resumo">
            <div>
                <dt>Cliente</dt>
                <dd><?= h($pedidoCriado['cliente_nome']) ?><?= $pedidoCriado['cliente_nif'] ? ' · NIF ' . h($pedidoCriado['cliente_nif']) : '' ?></dd>
            </div>
            <div>
                <dt>Equipamento</dt>
                <dd><?= h($pedidoCriado['marca_modelo']) ?> · <?= h($pedidoCriado['tipo']) ?></dd>
            </div>
            <div>
                <dt>Orçamento inicial</dt>
                <dd><?= $pedidoCriado['orcamento'] !== null ? h(euros((float) $pedidoCriado['orcamento'])) : 'A definir após diagnóstico' ?></dd>
            </div>
            <div>
                <dt>Fotografias</dt>
                <dd><?= (int) $pedidoCriado['total_fotos'] ?> <?= (int) $pedidoCriado['total_fotos'] === 1 ? 'anexada' : 'anexadas' ?></dd>
            </div>
            <div>
                <dt>Entrada</dt>
                <dd><?= h(date('d/m/Y \à\s H:i', strtotime((string) $pedidoCriado['data_entrada']))) ?></dd>
            </div>
        </dl>

        <div class="acoes__botoes">
            <a class="botao botao--primario" href="painel.php">
                <svg aria-hidden="true"><use href="#icone-seta-esquerda"/></svg>
                <span>Voltar ao painel</span>
            </a>
            <a class="botao botao--fantasma" href="pedido-novo.php">
                <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                <span>Registar outro pedido</span>
            </a>
        </div>
    </section>

<?php else: ?>
    <!-- =============================================================
         FORMULÁRIO
         ============================================================= -->
    <div class="cabecalho-pagina">
        <h1>Novo pedido</h1>
        <p>
            Regista a entrada de um equipamento. O sistema cria o pedido,
            gera o PIN de consulta e guarda as fotografias do estado inicial.
        </p>
    </div>

    <?php if ($pedidoInexistente): ?>
        <div class="alerta alerta--erro" role="alert">
            <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
            <div>
                <p class="alerta__titulo">Pedido não encontrado</p>
                <p class="alerta__texto">O pedido que tentaste abrir não existe ou foi eliminado.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($erroGeral !== ''): ?>
        <div class="alerta alerta--erro" role="alert">
            <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
            <div>
                <p class="alerta__titulo">Não foi possível registar o pedido</p>
                <p class="alerta__texto"><?= h($erroGeral) ?></p>
            </div>
        </div>
    <?php elseif ($erros !== []): ?>
        <div class="alerta alerta--erro" role="alert">
            <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
            <div>
                <p class="alerta__titulo">Faltam dados ou há campos inválidos</p>
                <p class="alerta__texto">Corrige os campos assinalados abaixo e volta a submeter.</p>
            </div>
        </div>
    <?php endif; ?>

    <form class="folha" id="form-pedido" method="post" action="pedido-novo.php"
          enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <!-- =========================================================
             1. CLIENTE
             ========================================================= -->
        <section class="seccao" aria-labelledby="seccao-cliente">
            <div class="seccao__topo">
                <h2 id="seccao-cliente">Cliente</h2>
                <p class="seccao__ajuda">Escolhe um cliente já registado ou cria um novo.</p>
            </div>

            <fieldset class="seletor">
                <legend class="campo__rotulo">Este equipamento pertence a</legend>

                <div class="segmento">
                    <label class="segmento__opcao">
                        <input type="radio" name="modo_cliente" value="existente"
                               <?= $valores['modo_cliente'] === 'existente' ? 'checked' : '' ?>
                               <?= $temClientes ? '' : 'disabled' ?>>
                        <span>Cliente existente</span>
                    </label>
                    <label class="segmento__opcao">
                        <input type="radio" name="modo_cliente" value="novo"
                               <?= $valores['modo_cliente'] === 'novo' ? 'checked' : '' ?>>
                        <span>Novo cliente</span>
                    </label>
                </div>

                <?php if (!$temClientes): ?>
                    <p class="campo__ajuda">Ainda não há clientes registados — começa por criar um novo.</p>
                <?php endif; ?>
            </fieldset>

            <div class="grupo" id="grupo-cliente-existente">
                <div class="campo<?= classeCampo($erros, 'cliente_id') ?>">
                    <label class="campo__rotulo" for="cliente_id">Cliente <span class="obrigatorio">*</span></label>
                    <select class="entrada" id="cliente_id" name="cliente_id" required
                            aria-describedby="erro-cliente_id"
                            <?= ariaInvalido($erros, 'cliente_id') ?>>
                        <option value="">Seleciona um cliente…</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= (int) $cliente['id'] ?>"
                                <?= $valores['cliente_id'] === (string) $cliente['id'] ? 'selected' : '' ?>>
                                <?= h($cliente['nome']) ?> · NIF <?= h($cliente['nif']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="campo__ajuda">Só aparecem clientes já registados na oficina.</p>
                    <?= erroCampo($erros, 'cliente_id') ?>
                </div>
            </div>

            <div class="grupo" id="grupo-cliente-novo" <?= $valores['modo_cliente'] === 'novo' ? '' : 'hidden' ?>>
                <div class="grelha">
                    <div class="campo campo--largo<?= classeCampo($erros, 'nome') ?>">
                        <label class="campo__rotulo" for="nome">Nome completo <span class="obrigatorio">*</span></label>
                        <input class="entrada" type="text" id="nome" name="nome"
                               value="<?= h($valores['nome']) ?>" maxlength="120" required
                               autocomplete="name" aria-describedby="erro-nome"
                               <?= ariaInvalido($erros, 'nome') ?>>
                        <?= erroCampo($erros, 'nome') ?>
                    </div>

                    <div class="campo<?= classeCampo($erros, 'telefone') ?>">
                        <label class="campo__rotulo" for="telefone">Telefone <span class="obrigatorio">*</span></label>
                        <input class="entrada" type="tel" id="telefone" name="telefone"
                               value="<?= h($valores['telefone']) ?>" maxlength="20" required
                               inputmode="tel" autocomplete="tel"
                               placeholder="912 345 678" aria-describedby="erro-telefone"
                               <?= ariaInvalido($erros, 'telefone') ?>>
                        <?= erroCampo($erros, 'telefone') ?>
                    </div>

                    <div class="campo<?= classeCampo($erros, 'nif') ?>">
                        <label class="campo__rotulo" for="nif">NIF <span class="obrigatorio">*</span></label>
                        <input class="entrada" type="text" id="nif" name="nif"
                               value="<?= h($valores['nif']) ?>" maxlength="9" required
                               inputmode="numeric" pattern="[0-9]{9}"
                               placeholder="123456789"
                               aria-describedby="ajuda-nif erro-nif"
                               <?= ariaInvalido($erros, 'nif') ?>>
                        <p class="campo__ajuda" id="ajuda-nif">9 dígitos, sem espaços.</p>
                        <?= erroCampo($erros, 'nif') ?>
                    </div>

                    <div class="campo<?= classeCampo($erros, 'email') ?>">
                        <label class="campo__rotulo" for="email">
                            Email <span class="campo__opcional">opcional</span>
                        </label>
                        <input class="entrada" type="email" id="email" name="email"
                               value="<?= h($valores['email']) ?>" maxlength="190"
                               autocomplete="email" placeholder="nome@exemplo.pt"
                               aria-describedby="erro-email"
                               <?= ariaInvalido($erros, 'email') ?>>
                        <?= erroCampo($erros, 'email') ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- =========================================================
             2. EQUIPAMENTO
             ========================================================= -->
        <section class="seccao" aria-labelledby="seccao-equipamento">
            <div class="seccao__topo">
                <h2 id="seccao-equipamento">Equipamento</h2>
                <p class="seccao__ajuda">Identifica o equipamento que deu entrada.</p>
            </div>

            <div class="grelha">
                <div class="campo<?= classeCampo($erros, 'tipo') ?>">
                    <label class="campo__rotulo" for="tipo">Tipo <span class="obrigatorio">*</span></label>
                    <input class="entrada" type="text" id="tipo" name="tipo"
                           value="<?= h($valores['tipo']) ?>" maxlength="60" required
                           list="tipos-equipamento" placeholder="Portátil, desktop, impressora…"
                           autocomplete="off" aria-describedby="erro-tipo"
                           <?= ariaInvalido($erros, 'tipo') ?>>
                    <datalist id="tipos-equipamento">
                        <option value="Portátil"></option>
                        <option value="Desktop"></option>
                        <option value="All-in-one"></option>
                        <option value="Monitor"></option>
                        <option value="Impressora"></option>
                        <option value="Telemóvel"></option>
                        <option value="Tablet"></option>
                        <option value="Consola"></option>
                        <option value="Outro"></option>
                    </datalist>
                    <?= erroCampo($erros, 'tipo') ?>
                </div>

                <div class="campo<?= classeCampo($erros, 'marca_modelo') ?>">
                    <label class="campo__rotulo" for="marca_modelo">Marca e modelo <span class="obrigatorio">*</span></label>
                    <input class="entrada" type="text" id="marca_modelo" name="marca_modelo"
                           value="<?= h($valores['marca_modelo']) ?>" maxlength="120" required
                           placeholder="ex.: Lenovo ThinkPad E14"
                           aria-describedby="erro-marca_modelo"
                           <?= ariaInvalido($erros, 'marca_modelo') ?>>
                    <?= erroCampo($erros, 'marca_modelo') ?>
                </div>

                <div class="campo campo--largo<?= classeCampo($erros, 'numero_serie') ?>">
                    <label class="campo__rotulo" for="numero_serie">
                        Número de série <span class="campo__opcional">opcional</span>
                    </label>
                    <input class="entrada" type="text" id="numero_serie" name="numero_serie"
                           value="<?= h($valores['numero_serie']) ?>" maxlength="100"
                           placeholder="Etiqueta na base do equipamento"
                           aria-describedby="erro-numero_serie"
                           <?= ariaInvalido($erros, 'numero_serie') ?>>
                    <?= erroCampo($erros, 'numero_serie') ?>
                </div>
            </div>
        </section>

        <!-- =========================================================
             3. AVARIA E ORÇAMENTO
             ========================================================= -->
        <section class="seccao" aria-labelledby="seccao-avaria">
            <div class="seccao__topo">
                <h2 id="seccao-avaria">Avaria e orçamento</h2>
                <p class="seccao__ajuda">Regista o que o cliente relatou e o valor combinado à entrada.</p>
            </div>

            <div class="campo<?= classeCampo($erros, 'descricao_avaria') ?>">
                <label class="campo__rotulo" for="descricao_avaria">
                    Descrição da avaria <span class="obrigatorio">*</span>
                </label>
                <textarea class="entrada entrada--area" id="descricao_avaria" name="descricao_avaria"
                          rows="5" maxlength="<?= MAX_DESCRICAO ?>"
                          placeholder="Ex.: não liga, ventoinha faz ruído, ecrã com riscos…"
                          required aria-describedby="ajuda-descricao erro-descricao_avaria"
                          <?= ariaInvalido($erros, 'descricao_avaria') ?>><?= h($valores['descricao_avaria']) ?></textarea>
                <p class="campo__ajuda" id="ajuda-descricao">
                    Inclui sintomas, quando começou e se o equipamento já foi intervencionado.
                </p>
                <?= erroCampo($erros, 'descricao_avaria') ?>
            </div>

            <div class="campo campo--curto<?= classeCampo($erros, 'orcamento') ?>">
                <label class="campo__rotulo" for="orcamento">
                    Orçamento inicial <span class="campo__opcional">opcional</span>
                </label>
                <div class="entrada-euros">
                    <input class="entrada" type="text" id="orcamento" name="orcamento"
                           value="<?= h($valores['orcamento']) ?>" maxlength="12"
                           inputmode="decimal" placeholder="0,00"
                           aria-describedby="ajuda-orcamento erro-orcamento"
                           <?= ariaInvalido($erros, 'orcamento') ?>>
                    <span class="entrada-euros__simbolo" aria-hidden="true">€</span>
                </div>
                <p class="campo__ajuda" id="ajuda-orcamento">Podes deixar em branco e definir depois do diagnóstico.</p>
                <?= erroCampo($erros, 'orcamento') ?>
            </div>
        </section>

        <!-- =========================================================
             4. FOTOGRAFIAS
             ========================================================= -->
        <section class="seccao" aria-labelledby="seccao-fotos">
            <div class="seccao__topo">
                <h2 id="seccao-fotos">Fotografias do estado inicial</h2>
                <p class="seccao__ajuda">
                    Servem de prova do estado em que o equipamento entrou na oficina.
                </p>
            </div>

            <div class="campo<?= classeCampo($erros, 'fotos') ?>" id="grupo-fotos">
                <label class="campo__rotulo" for="fotos">
                    Fotografias <span class="campo__opcional">até <?= MAX_FOTOS ?></span>
                </label>

                <div class="envio" id="zona-fotos">
                    <input class="visualmente-oculto" type="file" id="fotos" name="fotos[]"
                           accept="image/jpeg,image/png,image/webp" multiple
                           aria-describedby="ajuda-fotos erro-fotos">
                    <label class="envio__alvo" for="fotos">
                        <span class="envio__icone" aria-hidden="true">
                            <svg><use href="#icone-foto"/></svg>
                        </span>
                        <span class="envio__titulo">Arrasta fotografias para aqui</span>
                        <span class="envio__texto">ou clica para escolher do computador</span>
                        <span class="envio__limites">JPG, PNG ou WebP · 5 MB por ficheiro</span>
                    </label>
                </div>

                <ul class="miniaturas" id="miniaturas" aria-label="Fotografias escolhidas"></ul>

                <?php if (isset($erros['fotos'])): ?>
                    <p class="campo__erro" id="erro-fotos"><?= h($erros['fotos']) ?></p>
                <?php endif; ?>

                <p class="campo__ajuda" id="ajuda-fotos">
                    <?php if ($erros !== []): ?>
                        Se escolheste fotografias, volta a anexá-las depois de corrigir os restantes campos.
                    <?php else: ?>
                        As fotografias ficam na pasta de uploads, com um nome aleatório.
                    <?php endif; ?>
                </p>
                <?= erroCampo($erros, 'fotos') ?>
            </div>
        </section>

        <!-- =========================================================
             AÇÕES
             ========================================================= -->
        <div class="acoes">
            <p class="acoes__nota">Os campos marcados com <span aria-hidden="true">*</span> são obrigatórios.</p>
            <div class="acoes__botoes">
                <a class="botao botao--fantasma" href="painel.php">Cancelar</a>
                <button class="botao botao--primario" type="submit" id="submeter">
                    <svg aria-hidden="true"><use href="#icone-check"/></svg>
                    <span>Registar pedido</span>
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

</main>

<script>
/* =====================================================================
   pedido-novo.php — comportamento do formulário
   1. Alterna entre cliente existente e novo cliente.
   2. Valida os campos no browser com as mesmas regras do servidor
      (o servidor valida sempre de novo — isto é só feedback rápido).
   3. Pré-visualiza, valida e remove as fotografias escolhidas.
   4. Copia o PIN no ecrã de sucesso.
   ===================================================================== */
(function () {
    "use strict";

    var MAX_FOTOS = <?= MAX_FOTOS ?>;
    var MAX_BYTES = <?= MAX_BYTES_FOTO ?>;
    var TIPOS = ["image/jpeg", "image/png", "image/webp"];

    /* -------------------------------------------------------------
       1. Cliente existente / novo cliente
       ------------------------------------------------------------- */
    var grupoExistente = document.getElementById("grupo-cliente-existente");
    var grupoNovo = document.getElementById("grupo-cliente-novo");
    var opcoesModo = document.querySelectorAll('input[name="modo_cliente"]');

    function atualizarModoCliente() {
        if (!grupoExistente || !grupoNovo) {
            return;
        }

        var escolhida = document.querySelector('input[name="modo_cliente"]:checked');
        var novo = escolhida !== null && escolhida.value === "novo";

        grupoExistente.hidden = novo;
        grupoNovo.hidden = !novo;
    }

    opcoesModo.forEach(function (opcao) {
        opcao.addEventListener("change", atualizarModoCliente);
    });
    atualizarModoCliente();

    /* -------------------------------------------------------------
       Ferramentas de erro por campo
       ------------------------------------------------------------- */
    function grupoDoCampo(campo) {
        return campo.closest(".campo") || campo.closest("fieldset");
    }

    function mostrarErro(campo, mensagem) {
        var grupo = grupoDoCampo(campo);

        if (!grupo) {
            return;
        }

        grupo.classList.add("campo--invalido");
        campo.setAttribute("aria-invalid", "true");

        var texto = grupo.querySelector(".campo__erro");

        if (texto) {
            texto.textContent = mensagem;
            texto.hidden = false;
        }
    }

    function limparErro(campo) {
        var grupo = grupoDoCampo(campo);

        if (!grupo) {
            return;
        }

        grupo.classList.remove("campo--invalido");
        campo.removeAttribute("aria-invalid");

        var texto = grupo.querySelector(".campo__erro");

        if (texto) {
            texto.hidden = true;
            texto.textContent = "";
        }
    }

    function valorDe(id) {
        var campo = document.getElementById(id);
        return campo ? campo.value.trim() : "";
    }

    /* -------------------------------------------------------------
       2. Validação no browser
       ------------------------------------------------------------- */
    var formulario = document.getElementById("form-pedido");
    var campoFotos = document.getElementById("fotos");

    function validarFormulario() {
        var primeiroInvalido = null;

        // Limpa os erros da tentativa anterior antes de voltar a validar.
        document.querySelectorAll(".campo--invalido").forEach(function (grupo) {
            grupo.classList.remove("campo--invalido");
        });
        document.querySelectorAll(".campo__erro").forEach(function (texto) {
            texto.hidden = true;
            texto.textContent = "";
        });

        function falhar(id, mensagem) {
            var campo = document.getElementById(id);

            if (!campo) {
                return;
            }

            mostrarErro(campo, mensagem);

            if (primeiroInvalido === null) {
                primeiroInvalido = campo;
            }
        }

        var modoNovo = document.querySelector('input[name="modo_cliente"]:checked');

        if (modoNovo === null) {
            falhar("cliente_id", "Escolhe o tipo de cliente.");
        } else if (modoNovo.value === "existente") {
            if (valorDe("cliente_id") === "") {
                falhar("cliente_id", "Escolhe o cliente a quem pertence o equipamento.");
            }
        } else {
            if (valorDe("nome").length < 2) {
                falhar("nome", "Indica o nome completo do cliente.");
            }

            if (!/^[0-9+()\s.-]{6,20}$/.test(valorDe("telefone"))) {
                falhar("telefone", "Indica um telefone com 6 a 20 caracteres.");
            }

            if (!/^\d{9}$/.test(valorDe("nif"))) {
                falhar("nif", "O NIF tem de ter exatamente 9 dígitos.");
            }

            var email = valorDe("email");

            if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
                falhar("email", "Indica um email válido, por exemplo nome@exemplo.pt.");
            }
        }

        if (valorDe("tipo") === "") {
            falhar("tipo", "Indica o tipo de equipamento.");
        }

        if (valorDe("marca_modelo") === "") {
            falhar("marca_modelo", "Indica a marca e o modelo do equipamento.");
        }

        if (valorDe("descricao_avaria").length < 10) {
            falhar("descricao_avaria", "Descreve a avaria com pelo menos 10 caracteres.");
        }

        var orcamento = valorDe("orcamento");

        if (orcamento !== "" && !/^\d+([.,]\d{1,2})?$/.test(orcamento)) {
            falhar("orcamento", "Indica um valor em euros, por exemplo 45,90.");
        }

        if (campoFotos && campoFotos.files.length > MAX_FOTOS) {
            mostrarErro(campoFotos, "Podes anexar no máximo " + MAX_FOTOS + " fotografias.");

            if (primeiroInvalido === null) {
                primeiroInvalido = campoFotos;
            }
        }

        if (primeiroInvalido !== null) {
            primeiroInvalido.focus({ preventScroll: true });
            primeiroInvalido.scrollIntoView({ block: "center", behavior: "smooth" });
            return false;
        }

        return true;
    }

    if (formulario) {
        formulario.addEventListener("submit", function (evento) {
            if (!validarFormulario()) {
                evento.preventDefault();
            }
        });

        // Limpa o erro assim que o utilizador corrige o campo.
        formulario.addEventListener("input", function (evento) {
            var alvo = evento.target;

            if (alvo && alvo.classList.contains("entrada")) {
                limparErro(alvo);
            }
        });
    }

    /* -------------------------------------------------------------
       3. Fotografias: pré-visualização, validação e remoção
       ------------------------------------------------------------- */
    var zona = document.getElementById("zona-fotos");
    var listaMiniaturas = document.getElementById("miniaturas");
    var escolhidos = [];
    var enderecos = [];

    function libertarEnderecos() {
        enderecos.forEach(function (url) { URL.revokeObjectURL(url); });
        enderecos = [];
    }

    function formatarTamanho(bytes) {
        if (bytes >= 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(1).replace(".", ",") + " MB";
        }

        return Math.max(1, Math.round(bytes / 1024)) + " KB";
    }

    function sincronizarFicheiros() {
        if (!campoFotos) {
            return;
        }

        var transferencia = new DataTransfer();

        escolhidos.forEach(function (ficheiro) {
            transferencia.items.add(ficheiro);
        });

        campoFotos.files = transferencia.files;
    }

    function desenharMiniaturas() {
        if (!listaMiniaturas) {
            return;
        }

        libertarEnderecos();
        listaMiniaturas.innerHTML = "";

        escolhidos.forEach(function (ficheiro, indice) {
            var item = document.createElement("li");
            item.className = "miniatura";

            var url = URL.createObjectURL(ficheiro);
            enderecos.push(url);

            var imagem = document.createElement("img");
            imagem.src = url;
            imagem.alt = "";
            imagem.loading = "lazy";

            var remover = document.createElement("button");
            remover.type = "button";
            remover.className = "miniatura__remover";
            remover.setAttribute("aria-label", "Remover " + ficheiro.name);
            remover.innerHTML = '<svg aria-hidden="true"><use href="#icone-fechar"/></svg>';

            remover.addEventListener("click", function () {
                escolhidos.splice(indice, 1);
                sincronizarFicheiros();
                desenharMiniaturas();
            });

            var nome = document.createElement("span");
            nome.className = "miniatura__dados";
            nome.textContent = ficheiro.name + " · " + formatarTamanho(ficheiro.size);

            item.appendChild(imagem);
            item.appendChild(remover);
            item.appendChild(nome);
            listaMiniaturas.appendChild(item);
        });
    }

    function adicionarFicheiros(ficheiros) {
        if (!campoFotos) {
            return;
        }

        var mensagem = "";

        Array.prototype.forEach.call(ficheiros, function (ficheiro) {
            if (escolhidos.length >= MAX_FOTOS) {
                mensagem = "Podes anexar no máximo " + MAX_FOTOS + " fotografias.";
                return;
            }

            if (TIPOS.indexOf(ficheiro.type) === -1) {
                mensagem = '"' + ficheiro.name + '" não é JPG, PNG ou WebP.';
                return;
            }

            if (ficheiro.size > MAX_BYTES) {
                mensagem = '"' + ficheiro.name + '" excede os 5 MB.';
                return;
            }

            // Evita duplicados quando o utilizador volta a escolher o mesmo ficheiro.
            var repetido = escolhidos.some(function (existente) {
                return existente.name === ficheiro.name
                    && existente.size === ficheiro.size
                    && existente.lastModified === ficheiro.lastModified;
            });

            if (repetido) {
                return;
            }

            escolhidos.push(ficheiro);
        });

        if (mensagem !== "") {
            mostrarErro(campoFotos, mensagem);
        } else {
            limparErro(campoFotos);
        }

        sincronizarFicheiros();
        desenharMiniaturas();
    }

    if (campoFotos) {
        campoFotos.addEventListener("change", function () {
            adicionarFicheiros(Array.prototype.slice.call(campoFotos.files));
        });
    }

    if (zona) {
        ["dragenter", "dragover"].forEach(function (evento) {
            zona.addEventListener(evento, function (e) {
                e.preventDefault();
                zona.classList.add("envio--ativo");
            });
        });

        ["dragleave", "dragend", "drop"].forEach(function (evento) {
            zona.addEventListener(evento, function () {
                zona.classList.remove("envio--ativo");
            });
        });

        zona.addEventListener("drop", function (e) {
            e.preventDefault();

            if (e.dataTransfer && e.dataTransfer.files.length) {
                adicionarFicheiros(e.dataTransfer.files);
            }
        });
    }

    /* -------------------------------------------------------------
       4. Copiar o PIN no ecrã de sucesso
       ------------------------------------------------------------- */
    var botaoCopiar = document.getElementById("copiar-pin");
    var valorPin = document.getElementById("pin-valor");

    if (botaoCopiar && valorPin) {
        botaoCopiar.addEventListener("click", function () {
            var pin = valorPin.textContent.trim();
            var textoBotao = document.getElementById("copiar-pin-texto");
            var original = textoBotao ? textoBotao.textContent : "";

            function confirmar() {
                if (textoBotao) {
                    textoBotao.textContent = "Copiado";
                }

                window.setTimeout(function () {
                    if (textoBotao) {
                        textoBotao.textContent = original;
                    }
                }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(pin).then(confirmar).catch(selecionar);
            } else {
                selecionar();
            }

            // Alternativa para browsers/contextos sem Clipboard API.
            function selecionar() {
                try {
                    var intervalo = document.createRange();
                    intervalo.selectNodeContents(valorPin);

                    var selecao = window.getSelection();
                    selecao.removeAllRanges();
                    selecao.addRange(intervalo);

                    if (!document.execCommand("copy")) {
                        throw new Error("copy indisponível");
                    }

                    confirmar();
                } catch (erro) {
                    if (textoBotao) {
                        textoBotao.textContent = "Copia o PIN à mão";
                    }
                }
            }
        });
    }
})();
</script>
</body>
</html>
