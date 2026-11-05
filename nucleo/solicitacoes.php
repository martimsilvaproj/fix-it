<?php
declare(strict_types=1);

/**
 * solicitacoes.php — regras dos pedidos de orçamento que chegam pelo site.
 *
 * Junta num só sítio o que a caixa de entrada (backoffice/solicitacoes.php)
 * e a navegação do painel precisam de saber:
 *
 *   • que estados existem e como se chamam;
 *   • quantos pedidos estão por contactar (o número do menu);
 *   • como se lê um contacto escrito à pressa — «912 345 678», «+351 912
 *     345 678» ou «nome@exemplo.pt» — para o formulário de conversão não
 *     obrigar a oficina a reescrever o que o cliente já disse;
 *   • como se transforma uma solicitação num pedido de reparação.
 *
 * A conversão é a única operação que escreve: cria (ou reutiliza) o
 * cliente, cria o equipamento, emite o PIN e abre o pedido — tudo na
 * mesma transação. Um pedido de orçamento NÃO é uma ordem de serviço até
 * alguém decidir que é: é essa decisão que esta função executa, e é por
 * isso que ela exige os dados que o site nunca pediu (NIF e telefone, que
 * a tabela `clientes` não aceita em branco).
 *
 * Nada aqui inventa dados: o que o cliente não escreveu fica vazio e é a
 * oficina que preenche, no balcão, com o equipamento à frente.
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/texto.php';
require_once __DIR__ . '/pin-consulta.php';

/**
 * Estados da caixa de entrada.
 *
 * «Convertida» não é escolhido à mão: só a conversão o põe, para o estado
 * e a existência de `pedido_id` nunca discordarem. Os outros três são
 * decisões de quem atende — contactei, arquivei, ainda não vi.
 */
const SOLICITACOES_ESTADOS = ['Nova', 'Em contacto', 'Convertida', 'Arquivada'];

/** Estados que a página deixa marcar manualmente. */
const SOLICITACOES_ESTADOS_MANUAIS = ['Nova', 'Em contacto', 'Arquivada'];

/**
 * Erros de validação da conversão, por campo.
 *
 * Existe para a página poder pôr a mensagem ao lado do campo certo em vez
 * de um aviso genérico no topo — o formulário tem onze campos e «dados
 * inválidos» não diz qual.
 */
final class SolicitacaoInvalida extends RuntimeException
{
    /** @param array<string, string> $erros */
    public function __construct(public readonly array $erros)
    {
        parent::__construct('Os dados da conversão não são válidos.');
    }
}

/**
 * Pedidos por contactar, para o contador do menu.
 *
 * Falha em silêncio (zero, com registo no log): a navegação é desenhada em
 * todas as páginas do painel e um contador nunca deve impedir uma página
 * de abrir. Sem base de dados, ou sem a migração importada, o menu fica
 * sem número — que é a verdade.
 */
function solicitacoesNovas(): int
{
    try {
        $consulta = obterConexao()->query(
            "SELECT COUNT(*) FROM solicitacoes WHERE estado = 'Nova'"
        );

        return (int) $consulta->fetchColumn();
    } catch (Throwable $excecao) {
        error_log('[FixIt][Solicitacoes][Contagem] ' . $excecao->getMessage());

        return 0;
    }
}

/**
 * Classifica o contacto escrito no site: email, telefone ou nenhum dos
 * dois (o cliente pode ter escrito «liguem depois das 18h»).
 *
 * @return array{tipo: 'email'|'telefone'|'desconhecido', valor: string}
 */
function classificarContacto(string $contacto): array
{
    $limpo = trim($contacto);

    if ($limpo === '') {
        return ['tipo' => 'desconhecido', 'valor' => ''];
    }

    if (str_contains($limpo, '@')) {
        return filter_var($limpo, FILTER_VALIDATE_EMAIL) !== false
            ? ['tipo' => 'email', 'valor' => $limpo]
            : ['tipo' => 'desconhecido', 'valor' => $limpo];
    }

    $telefone = normalizarTelefone($limpo);

    return $telefone !== null
        ? ['tipo' => 'telefone', 'valor' => $telefone]
        : ['tipo' => 'desconhecido', 'valor' => $limpo];
}

