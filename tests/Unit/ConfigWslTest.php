<?php

declare(strict_types=1);

use App\Http\ConfigView;
use App\Http\Pages;
use App\Http\Respond;
use App\Win\ElevationState;
use App\Win\PsResult;

/**
 * @param list<string>|null $distros
 * @param list<string>|null $rodando
 */
function configWslHtml(?array $distros, ?array $rodando = [], bool $ps = true): string
{
    return Respond::render('config.php', new ConfigView(
        provider: 'sqlite',
        details: [],
        apiEnabled: false,
        apiFromEnv: false,
        apiOverridden: false,
        corsOrigin: 'http://127.0.0.1:4001',
        dbPath: '/tmp/x.sqlite',
        dbExists: false,
        notice: null,
        csrfToken: 't',
        csrfField: '_csrf',
        win: $ps ? new ElevationState(on: true, psPid: 1, provedAt: '2026-10-09 12:00:00') : new ElevationState(on: false),
        winRetry: false,
        winProofTimeout: 30,
        hypervEnabled: false,
        hypervEnabledAt: null,
        uacOk: true,
        uacSummary: 'UAC ok.',
        tz: 'America/Sao_Paulo',
        wslDistros: $distros,
        wslRunning: $rodando,
    ));
}

function fonteDasPaginas(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
}

function trechoDe(string $fonte, string $assinatura): string
{
    $inicio = strpos($fonte, $assinatura);
    expect($inicio)->not->toBeFalse();

    $fim = strpos($fonte, "\n    }\n", (int) $inicio);

    return substr($fonte, (int) $inicio, $fim === false ? null : $fim - (int) $inicio);
}

it('sem WSL a seção some', function () {
    $html = configWslHtml(null);

    expect($html)->not->toContain('id="wsl"')
        ->and($html)->not->toContain('id="form-wsl"');
});

it('com WSL a seção aparece, sem reload, com acordar e desligar pelo worker', function () {
    $html = configWslHtml(['Debian', 'Ubuntu'], ['Debian']);

    expect($html)->toContain('<section id="wsl">')
        ->and($html)->toContain('id="form-wsl" data-sem-reload')
        ->and($html)->toContain('name="acao" value="wsl"')
        ->and($html)->toContain('name="SubAction" value="wake" id="btn-wsl-acordar"')
        ->and($html)->toContain('name="SubAction" value="shutdown"')
        ->and($html)->toContain('Rodando: <strong>Debian</strong>')
        ->and($html)->toContain('Instaladas: Debian, Ubuntu.')
        ->and($html)->not->toContain('data-uac');
});

it('desligar passa por confirmação: o botão só abre o modal, e o envio está dentro dele', function () {
    $html = configWslHtml(['Debian']);

    expect($html)->toMatch('/<button type="button" class="btn btn-danger btn-sm" id="btn-wsl-desligar"/')
        ->and($html)->toContain('<dialog id="modal-wsl">')
        ->and($html)->toContain("closest('#btn-wsl-desligar')) { document.getElementById('modal-wsl').showModal(); }");

    $modal = substr($html, (int) strpos($html, '<dialog id="modal-wsl">'));
    $modal = substr($modal, 0, (int) strpos($modal, '</dialog>'));

    expect($modal)->toContain('value="shutdown"')
        ->and($modal)->toContain('data-fechar');
});

it('sem o PowerShell elevado os dois botões ficam desabilitados e a tela diz por quê', function () {
    $html = configWslHtml(['Debian'], [], ps: false);

    expect($html)->toMatch('/id="btn-wsl-acordar"\s+disabled/')
        ->and($html)->toMatch('/id="btn-wsl-desligar"\s+disabled/')
        ->and($html)->toContain('Precisa do PowerShell elevado ligado');
});

it('sem nada rodando, acordar fica habilitado e desligar desabilitado', function () {
    $html = configWslHtml(['Debian'], []);

    expect($html)->not->toMatch('/id="btn-wsl-acordar"\s+disabled/')
        ->and($html)->toMatch('/id="btn-wsl-desligar"\s+disabled/')
        ->and($html)->toContain('Nenhuma distro rodando.');
});

