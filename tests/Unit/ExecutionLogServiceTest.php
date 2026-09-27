<?php

declare(strict_types=1);

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Services\ExecutionLogService;
use Tests\Fakes\FakeProvider;

function exec_(
    string $command = 'ls -la',
    int $durationMs = 12,
    ExecutionKind $kind = ExecutionKind::Comando,
): Execution {
    return new Execution(
        command: $command,
        output: "total 0\n",
        exitCode: 0,
        durationMs: $durationMs,
        kind: $kind,
        timedOut: false,
    );
}

it('registra uma execução e entrega ao provider', function () {
    $provider = new FakeProvider();
    $service  = new ExecutionLogService($provider);

    $service->record(exec_('git status'));

    expect($provider->inserted)->toHaveCount(1)
        ->and($provider->inserted[0]->command)->toBe('git status');
});

it('NÃO altera o comando ao registrar — o log é prova, não normalização', function () {
    $provider = new FakeProvider();
    $service  = new ExecutionLogService($provider);

    $service->record(exec_("  echo 'oi'  \n"));

    expect($provider->inserted[0]->command)->toBe("  echo 'oi'  \n");
});

it('recusa comando vazio', function () {
    (new ExecutionLogService(new FakeProvider()))->record(exec_(''));
})->throws(InvalidArgumentException::class);

it('recusa comando só com espaços', function () {
    (new ExecutionLogService(new FakeProvider()))->record(exec_("   \n\t "));
})->throws(InvalidArgumentException::class);

it('recusa duração negativa', function () {
    (new ExecutionLogService(new FakeProvider()))->record(exec_('ls', -1));
})->throws(InvalidArgumentException::class);

it('não toca no provider quando a entrada é inválida', function () {
    $provider = new FakeProvider();

    try {
        (new ExecutionLogService($provider))->record(exec_(''));
    } catch (InvalidArgumentException) {
        // esperado
    }

    expect($provider->inserted)->toBe([]);
});

it('recusa limite fora da faixa', function (int $limit) {
    (new ExecutionLogService(new FakeProvider()))->recent($limit);
})->with([0, -1, ExecutionLogService::MAX_LIMIT + 1])->throws(InvalidArgumentException::class);

it('aceita os limites das pontas', function () {
    $service = new ExecutionLogService(new FakeProvider());

    expect($service->recent(1))->toBe([])
        ->and($service->recent(ExecutionLogService::MAX_LIMIT))->toBe([]);
});

it('clear() delega e devolve a contagem', function () {
    $provider = new FakeProvider();
    $service  = new ExecutionLogService($provider);
    $service->record(exec_('um'));
    $service->record(exec_('dois'));

    expect($service->clear())->toBe(2)
        ->and($provider->clearCalls)->toBe(1);
});