/**
 * Telefone em forma canónica, ou null se não parecer um número português.
 *
 * Aceita o que as pessoas escrevem — «912 345 678», «912345678»,
 * «+351 912 345 678», «00351 912345678» — e devolve sempre nove dígitos
 * (ou `+351` e nove dígitos, quando o cliente escreveu o indicativo).
 * Não valida o prefixo contra a lista de operadores: um número fixo novo
 * não pode ser recusado por uma tabela desactualizada.
 */
function normalizarTelefone(string $contacto): ?string
{
    $limpo = preg_replace('/[\s().\-\/]+/u', '', trim($contacto)) ?? '';

    if ($limpo === '') {
        return null;
    }

    $temIndicativo = false;

    if (str_starts_with($limpo, '+351')) {
        $limpo         = substr($limpo, 4);
        $temIndicativo = true;
    } elseif (str_starts_with($limpo, '00351')) {
        $limpo         = substr($limpo, 5);
        $temIndicativo = true;
    }

    if (preg_match('/^[0-9]{9}$/', $limpo) !== 1) {
        return null;
    }

    return ($temIndicativo ? '+351' : '') . $limpo;
}

/** Uma solicitação da base, ou null. */
function buscarSolicitacao(PDO $pdo, int $id): ?array
{
    $consulta = $pdo->prepare(
        'SELECT id, nome, contacto, equipamento, marca_modelo, descricao,
                estado, pedido_id, convertida_em, criado_em
           FROM solicitacoes
          WHERE id = ?
          LIMIT 1'
    );
    $consulta->execute([$id]);

    $linha = $consulta->fetch();

    return $linha === false ? null : $linha;
}

/**
 * O pedido de orçamento já deu origem a um pedido de reparação?
 * Devolve o id desse pedido, ou null.
 */
function pedidoDaSolicitacao(PDO $pdo, int $id): ?int
{
    $consulta = $pdo->prepare('SELECT pedido_id FROM solicitacoes WHERE id = ? LIMIT 1');
    $consulta->execute([$id]);

    $valor = $consulta->fetchColumn();

    return $valor === false || $valor === null ? null : (int) $valor;
}

/** A tabela `pedidos` já tem a coluna `registado_por`? (migração dos avisos) */
function pedidosTemAutor(PDO $pdo): bool
{
    static $tem = null;

    if ($tem !== null) {
        return $tem;
    }

    try {
        $pdo->query('SELECT registado_por FROM pedidos LIMIT 1');
        $tem = true;
    } catch (PDOException $excecao) {
        error_log('[FixIt][Solicitacoes][Autor] ' . $excecao->getMessage());
        $tem = false;
    }

    return $tem;
}

/**
 * Converte um pedido de orçamento num pedido de reparação.
 *
 * O que faz, por esta ordem, dentro de uma transação:
 *   1. tranca a solicitação (SELECT … FOR UPDATE) e recusa se já tiver
 *      `pedido_id` — a conversão acontece uma vez;
 *   2. reutiliza o cliente escolhido ou cria um novo com os dados que a
 *      oficina confirmou (o site só recolhe nome e contacto);
 *   3. cria o equipamento;
 *   4. emite o PIN e abre o pedido em «Recebido», sem técnico atribuído;
 *   5. grava `pedido_id`/`convertida_em` e passa a solicitação a
 *      «Convertida».
 *
 * Se alguma coisa falhar, nada fica escrito — nem cliente, nem
 * equipamento, nem pedido.
 *
 * @param array<string, mixed> $dados campos do formulário de conversão
 *
 * @return array{pedido_id: int, cliente_id: int, cliente_criado: bool}
 *
 * @throws SolicitacaoInvalida quando há campos por corrigir
 * @throws RuntimeException    quando a escrita falha ou já foi convertida
 */
