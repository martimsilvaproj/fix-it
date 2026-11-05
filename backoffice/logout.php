<?php
declare(strict_types=1);

/**
 * logout.php — termina a sessão da área da oficina.
 *
 * Só aceita POST com o token CSRF da sessão. Um link GET podia ser
 * disparado por outro site (basta uma imagem apontada para cá) e fechar a
 * sessão a meio do trabalho; o botão "Sair" do painel envia o POST.
 */

require_once __DIR__ . '/../nucleo/sessao.php';

Sessao::iniciar();

$token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Visita direta ao endereço: volta para onde o utilizador estava.
    header('Location: ' . (Sessao::utilizador() !== null ? 'painel.php' : 'login.php'), true, 303);
    exit;
}

if (!Sessao::csrfValido($token)) {
    // Token inválido (página velha ou pedido de outro site): não se fecha
    // nada. Se a sessão já não existir, o painel encaminha para o login.
    header('Location: painel.php', true, 303);
    exit;
}

Sessao::sair();

header('Location: login.php?motivo=saida', true, 303);
exit;
