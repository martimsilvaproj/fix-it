<?php
declare(strict_types=1);

/**
 * index.php — porta de entrada do projecto.
 *
 * O sistema está dividido em duas superfícies:
 *
 *   • frontoffice/ — o que o cliente vê (consulta pública por PIN);
 *   • backoffice/  — a área da oficina (login, painel, pedidos, clientes,
 *                    equipamentos, utilizadores e conta).
 *
 * O código partilhado pelas duas vive em nucleo/ (ligação à base de dados,
 * sessão, ação de mudança de estado e PIN) e os estilos em assets/css/.
 *
 * Este ficheiro não tem lógica própria: só encaminha quem abre a raiz do
 * projecto para o front office, para não cair numa listagem de pastas.
 */

header('Location: frontoffice/index.php', true, 302);
exit;
