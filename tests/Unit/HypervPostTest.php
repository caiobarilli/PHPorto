<?php

declare(strict_types=1);

use App\Http\Pages;
use App\Win\PsResult;
use App\Win\WinAction;

const POST_VM_ID = '6c1e2a4b-0d3f-4e5a-9b8c-7d6e5f4a3b2c';

function pagesFonte(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages.php');
}

function corpoDe(string $fonte, string $assinatura): string
{
    $inicio = strpos($fonte, $assinatura);
    expect($inicio)->not->toBeFalse();

    $fim = strpos($fonte, "\n    }\n", (int) $inicio);

    return substr($fonte, (int) $inicio, (int) $fim - (int) $inicio);
}

it('o POST da /hyperv aceita o hyperv-vm validado pela WinAction', function (string $sub) {
    expect(Pages::hypervPostParams(['acao' => 'hyperv-vm', 'SubAction' => $sub, 'VmId' => strtoupper(POST_VM_ID), '_csrf' => 'x']))
        ->toBe(['SubAction' => $sub, 'VmId' => POST_VM_ID]);
})->with(['start', 'stop', 'restart']);

it('o POST da /hyperv recusa qualquer outra ação', function (mixed $acao) {
    Pages::hypervPostParams(['acao' => $acao, 'SubAction' => 'start', 'VmId' => POST_VM_ID]);
})->throws(InvalidArgumentException::class, 'Ação desconhecida.')->with(['hyperv', 'install', 'rdp', '', null, 'lista' => [['hyperv-vm']]]);

it('o POST da /hyperv passa pela validação: id fora do formato é recusado', function () {
    Pages::hypervPostParams(['acao' => 'hyperv-vm', 'SubAction' => 'stop', 'VmId' => '*']);
})->throws(InvalidArgumentException::class, 'VmId não é um identificador de VM válido.');

it('o POST da /hyperv ignora campo que não é texto e não leva campo a mais', function () {
    Pages::hypervPostParams(['acao' => 'hyperv-vm', 'SubAction' => ['stop'], 'VmId' => POST_VM_ID]);
})->throws(InvalidArgumentException::class, 'Escolha um valor para SubAction');

it('o POST da /hyperv não leva Name nem outro campo ao worker', function () {
    expect(Pages::hypervPostParams(['acao' => 'hyperv-vm', 'SubAction' => 'stop', 'VmId' => POST_VM_ID, 'Name' => '*']))
        ->toBe(['SubAction' => 'stop', 'VmId' => POST_VM_ID]);
});

it('a /win recusa o hyperv-vm, o wsl, o wsl-enable, o wsl-install-distro e o php-update, e só eles', function () {
    expect(Pages::winPostRefusal(WinAction::HypervVm))->toBe('Ligar, desligar e reiniciar VM é pela tela do Hyper-V, não pela /win.')
        ->and(Pages::winPostRefusal(WinAction::Wsl))->toBe('Desligar e acordar o WSL é pela configuração, não pela /win.')
        ->and(Pages::winPostRefusal(WinAction::WslEnable))->toBe('Ativar o WSL é pela configuração, não pela /win.')
        ->and(Pages::winPostRefusal(WinAction::WslInstallDistro))->toBe('Instalar a distro do WSL é pela configuração, não pela /win.')
        ->and(Pages::winPostRefusal(WinAction::PhpUpdate))->toBe('Atualizar o PHP é pela tela do PHP, não pela /win.');

    foreach (WinAction::cases() as $acao) {
        if (!in_array($acao, [WinAction::HypervVm, WinAction::Wsl, WinAction::WslEnable, WinAction::WslInstallDistro, WinAction::PhpUpdate], true)) {
            expect(Pages::winPostRefusal($acao))->toBeNull();
        }
    }
});

it('a /win confere a recusa ANTES de validar e de abrir qualquer UAC', function () {
    $corpo = corpoDe(pagesFonte(), 'private function handleWinPost(');

    $recusa   = strpos($corpo, 'self::winPostRefusal($acao)');
    $valida   = strpos($corpo, '$acao->validate($entrada)');
    $dispatch = strpos($corpo, '$this->oneShot->dispatch(');

    expect($recusa)->not->toBeFalse()
        ->and($recusa)->toBeLessThan($valida)
        ->and($recusa)->toBeLessThan($dispatch);
});

it('o POST da /hyperv queima o token primeiro, só roda pelo uso único e grava no histórico', function () {
    $corpo = corpoDe(pagesFonte(), 'private function handleHypervPost(');

    $token    = strpos($corpo, 'Csrf::consume()');
    $params   = strpos($corpo, 'self::hypervPostParams($_POST)');
    $bloqueio = strpos($corpo, '$this->oneShot->blockingReason()');
    $dispatch = strpos($corpo, '$this->oneShot->dispatch(WinAction::HypervVm');
    $grava    = strpos($corpo, '->record(new Execution(');

    expect($token)->not->toBeFalse()
        ->and($token)->toBeLessThan($params)
        ->and($params)->toBeLessThan($bloqueio)
        ->and($bloqueio)->toBeLessThan($dispatch)
        ->and($dispatch)->toBeLessThan($grava)
        ->and($corpo)->toContain('kind: ExecutionKind::Windows')
        ->and($corpo)->not->toContain('JobChannel')
        ->and(substr_count($corpo, "Respond::redirect('/hyperv')"))->toBe(5);
});

it('a rota /hyperv recebe o método, e o POST cai no handler antes da leitura', function () {
    $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    $corpo = corpoDe(pagesFonte(), 'public function hyperv(');

    expect($index)->toContain("'/hyperv' => \$pages->hyperv(\$method),")
        ->and(strpos($corpo, '$this->handleHypervPost()'))->toBeLessThan(strpos($corpo, '->dispatch(WinAction::Hyperv'))
        ->and(strpos($corpo, 'Respond::notFound()'))->toBeLessThan(strpos($corpo, '$this->handleHypervPost()'));
});

it('o aviso diz o nome da VM e a mensagem do Invoke-HypervVm', function () {
    $run = new PsResult('{"ok":true,"subAction":"start","vmId":"' . POST_VM_ID . '","name":"Ubuntu","mensagem":"VM ligando."}', 0, false, 900);

    expect(Pages::hypervVmNotice($run))->toBe('Ubuntu: VM ligando.');
});

it('o aviso de recusa também vem do Invoke-HypervVm', function () {
    $run = new PsResult("[ INFO ] x\r\n" . '{"ok":false,"name":"Win11","motivo":"em-transicao","mensagem":"A VM esta mudando de estado (Stopping)."}', 1, false, 300);

    expect(Pages::hypervVmNotice($run))->toBe('Win11: A VM esta mudando de estado (Stopping).');
});

it('o aviso não inventa: saída ilegível manda para o histórico, com o código', function (string $saida, ?int $codigo, string $trecho) {
    expect(Pages::hypervVmNotice(new PsResult($saida, $codigo, false, 10)))->toContain($trecho);
})->with([
    'sem json'      => ['[phporto] job recusado pela allowlist do worker', 126, 'código 126'],
    'sem mensagem'  => ['{"ok":false}', 1, 'código 1'],
    'sem código'    => ['', null, 'código nenhum'],
]);

it('o aviso de leitura cancelada no teto diz para atualizar', function () {
    expect(Pages::hypervVmNotice(new PsResult('{"ok":true,"mensagem":"x"}', null, true, 600000)))
        ->toBe('A ação na VM demorou demais e foi cancelada. Atualize a lista para ver como ela ficou.');
});
