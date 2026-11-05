<?php
declare(strict_types=1);

/**
 * sessao.php — entrada, sessão e saída da área da oficina.
 *
 * Toda a página administrativa começa assim, antes de qualquer consulta
 * ou output:
 *
 *     require_once __DIR__ . '/sessao.php';
 *     Sessao::iniciar();
 *     $utilizador = Sessao::exigir();
 *
 * `exigir()` devolve o utilizador autenticado (id, nome, email, cargo) ou
 * manda o visitante para o login.php e termina o pedido. Só o login.php
 * chama `entrar()` e só o logout.php chama `sair()`.
 *
 * Decisões de segurança:
 *   • a palavra-passe nunca é comparada em texto simples: o login usa
 *     password_verify() sobre o hash criado por password_hash();
 *   • o ID de sessão é regenerado ao entrar, para que um ID apanhado
 *     antes do login não sirva depois (session fixation);
 *   • cookie HttpOnly e SameSite=Lax, Secure quando o pedido vem por
 *     HTTPS — este último não pode estar sempre ligado porque em
 *     http://localhost o browser recusaria o cookie e não haveria login;
 *   • a sessão caduca após 8 horas sem atividade;
 *   • a sessão está presa à palavra-passe com que entrou: guarda uma
 *     impressão do hash e compara-a com a base de dados em cada pedido.
 *     Mudar a palavra-passe (aqui ou em Utilizadores) muda o hash, e todas
 *     as sessões antigas dessa conta caem no pedido seguinte — é assim que
 *     uma mudança de palavra-passe fecha as sessões abertas noutros
 *     dispositivos;
 *   • além disso, cada conta tem uma `sessao_versao` na base de dados. Uma
 *     sessão guarda o valor que leu ao entrar; "Terminar outras sessões"
 *     grava um valor novo, e as sessões com o antigo caem — fechar sessões
 *     sem mexer na palavra-passe;
 *   • o número de sessões abertas é contado lendo os ficheiros da pasta
 *     de sessões do PHP (não há índice na base de dados). Contam-se as que
 *     não expiraram e cuja versão é a atual da conta, que são exatamente
 *     as que ainda abrem o painel. Só se devolvem contagens — nunca IDs
 *     nem dados de sessão; se a pasta não for legível, o número é
 *     desconhecido e as páginas escondem-no em vez de mostrar zero;
 *   • nome de sessão próprio (fixit_painel), separado do cookie da
 *     consulta pública em frontoffice/index.php: sair da oficina não apaga a consulta
 *     do cliente e entrar na oficina não toca nela.
 */

require_once __DIR__ . '/conexao.php';

/** Nome do cookie da área da oficina. */
const SESSAO_NOME = 'fixit_painel';

/** Segundos sem atividade até a sessão caducar: 8 horas. */
const SESSAO_LIMITE = 8 * 3600;

final class Sessao
{
    private static bool $iniciada = false;