function converterSolicitacaoEmPedido(PDO $pdo, int $idSolicitacao, int $autor, array $dados): array
{
    $texto = static function (string $chave) use ($dados): string {
        $valor = $dados[$chave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    };

    $modo          = $texto('modo_cliente') === 'existente' ? 'existente' : 'novo';
    $idCliente     = (int) ($dados['cliente_id'] ?? 0);
    $nome          = $texto('nome');
    $email         = $texto('email');
    $telefone      = $texto('telefone');
    $nif           = preg_replace('/\s+/u', '', $texto('nif')) ?? '';
    $tipo          = $texto('tipo');
    $marcaModelo   = $texto('marca_modelo');
    $numeroSerie   = $texto('numero_serie');
    $descricao     = $texto('descricao');
    $orcamentoTexto = $texto('orcamento');

    $erros = [];
    $telefoneGuardar = $telefone;

    // -------------------------------------------------------------
    //  Cliente
    // -------------------------------------------------------------
    if ($modo === 'existente') {
        if ($idCliente <= 0) {
            $erros['cliente_id'] = 'Escolhe o cliente, ou preenche os dados de um cliente novo.';
        } else {
            $consulta = $pdo->prepare('SELECT 1 FROM clientes WHERE id = ? LIMIT 1');
            $consulta->execute([$idCliente]);

            if ($consulta->fetchColumn() === false) {
                $erros['cliente_id'] = 'O cliente escolhido já não existe. Atualiza a página.';
            }
        }
    } else {
        if (fixitTamanho($nome) < 2) {
            $erros['nome'] = 'Indica o nome do cliente.';
        } elseif (fixitTamanho($nome) > 120) {
            $erros['nome'] = 'O nome pode ter no máximo 120 caracteres.';
        }

        if ($nif === '') {
            $erros['nif'] = 'O NIF é obrigatório para abrir um pedido. Pede-o ao cliente.';
        } elseif (preg_match('/^[0-9]{9}$/', $nif) !== 1) {
            $erros['nif'] = 'O NIF tem de ter exatamente 9 dígitos.';
        }

        $telefoneNormalizado = normalizarTelefone($telefone);

        if ($telefoneNormalizado === null) {
            $erros['telefone'] = 'Indica um telefone com 9 dígitos (ex.: 912 345 678).';
        } else {
            // Guardado na forma canónica: «912 345 678» e «+351 912345678»
            // passam a ser comparáveis e o balcão reconhece o cliente.
            $telefoneGuardar = $telefoneNormalizado;
        }

        if ($email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $erros['email'] = 'Indica um email válido, por exemplo nome@exemplo.pt.';
            } elseif (fixitTamanho($email) > 190) {
                $erros['email'] = 'O email é demasiado longo.';
            }
        }
    }

    // -------------------------------------------------------------
    //  Equipamento e pedido
    // -------------------------------------------------------------
    if ($tipo === '') {
        $erros['tipo'] = 'Indica o tipo de equipamento (portátil, telemóvel, impressora…).';
    } elseif (fixitTamanho($tipo) > 60) {
        $erros['tipo'] = 'O tipo pode ter no máximo 60 caracteres.';
    }

    if ($marcaModelo === '') {
        $erros['marca_modelo'] = 'Indica a marca e o modelo do equipamento.';
    } elseif (fixitTamanho($marcaModelo) > 120) {
        $erros['marca_modelo'] = 'A marca e o modelo podem ter no máximo 120 caracteres.';
    }

    if (fixitTamanho($numeroSerie) > 100) {
        $erros['numero_serie'] = 'O número de série pode ter no máximo 100 caracteres.';
    }

    if (fixitTamanho($descricao) < 10) {
        $erros['descricao'] = 'Descreve a avaria com pelo menos 10 caracteres.';
    } elseif (fixitTamanho($descricao) > 2000) {
        $erros['descricao'] = 'A descrição pode ter no máximo 2000 caracteres.';
    }

    $orcamento = null;

    if ($orcamentoTexto !== '') {
        $limpo = str_replace([' ', "\u{00A0}", '€', ','], ['', '', '', '.'], $orcamentoTexto);

        if (!is_numeric($limpo)) {
            $erros['orcamento'] = 'Indica um valor em euros, por exemplo 45,90.';
        } else {
            $valor = (float) $limpo;

            if ($valor < 0 || $valor > 9999999.99) {
                $erros['orcamento'] = 'O orçamento tem de estar entre 0 € e 9 999 999,99 €.';
            } else {
                $orcamento = number_format($valor, 2, '.', '');
            }
        }
    }

    if ($erros !== []) {
        throw new SolicitacaoInvalida($erros);
    }

    $clienteCriado = false;

    $pdo->beginTransaction();

    try {
        // 1. A solicitação, trancada até ao fim da transação.
        $consulta = $pdo->prepare(
            'SELECT id, estado, pedido_id FROM solicitacoes WHERE id = ? FOR UPDATE'
        );
        $consulta->execute([$idSolicitacao]);
        $solicitacao = $consulta->fetch();

        if ($solicitacao === false) {
            throw new RuntimeException('Este pedido de orçamento já não existe.');
        }

        if ($solicitacao['pedido_id'] !== null) {
            throw new RuntimeException(
                'Este pedido de orçamento já foi convertido no pedido #'
                . (int) $solicitacao['pedido_id'] . '.'
            );
        }

        // 2. Cliente
        if ($modo === 'novo') {
            try {
                $inserir = $pdo->prepare(
                    'INSERT INTO clientes (nome, email, telefone, nif) VALUES (?, ?, ?, ?)'
                );
                $inserir->execute([
                    $nome,
                    $email !== '' ? $email : null,
                    $telefoneGuardar,
                    $nif,
                ]);
                $idCliente     = (int) $pdo->lastInsertId();
                $clienteCriado = true;
            } catch (PDOException $excecao) {
                // 23000 é violação de restrição UNIQUE: NIF ou email repetido.
                if ($excecao->getCode() === '23000') {
                    if (str_contains($excecao->getMessage(), 'uq_clientes_nif')) {
                        throw new RuntimeException(
                            'Já existe um cliente com este NIF. Escolhe-o na lista em vez de criar um novo.'
                        );
                    }

                    if (str_contains($excecao->getMessage(), 'uq_clientes_email')) {
                        throw new RuntimeException(
                            'Já existe um cliente com este email. Escolhe-o na lista em vez de criar um novo.'
                        );
                    }
                }

                throw $excecao;
            }
        }

        // 3. Equipamento
        try {
            $inserir = $pdo->prepare(
                'INSERT INTO equipamentos (cliente_id, tipo, marca_modelo, numero_serie)
                 VALUES (?, ?, ?, ?)'
            );
            $inserir->execute([
                $idCliente,
                $tipo,
                $marcaModelo,
                $numeroSerie !== '' ? $numeroSerie : null,
            ]);
        } catch (PDOException $excecao) {
            if ($excecao->getCode() === '23000'
                && str_contains($excecao->getMessage(), 'uq_equipamentos_numero_serie')
            ) {
                throw new RuntimeException(
                    'Já está registado um equipamento com este número de série.'
                );
            }

            throw $excecao;
        }

        $idEquipamento = (int) $pdo->lastInsertId();

        // 4. Pedido, com PIN novo. Sem técnico: a atribuição é feita
        //    depois, na lista de pedidos, por quem tiver o equipamento à
        //    frente.
        $pin     = gerarPinUnico($pdo);
        $temAutor = pedidosTemAutor($pdo);

        $inserir = $pdo->prepare(
            'INSERT INTO pedidos
                (equipamento_id, tecnico_id, descricao_avaria, estado, orcamento, pin_consulta'
            . ($temAutor ? ', registado_por' : '') . ')
             VALUES (?, NULL, ?, ?, ?, ?'
            . ($temAutor ? ', ?' : '') . ')'
        );

        $parametros = [
            $idEquipamento,
            $descricao,
            'Recebido',
            $orcamento,
            $pin,
        ];

        if ($temAutor && $autor > 0) {
            $parametros[] = $autor;
        }

        $inserir->execute($parametros);
        $idPedido = (int) $pdo->lastInsertId();

        // O primeiro talão do pedido fica no histórico. Se a tabela não
        // existir (migração por importar), o pedido é registado à mesma:
        // o que o cliente leva é o PIN, não o registo.
        try {
            registarPinNoHistorico($pdo, $idPedido, $autor > 0 ? $autor : null, 'inicial');
        } catch (PDOException $excecao) {
            error_log('[FixIt][Solicitacoes][PIN] ' . $excecao->getMessage());
        }

        // 5. Fechar a solicitação. O `AND pedido_id IS NULL` é a segunda
        //    barreira: se outra transação a converteu entretanto, o
        //    UPDATE não apanha linha e tudo é desfeito.
        $atualizar = $pdo->prepare(
            "UPDATE solicitacoes
                SET estado = 'Convertida', pedido_id = ?, convertida_em = NOW()
              WHERE id = ? AND pedido_id IS NULL"
        );
        $atualizar->execute([$idPedido, $idSolicitacao]);

        if ($atualizar->rowCount() !== 1) {
            throw new RuntimeException(
                'Este pedido de orçamento foi convertido por outra pessoa enquanto preenchias o formulário.'
            );
        }

        $pdo->commit();
    } catch (Throwable $excecao) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $excecao;
    }

    return [
        'pedido_id'      => $idPedido,
        'cliente_id'     => $idCliente,
        'cliente_criado' => $clienteCriado,
    ];
}