it('com distro rodando, acordar fica desabilitado e desligar habilitado', function () {
    $html = configWslHtml(['Debian', 'Ubuntu'], ['Debian']);

    expect($html)->toMatch('/id="btn-wsl-acordar"\s+disabled/')
        ->and($html)->not->toMatch('/id="btn-wsl-desligar"\s+disabled/')
        ->and($html)->toContain('Rodando: <strong>Debian</strong>');
});

it('sem saber o que roda, os dois botões ficam habilitados', function () {
    $html = configWslHtml(['Debian'], null);

    expect($html)->not->toMatch('/id="btn-wsl-acordar"\s+disabled/')
        ->and($html)->not->toMatch('/id="btn-wsl-desligar"\s+disabled/');
});

it('WSL sem distro instalada e nada rodando não deixa acordar nem desligar', function () {
    $html = configWslHtml([], []);

    expect($html)->toMatch('/id="btn-wsl-acordar"\s+disabled/')
        ->and($html)->toMatch('/id="btn-wsl-desligar"\s+disabled/')
        ->and($html)->toContain('Nenhuma distro instalada.');
});

it('sem a lista de rodando a tela diz que não leu', function () {
    expect(configWslHtml(['Debian'], null))->toContain('Não foi possível ler quais distros estão rodando.');
});

it('o nome da distro é escapado', function () {
    expect(configWslHtml(['<b>x</b>'], ['<b>x</b>']))->not->toContain('<b>x</b>');
});

it('o POST do WSL só leva a SubAction validada', function (string $sub) {
    expect(Pages::wslPostParams(['acao' => 'wsl', 'SubAction' => $sub, 'Distro' => 'Debian']))->toBe(['SubAction' => $sub]);
})->with(['shutdown', 'wake']);

it('o POST do WSL recusa subação ausente, em lista ou fora da lista', function (array $post) {
    Pages::wslPostParams($post);
})->throws(InvalidArgumentException::class)->with([
    'ausente'   => [['acao' => 'wsl']],
    'em lista'  => [['acao' => 'wsl', 'SubAction' => ['shutdown']]],
    'terminate' => [['acao' => 'wsl', 'SubAction' => 'terminate']],
]);

it('o aviso diz o que aconteceu', function (string $sub, PsResult $run, string $trecho) {
    expect(Pages::wslNotice($sub, $run))->toContain($trecho);
})->with([
    'desligou'         => ['shutdown', new PsResult('ok', 0, false, 10), 'WSL desligado'],
    'acordou'          => ['wake', new PsResult('ok', 0, false, 10), 'WSL acordado'],
    'acordar ocioso'   => ['wake', new PsResult('ok', 0, false, 10), 'tempo ocioso'],
    'falhou desligar'  => ['shutdown', new PsResult('x', 1, false, 10), 'Falha ao desligar o WSL (código 1)'],
    'falhou acordar'   => ['wake', new PsResult('x', 1, false, 10), 'Falha ao acordar o WSL (código 1)'],
    'sem código'       => ['wake', new PsResult('', null, false, 10), '(código nenhum)'],
    'demorou'          => ['shutdown', new PsResult('', null, true, 600000), 'demorou demais'],
    'aponta histórico' => ['shutdown', new PsResult('x', 1, false, 10), 'histórico da /win'],
]);

it('o POST do WSL na /config queima o token, valida, exige o PowerShell elevado, vai pelo worker sem UAC e grava no histórico', function () {
    $fonte = fonteDasPaginas();
    $post  = trechoDe($fonte, 'private function handleConfigPost(');

    expect(strpos($post, 'Csrf::consume()'))->toBeLessThan((int) strpos($post, 'WinAction::Wsl->value'));

    $corpo = trechoDe($fonte, 'private function handleWslPost(');

    $params   = strpos($corpo, 'self::wslPostParams($_POST)');
    $bloqueio = strpos($corpo, '$this->winBlockingReason()');
    $dispatch = strpos($corpo, '->dispatch(WinAction::Wsl, $params, $nonce');
    $grava    = strpos($corpo, '->record(new Execution(');

    expect($params)->not->toBeFalse()
        ->and($params)->toBeLessThan($bloqueio)
        ->and($bloqueio)->toBeLessThan($dispatch)
        ->and($dispatch)->toBeLessThan($grava)
        ->and($corpo)->toContain('new JobChannel(')
        ->and($corpo)->toContain('kind: ExecutionKind::Windows')
        ->and($corpo)->not->toContain('oneShot');
});
