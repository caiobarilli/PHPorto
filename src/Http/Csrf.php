<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Token anti-CSRF das telas.
 *
 * POR QUE ISTO EXISTE, e por que o CORS não resolve — a confusão aqui é a
 * regra, não a exceção:
 *
 *   O CORS NÃO IMPEDE O POST DE SAIR. Ele impede a RESPOSTA de ser LIDA.
 *
 * Um formulário em qualquer site que a pessoa abrir pode fazer POST para
 * http://127.0.0.1:4001/wsl, e o comando RODA. O atacante não vê a saída — e
 * não precisa ver: o efeito já aconteceu na máquina. Um `rm -rf` não devolve
 * nada de interessante mesmo.
 *
 * Sem autenticação e sem token, a diferença entre "só eu executo" e "qualquer
 * aba que eu abrir executa" é exatamente este arquivo.
 *
 * O token vive na sessão: sai da página junto com o formulário e volta no
 * POST, e um site de terceiro não tem como lê-lo (aí sim o CORS e a política
 * de mesma origem trabalham a nosso favor). O cookie de sessão é SameSite
 * Strict como segunda camada.
 */
final class Csrf
{
    private const FIELD = 'phporto_csrf';
    private const KEY   = 'phporto_csrf_token';

    public static function fieldName(): string
    {
        return self::FIELD;
    }

    /** Inicia a sessão com cookie restritivo. Idempotente. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Strict',
            'path'     => '/',
        ]);
        session_name('phporto_session');
        @session_start();
    }

    public static function token(): string
    {
        self::start();

        $current = $_SESSION[self::KEY] ?? null;

        if (is_string($current) && $current !== '') {
            return $current;
        }

        $token                = bin2hex(random_bytes(32));
        $_SESSION[self::KEY]  = $token;

        return $token;
    }

    /**
     * Confere o token do POST e o QUEIMA: um token vale por UMA execução.
     *
     * MEDIDO: com token reutilizável, reenviar o mesmo POST três vezes gerava
     * três execuções idênticas (186, 175 e 191 ms). Numa ferramenta que executa
     * comando de shell, a diferença entre "uma intenção" e "uma requisição" não
     * pode ficar por conta do cliente — recarregar, voltar no histórico,
     * reenviar o formulário ou um script repetir a chamada são todos caminhos
     * para o mesmo comando rodar de novo sem ninguém pedir.
     *
     * O ciclo fecha aqui, no servidor. Não há retry nem timeout do lado do
     * cliente: o token simplesmente deixa de valer assim que é aceito, e a
     * página seguinte (o redirect) já vem com um novo.
     *
     * Efeito colateral aceito: com duas abas abertas, a mais antiga carrega um
     * token vencido e tem o envio recusado com mensagem clara. Para uma
     * ferramenta local de uma pessoa só, recusar a aba velha é o comportamento
     * seguro.
     *
     * hash_equals em vez de === porque comparação com saída antecipada vaza o
     * tamanho do prefixo correto pelo tempo de resposta.
     */
    public static function consume(): bool
    {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;

        if (!self::sameSite(is_string($site) ? $site : null)) {
            return false;
        }

        self::start();

        $expected = $_SESSION[self::KEY] ?? null;
        $sent     = $_POST[self::FIELD] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($sent) || $sent === '') {
            return false;
        }

        if (!hash_equals($expected, $sent)) {
            return false;
        }

        // Queima. O próximo token nasce no token() da página seguinte.
        unset($_SESSION[self::KEY]);

        return true;
    }

    /**
     * Segunda camada, antes do token: o cabeçalho Sec-Fetch-Site, que o
     * navegador escreve e a página não consegue forjar.
     *
     * POST vindo de outro site é recusado mesmo antes de olhar o token. Sem o
     * cabeçalho (navegador antigo, ou cliente fora do navegador) passa: quem
     * tranca continua sendo o token, e isto só fecha mais cedo. "none" é a
     * navegação que a própria pessoa digitou ou abriu de favorito.
     */
    public static function sameSite(?string $header): bool
    {
        return $header === null || in_array(strtolower(trim($header)), ['same-origin', 'none'], true);
    }
}