it('record() devolve o registro com created_at preenchido pelo banco', function () {
    $service = new ExecutionLogService(new FakeProvider());

    $gravado = $service->record(exec_('date'));

    expect($gravado->createdAt)->not->toBeNull()
        ->and($gravado->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('record() preserva os demais campos no retorno', function () {
    $service = new ExecutionLogService(new FakeProvider());

    $gravado = $service->record(exec_('git log', 4321));

    expect($gravado->command)->toBe('git log')
        ->and($gravado->durationMs)->toBe(4321)
        ->and($gravado->exitCode)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Repasse do filtro por tipo
|--------------------------------------------------------------------------
|
| O serviço é o único caminho das telas até os providers: sem o repasse, o
| filtro existiria nos três drivers e nenhuma tela alcançaria. Estes testes
| rodam sempre — não dependem de banco nenhum.
|
*/

it('recent() repassa o tipo ao provider e devolve só aquele tipo', function () {
    $provider = new FakeProvider();
    $service  = new ExecutionLogService($provider);
    $service->record(exec_('um comando'));
    $service->record(exec_('uma acao', 12, ExecutionKind::Windows));

    $lidas = $service->recent(100, [ExecutionKind::Windows]);

    expect($lidas)->toHaveCount(1)
        ->and($lidas[0]->command)->toBe('uma acao')
        ->and($service->recent(100))->toHaveCount(2);
});

it('recent() valida o limite mesmo com tipo informado', function () {
    (new ExecutionLogService(new FakeProvider()))->recent(0, [ExecutionKind::Windows]);
})->throws(InvalidArgumentException::class);

it('clear() repassa o tipo e não leva os outros', function () {
    $provider = new FakeProvider();
    $service  = new ExecutionLogService($provider);
    $service->record(exec_('um comando'));
    $service->record(exec_('uma acao', 12, ExecutionKind::Windows));

    expect($service->clear([ExecutionKind::Windows]))->toBe(1)
        ->and($service->recent(100))->toHaveCount(1)
        ->and($service->recent(100)[0]->kind)->toBe(ExecutionKind::Comando);
});

it('a lista do WSL lê e apaga comando e anexo, e deixa o Windows', function () {
    $service = new ExecutionLogService(new FakeProvider());
    $service->record(exec_('um comando'));
    $service->record(exec_('um anexo', 12, ExecutionKind::Anexo));
    $service->record(exec_('uma acao', 12, ExecutionKind::Windows));

    expect($service->recent(100, ExecutionKind::WSL))->toHaveCount(2)
        ->and($service->recent(100, []))->toBe([])
        ->and($service->clear([]))->toBe(0)
        ->and($service->clear(ExecutionKind::WSL))->toBe(2)
        ->and($service->recent(100)[0]->kind)->toBe(ExecutionKind::Windows);
});

/*
|--------------------------------------------------------------------------
| Estado da /win
|--------------------------------------------------------------------------
|
| O repasse existe porque o serviço é o único caminho das telas até os
| providers. Sem ele, os três drivers teriam os métodos e nenhuma tela
| alcançaria — código nascido morto, esperando outro commit para ter sentido.
|
*/

it('putWinState repassa e devolve com o updated_at carimbado', function () {
    $provider = new FakeProvider();
    $service  = new ExecutionLogService($provider);

    $gravado = $service->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    expect($gravado->updatedAt)->not->toBeNull()
        ->and($provider->winState)->toHaveKey('aplicado:tweaks');
});

it('RECUSA AÇÃO VAZIA antes de chegar ao banco', function () {
    // Mesmo critério do record() com comando vazio: o que não identifica nada
    // não é linha de estado, é lixo que a tela leria como ação desconhecida.
    (new ExecutionLogService(new FakeProvider()))
        ->putWinState(new WinState(WinStateScope::Applied, '   '));
})->throws(InvalidArgumentException::class);

it('winStates repassa o escopo, e um escopo não vê o outro', function () {
    $service = new ExecutionLogService(new FakeProvider());
    $service->putWinState(new WinState(WinStateScope::Applied, 'gdid'));
    $service->putWinState(new WinState(WinStateScope::Selection, 'debloat', ['itens' => 'x']));

    expect(array_keys($service->winStates(WinStateScope::Applied)))->toBe(['gdid'])
        ->and(array_keys($service->winStates(WinStateScope::Selection)))->toBe(['debloat']);
});

it('forgetWinState apaga e devolve quantos, e repetir devolve zero', function () {
    $service = new ExecutionLogService(new FakeProvider());
    $service->putWinState(new WinState(WinStateScope::Applied, 'gdid'));

    expect($service->forgetWinState(WinStateScope::Applied, 'gdid'))->toBe(1)
        ->and($service->forgetWinState(WinStateScope::Applied, 'gdid'))->toBe(0)
        ->and($service->winStates(WinStateScope::Applied))->toBe([]);
});

it('CLEAR NÃO LEVA O ESTADO, nem com tipo nem sem', function () {
    // O fake é escrito para provar isto: um fake que apagasse os dois deixaria
    // o teste passar e esconderia o defeito na tela.
    $service = new ExecutionLogService(new FakeProvider());
    $service->record(exec_('uma acao', 12, ExecutionKind::Windows));
    $service->putWinState(new WinState(WinStateScope::Applied, 'tweaks', ['Preset' => 'standard']));

    expect($service->clear([ExecutionKind::Windows]))->toBe(1)
        ->and($service->winStates(WinStateScope::Applied))->toHaveCount(1);

    expect($service->clear())->toBe(0)
        ->and($service->winStates(WinStateScope::Applied))->toHaveCount(1);
});