    /** Arranca a sessão uma única vez, com o cookie endurecido. */
    public static function iniciar(): void
    {
        if (self::$iniciada || session_status() === PHP_SESSION_ACTIVE) {
            self::$iniciada = true;
            return;
        }

        // use_strict_mode recusa IDs de sessão inventados pelo cliente.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'path'     => '/',
        ]);

        session_name(SESSAO_NOME);
        session_start();

        self::$iniciada = true;
    }

    /**
     * Utilizador autenticado, ou null.
     *
     * @return array{id: int, nome: string, email: string, cargo: string}|null
     */
    public static function utilizador(): ?array
    {
        $utilizador = $_SESSION['utilizador'] ?? null;

        return is_array($utilizador) && isset($utilizador['id']) ? $utilizador : null;
    }

    /**
     * Abre a sessão autenticada. Chamado apenas pelo login.php, depois de
     * password_verify() ter aceitado as credenciais.
     *
     * @param array{id: int|string, nome: string, email: string, cargo: string} $utilizador
     * @param string $hash Hash da palavra-passe que acabou de ser confirmado,
     *                     tal como está na base de dados.
     */
    public static function entrar(array $utilizador, string $hash): void
    {
        self::iniciar();

        // ID novo para a sessão autenticada; o antigo (anónimo) é apagado.
        session_regenerate_id(true);

        $_SESSION['utilizador'] = [
            'id'    => (int) $utilizador['id'],
            'nome'  => (string) $utilizador['nome'],
            'email' => (string) $utilizador['email'],
            'cargo' => (string) $utilizador['cargo'],
        ];
        $_SESSION['visto_em'] = time();

        self::registarCredencial($hash);
        self::registarVersao((string) ($utilizador['sessao_versao'] ?? ''));

        // As tentativas falhadas deixam de interessar depois de entrar.
        unset($_SESSION['tentativas_login']);
    }

    /** Valor novo para `utilizadores.sessao_versao`: 128 bits em hex. */
    public static function novaVersao(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Quantas sessões abertas tem esta conta, na versão indicada. Com
     * `$semAtual`, a sessão deste pedido não entra na conta.
     *
     * Devolve null quando não é possível ler a pasta de sessões: a página
     * deve omitir o número em vez de arriscar dizer zero.
     */
    public static function sessoesAbertas(int $id, string $versao, bool $semAtual = false): ?int
    {
        $mapa = self::mapaSessoes();

        if ($mapa === null) {
            return null;
        }

        $sessoes  = $mapa[$id][$versao] ?? [];
        $total    = count($sessoes);
        $temAtual = isset($sessoes[session_id()]);

        // O ficheiro desta sessão só é escrito no fim do pedido, por isso
        // não se pode contar pelo conteúdo. Se é desta conta e desta
        // versão, entra na conta à mão.
        $atual = self::utilizador();

        if (!$temAtual
            && $atual !== null
            && (int) $atual['id'] === $id
            && (string) ($_SESSION['versao'] ?? '') === $versao
        ) {
            $total++;
            $temAtual = true;
        }

        if ($semAtual && $temAtual) {
            $total--;
        }

        return max(0, $total);
    }

    /**
     * Contagens por conta e por versão, lidas uma única vez por pedido.
     *
     * @var array<int, array<string, array<string, true>>>|null
     */
    private static ?array $mapaSessoes = null;

    private static bool $mapaSessoesLido = false;

    /** @return array<int, array<string, array<string, true>>>|null */
    private static function mapaSessoes(): ?array
    {
        if (!self::$mapaSessoesLido) {
            self::$mapaSessoesLido = true;
            self::$mapaSessoes     = self::lerPastaSessoes();
        }

        return self::$mapaSessoes;
    }

    /**
     * Lê os ficheiros `sess_*` da pasta de sessões do PHP e agrupa os que
     * estão autenticados e dentro do prazo. Os ficheiros são lidos como
     * texto: não se chama session_decode() nem se executa nada do que lá
     * está. Só `utilizador.id`, `visto_em` e `versao` são extraídos.
     *
     * @return array<int, array<string, array<string, true>>>|null
     */
    private static function lerPastaSessoes(): ?array
    {
        $pasta = self::pastaSessoes();

        if ($pasta === null) {
            return null;
        }

        $ficheiros = @glob($pasta . DIRECTORY_SEPARATOR . 'sess_*');

        if (!is_array($ficheiros)) {
            return null;
        }

        $limite = time() - SESSAO_LIMITE;
        $mapa   = [];

        foreach ($ficheiros as $ficheiro) {
            // O ficheiro é reescrito em cada pedido: fora do prazo não
            // interessa ler o conteúdo.
            $modificado = @filemtime($ficheiro);

            if ($modificado === false || $modificado < $limite) {
                continue;
            }

            $conteudo = @file_get_contents($ficheiro);

            if (!is_string($conteudo) || $conteudo === '') {
                continue;
            }

            if (preg_match('/utilizador\|a:\d+:\{s:2:"id";i:(\d+);/', $conteudo, $conta) !== 1) {
                continue;
            }

            if (preg_match('/visto_em\|i:(\d+);/', $conteudo, $visto) !== 1) {
                continue;
            }

            if ((int) $visto[1] < $limite) {
                continue;
            }

            $versao = preg_match('/versao\|s:\d+:"([^"]*)";/', $conteudo, $versaoLida) === 1
                ? $versaoLida[1]
                : '';

            $mapa[(int) $conta[1]][$versao][substr(basename($ficheiro), 5)] = true;
        }

        return $mapa;
    }

    /** Pasta onde o PHP guarda os ficheiros de sessão, ou null. */
    private static function pastaSessoes(): ?string
    {
        $configurada = (string) ini_get('session.save_path');

        // Formato "2;/caminho": as sessões ficam em subpastas (N níveis),
        // que não se enumeram sem percorrer a árvore — conta desconhecida.
        if (preg_match('/^\d+;/', $configurada) === 1) {
            return null;
        }

        // Formato "/caminho;0644": o modo vem depois do ponto e vírgula.
        if (str_contains($configurada, ';')) {
            $partes     = explode(';', $configurada);
            $configurada = (string) end($partes);
        }

        $pasta = $configurada !== '' ? $configurada : (string) session_save_path();

        if ($pasta === '') {
            $pasta = sys_get_temp_dir();
        }

        return is_dir($pasta) && is_readable($pasta) ? $pasta : null;
    }

    /**
     * Marca esta sessão como estando na versão atual. Chamado ao entrar e
     * depois de carregar em "Terminar outras sessões" (ou de mudar a
     * própria palavra-passe), para que a sessão onde a ação aconteceu não
     * caia com as outras.
     */
    public static function registarVersao(string $versao): void
    {
        $_SESSION['versao'] = $versao;
    }

    /**
     * Marca a sessão como pertencente à palavra-passe atual. Chamado ao
     * entrar e sempre que a própria pessoa muda a sua palavra-passe, para
     * que a sessão onde a mudança aconteceu não caia com as outras.
     */
    public static function registarCredencial(string $hash): void
    {
        $_SESSION['credencial'] = self::impressao($hash);
    }

    /**
     * Impressão do hash da palavra-passe: não é uma credencial (não serve
     * para entrar), mas muda sempre que a palavra-passe muda.
     */
    private static function impressao(string $hash): string
    {
        return hash('sha256', $hash);
    }

    /**
     * Esta sessão ainda vale? Duas perguntas numa só consulta:
     *   • a palavra-passe é a mesma com que esta sessão entrou?
     *   • a conta não pediu entretanto para terminar as outras sessões?
     *
     * Sem base de dados a resposta é "sim": cada página mostra o seu
     * próprio estado de erro em vez de expulsar toda a gente, e a sessão
     * volta a ser verificada no pedido seguinte. Numa base de dados sem a
     * migração sql/migracao-sessoes.sql a segunda pergunta não se aplica e
     * fica só a da palavra-passe.
     */
    private static function sessaoAtual(int $id): bool
    {
        $credencial = $_SESSION['credencial'] ?? '';
        $versao     = $_SESSION['versao'] ?? '';

        $credencial = is_string($credencial) ? $credencial : '';
        $versao     = is_string($versao) ? $versao : '';

        try {
            $consulta = obterConexao()->prepare(
                'SELECT palavra_passe, sessao_versao FROM utilizadores WHERE id = ? LIMIT 1'
            );
            $consulta->execute([$id]);
            $linha = $consulta->fetch();
        } catch (PDOException $excecao) {
            if ($excecao->getCode() !== '42S22') {
                error_log('[FixIt][Sessao] ' . $excecao->getMessage());

                return true;
            }

            // Coluna em falta: vale só a verificação da palavra-passe.
            try {
                $consulta = obterConexao()->prepare(
                    'SELECT palavra_passe FROM utilizadores WHERE id = ? LIMIT 1'
                );
                $consulta->execute([$id]);
                $linha = $consulta->fetch();
            } catch (Throwable $falha) {
                error_log('[FixIt][Sessao] ' . $falha->getMessage());

                return true;
            }

            if (is_array($linha)) {
                $linha['sessao_versao'] = $versao;
            }
        }

        if (!is_array($linha) || !is_string($linha['palavra_passe'] ?? null)) {
            return false;
        }

        if ($credencial === '' || !hash_equals($credencial, self::impressao($linha['palavra_passe']))) {
            return false;
        }

        return hash_equals((string) ($linha['sessao_versao'] ?? ''), $versao);
    }

    /**
     * Atualiza os dados da conta guardados na sessão, depois de o próprio
     * utilizador os editar (nome, email ou cargo). Sem isto, o cabeçalho e
     * a guarda de administrador continuavam a ver os valores antigos até
     * ao próximo login.
     */
    public static function atualizarPerfil(string $nome, string $email, string $cargo): void
    {
        if (self::utilizador() === null) {
            return;
        }

        $_SESSION['utilizador']['nome'] = $nome;
        $_SESSION['utilizador']['email'] = $email;
        $_SESSION['utilizador']['cargo'] = $cargo;
    }

    /**
     * Garante uma sessão válida. Sem utilizador, ou com a sessão parada há
     * mais de SESSAO_LIMITE segundos, manda para o login e termina o
     * pedido — nenhuma página administrativa corre sem passar por aqui.
     *
     * @return array{id: int, nome: string, email: string, cargo: string}
     */
    public static function exigir(): array
    {
        self::iniciar();

        $utilizador = self::utilizador();

        if ($utilizador === null) {
            self::sair();
            self::redirecionar('entrada');
        }

        $visto = (int) ($_SESSION['visto_em'] ?? 0);

        if ($visto > 0 && (time() - $visto) > SESSAO_LIMITE) {
            self::sair();
            self::redirecionar('expirada');
        }

        // A palavra-passe mudou, ou a conta pediu para terminar as outras
        // sessões? Então esta é antiga: fecha-se, mesmo com cookie válido.
        if (!self::sessaoAtual((int) $utilizador['id'])) {
            self::sair();
            self::redirecionar('terminada');
        }

        // Cada página visitada renova o prazo de inatividade.
        $_SESSION['visto_em'] = time();

        return $utilizador;
    }

    /** A conta autenticada pertence a um administrador? */
    public static function eAdministrador(): bool
    {
        $utilizador = self::utilizador();

        return $utilizador !== null && ($utilizador['cargo'] ?? '') === 'Administrador';
    }

    /**
     * Como exigir(), mas reserva a página a administradores. Um técnico
     * autenticado volta ao painel com um aviso — a recusa é feita antes
     * de qualquer consulta da página.
     *
     * @return array{id: int, nome: string, email: string, cargo: string}
     */
    public static function exigirAdministrador(): array
    {
        $utilizador = self::exigir();

        if (($utilizador['cargo'] ?? '') !== 'Administrador') {
            header('Location: painel.php?aviso=sem-permissao', true, 303);
            exit;
        }

        return $utilizador;
    }

    /** Fecha a sessão e apaga o cookie no browser. */
    public static function sair(): void
    {
        self::iniciar();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $parametros['path'],
                'domain'   => $parametros['domain'],
                'secure'   => $parametros['secure'],
                'httponly' => $parametros['httponly'],
                'samesite' => $parametros['samesite'],
            ]);
        }

        session_destroy();
        self::$iniciada = false;
    }

    /** Token CSRF da sessão: um por formulário, criado à primeira chamada. */
    public static function csrf(string $chave = 'csrf_painel'): string
    {
        if (empty($_SESSION[$chave])) {
            $_SESSION[$chave] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[$chave];
    }

    /** Comparação em tempo constante do token recebido. */
    public static function csrfValido(?string $token, string $chave = 'csrf_painel'): bool
    {
        return is_string($token)
            && !empty($_SESSION[$chave])
            && hash_equals((string) $_SESSION[$chave], $token);
    }

    /**
     * Cabeçalhos de segurança da área reservada.
     *
     * O login.php já os enviava desde o início; as páginas autenticadas não,
     * e essa era a única parte do back office que ficava exposta a
     * clickjacking: sem X-Frame-Options, qualquer site podia embutir o painel
     * num <iframe> e sobrepor botões invisíveis por cima de «Sair» ou de
     * «Abandonar pedido».
     *
     * `nosniff` impede o browser de adivinhar o tipo de conteúdo, e
     * `same-origin` evita que o URL de uma ficha (que tem o id do pedido)
     * viaje no referrer para sites externos que a página ligue.
     *
     * Uma só definição, chamada por todas as páginas da oficina: repetir
     * quatro `header()` em nove ficheiros era pedir que um deles ficasse de
     * fora — foi exactamente o que aconteceu.
     */
    public static function cabecalhosSeguranca(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: private, no-store');

        // Política de conteúdo: só o que é da casa. Nada de scripts, folhas
        // ou imagens de outros domínios, nada de <object>, nada de formulários
        // para fora, e o painel não pode ser embutido (frame-ancestors, o
        // equivalente moderno do X-Frame-Options).
        //
        // `'unsafe-inline'` é necessário porque o painel usa estilos e pequenos
        // scripts escritos na própria página; apertar isso exige nonces por
        // pedido, que ficam para quando houver CSP própria por página.
        //
        // A única excepção é o Chart.js do gráfico de estados, que ainda vem
        // de um CDN — dependência que contraria a regra «sem CDN» do projecto
        // e está registada como decisão pendente. Vem fixo numa versão e preso
        // por integrity="sha384-…" no <script>, por isso um CDN comprometido
        // não consegue servir outro ficheiro.
        header(
            "Content-Security-Policy: default-src 'self'; base-uri 'none'; "
            . "form-action 'self'; frame-ancestors 'none'; object-src 'none'; "
            . "img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net"
        );
    }

    /** Iniciais para o avatar do perfil ("Martim Silva" → "MS"). */
    public static function iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome)) ?: [];
        $partes = array_values(array_filter($partes));

        if ($partes === []) {
            return '?';
        }

        $primeira = self::maiuscula($partes[0]);
        $ultima = count($partes) > 1
            ? self::maiuscula($partes[count($partes) - 1])
            : '';

        return $primeira . $ultima;
    }

    private static function maiuscula(string $palavra): string
    {
        if (function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($palavra, 0, 1, 'UTF-8'), 'UTF-8');
        }

        return strtoupper(substr($palavra, 0, 1));
    }

    /** 303 para o login com o motivo — um refresh não repete o POST. */
    private static function redirecionar(string $motivo): void
    {
        header('Location: login.php?motivo=' . $motivo, true, 303);
        exit;
    }
}
