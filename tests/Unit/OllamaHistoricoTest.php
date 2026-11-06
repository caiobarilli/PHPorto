<?php

declare(strict_types=1);

use App\Domain\OllamaExecution;
use App\Domain\OllamaExecutionAction;
use App\Domain\OllamaExecutionStatus;
use App\Http\OllamaView;
use App\Http\Pages\OllamaPage;
use App\Http\Respond;
use App\Services\ExecutionLogService;
use App\Win\Ollama;
use App\Win\OllamaSub;
use App\Win\OllamaStatus;
use Tests\Fakes\FakeProvider;

function ollamaHistSvc(?FakeProvider $fake = null): array
{
    $provider = $fake ?? new FakeProvider();

    return [$provider, new ExecutionLogService($provider)];
}

it('só pull, remove, install e update entram no histórico; status e list não', function () {
    expect(OllamaPage::logsAction(OllamaSub::Pull))->toBe(OllamaExecutionAction::Pull)
        ->and(OllamaPage::logsAction(OllamaSub::Remove))->toBe(OllamaExecutionAction::Remove)
        ->and(OllamaPage::logsAction(OllamaSub::Install))->toBe(OllamaExecutionAction::Install)
        ->and(OllamaPage::logsAction(OllamaSub::Status))->toBeNull()
        ->and(OllamaPage::logsAction(OllamaSub::List))->toBeNull()
        ->and(OllamaPage::logsAction(OllamaSub::Start))->toBeNull()
        ->and(OllamaPage::logsAction(OllamaSub::Stop))->toBeNull()
        ->and(OllamaPage::logsAction(OllamaSub::Uninstall))->toBeNull()
        ->and(OllamaExecutionAction::Update->value)->toBe('update')
        ->and(OllamaExecutionAction::Chat->value)->toBe('chat');
});

it('grava ações e o usuário é sempre —', function () {
    [$fake, $svc] = ollamaHistSvc();

    $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Install,
        output: 'OLLAMA=instalador-aberto',
        status: OllamaExecutionStatus::Ok,
        durationMs: 12,
    ));
    $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Remove,
        output: 'DELETE /api/delete 200',
        status: OllamaExecutionStatus::Ok,
        durationMs: 5,
        model: 'qwen3:4b',
    ));

    expect($fake->ollama)->toHaveCount(2)
        ->and($fake->ollama[0]->user)->toBe('—')
        ->and($fake->ollama[1]->user)->toBe(OllamaExecution::USER)
        ->and($fake->inserted)->toBe([]);
});

it('o pull fica numa linha iniciado e depois vira ok ou erro com duração do pull.json', function () {
    [$fake, $svc] = ollamaHistSvc();

    $aberta = $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Pull,
        output: Ollama::REGISTRO_PULL_NOVO_CONFIRMADO,
        status: OllamaExecutionStatus::Iniciado,
        durationMs: 0,
        model: 'gemma3:1b',
    ));

    expect($aberta->status)->toBe(OllamaExecutionStatus::Iniciado)
        ->and($aberta->output)->toBe('pull de modelo novo, confirmado')
        ->and($svc->openOllamaPull('gemma3:1b')?->id)->toBe($aberta->id);

    $progressoOk = Ollama::parseProgress('{"status":"success","duration_ms":9400}');
    expect($progressoOk['estado'])->toBe('success')
        ->and($progressoOk['duration_ms'])->toBe(9400);

    $ok = $svc->finalizeOllama((int) $aberta->id, OllamaExecutionStatus::Ok, $aberta->output, $progressoOk['duration_ms']);
    expect($ok?->status)->toBe(OllamaExecutionStatus::Ok)
        ->and($ok?->durationMs)->toBe(9400)
        ->and($svc->openOllamaPull())->toBeNull();

    $erro = $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Pull,
        output: 'pull iniciado',
        status: OllamaExecutionStatus::Iniciado,
        durationMs: 0,
        model: 'qwen3:4b',
    ));
    $progressoErro = Ollama::parseProgress('{"error":"disk full","duration_ms":120}');
    $fim = $svc->finalizeOllama((int) $erro->id, OllamaExecutionStatus::Erro, $erro->output . "\n" . $progressoErro['estado'], $progressoErro['duration_ms']);
    expect($fim?->status)->toBe(OllamaExecutionStatus::Erro)
        ->and($fim?->durationMs)->toBe(120)
        ->and($fim?->output)->toContain('erro: disk full');
});

it('retém no máximo 1000 registros, apagando os mais antigos', function () {
    [$fake, $svc] = ollamaHistSvc();

    for ($i = 0; $i < ExecutionLogService::OLLAMA_RETENTION + 25; $i++) {
        $svc->recordOllama(new OllamaExecution(
            action: OllamaExecutionAction::Remove,
            output: 'n' . $i,
            status: OllamaExecutionStatus::Ok,
            durationMs: 1,
            model: 'qwen3:4b',
        ));
    }

    expect($svc->ollamaPage(1)['total'])->toBe(ExecutionLogService::OLLAMA_RETENTION)
        ->and($fake->ollama[0]->output)->toBe('n25')
        ->and($fake->ollama[count($fake->ollama) - 1]->output)->toBe('n' . (ExecutionLogService::OLLAMA_RETENTION + 24));
});

it('pagina de 50 em 50', function () {
    [, $svc] = ollamaHistSvc();

    for ($i = 0; $i < 120; $i++) {
        $svc->recordOllama(new OllamaExecution(
            action: OllamaExecutionAction::Install,
            output: 'i' . $i,
            status: OllamaExecutionStatus::Ok,
            durationMs: 1,
        ));
    }

    $p1 = $svc->ollamaPage(1);
    $p2 = $svc->ollamaPage(2);
    $p3 = $svc->ollamaPage(3);

    expect($p1['pages'])->toBe(3)
        ->and($p1['rows'])->toHaveCount(50)
        ->and($p2['rows'])->toHaveCount(50)
        ->and($p3['rows'])->toHaveCount(20)
        ->and(OllamaPage::ollamaPagina('2'))->toBe(2)
        ->and(OllamaPage::ollamaPagina('0'))->toBe(1)
        ->and(OllamaPage::ollamaPagina(null))->toBe(1)
        ->and(ExecutionLogService::OLLAMA_PAGE_SIZE)->toBe(50);
});

it('apagar histórico zera a tabela ollama_executions', function () {
    [$fake, $svc] = ollamaHistSvc();
    $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Install,
        output: 'x',
        status: OllamaExecutionStatus::Ok,
        durationMs: 1,
    ));

    expect($svc->clearOllama())->toBe(1)
        ->and($fake->ollama)->toBe([])
        ->and($svc->clearOllama())->toBe(0);
});

it('mascara Password, Pin, token e chave privada antes de gravar', function () {
    [$fake, $svc] = ollamaHistSvc();

    $svc->recordOllama(new OllamaExecution(
        action: OllamaExecutionAction::Chat,
        output: "Authorization: Bearer abc.def\n-Password segredo123 -Pin 4821\napi_key=XYZTOKEN\n-----BEGIN PRIVATE KEY-----\nAAA\n-----END PRIVATE KEY-----",
        status: OllamaExecutionStatus::Ok,
        durationMs: 10,
        model: 'llama3.2:3b',
        prompt: 'password=outro Token xyz',
    ));

    $row = $fake->ollama[0];
    expect($row->output)->not->toContain('segredo123')
        ->and($row->output)->not->toContain('4821')
        ->and($row->output)->not->toContain('abc.def')
        ->and($row->output)->not->toContain('XYZTOKEN')
        ->and($row->output)->not->toContain('AAA')
        ->and($row->output)->toContain('-Password ***')
        ->and($row->output)->toContain('-Pin ***')
        ->and($row->output)->toContain('Authorization: Bearer ***')
        ->and($row->output)->toContain('api_key=***')
        ->and($row->output)->toContain('[chave privada omitida]')
        ->and($row->prompt)->not->toContain('outro')
        ->and($row->prompt)->toContain('password=***')
        ->and($row->action)->toBe(OllamaExecutionAction::Chat);
});

it('a tela mostra o histórico, o usuário —, a paginação e o botão Apagar histórico', function () {
    $rows = [
        new OllamaExecution(
            action: OllamaExecutionAction::Pull,
            output: Ollama::REGISTRO_PULL_NOVO_CONFIRMADO,
            status: OllamaExecutionStatus::Ok,
            durationMs: 9400,
            model: 'gemma3:1b',
            createdAt: '2026-11-06T12:00:00+00:00',
            id: 1,
        ),
    ];

    $view = new OllamaView(
        blocked: null,
        status: OllamaStatus::parse("NIVEL=medio\nEXE=sim\nPROC=1\nGPU=GTX 1650, 4096, 0\n"),
        version: '0.40.13',
        models: ['qwen3:4b' => 1],
        installerReady: true,
        download: null,
        pull: null,
        csrfToken: 'tok',
        csrfField: '_csrf',
        loaded: [],
        history: $rows,
        historyTotal: 51,
        historyPage: 1,
        historyPages: 2,
        tz: 'America/Sao_Paulo',
    );

    $html = Respond::render('ollama.php', $view);

    expect($html)->toContain('id="ollama-historico"')
        ->and($html)->toContain('>Histórico<')
        ->and($html)->toContain('gemma3:1b')
        ->and($html)->toContain('pull de modelo novo, confirmado')
        ->and($html)->toContain('>—<')
        ->and($html)->toContain('id="btn-ollama-apagar"')
        ->and($html)->toContain('>Apagar histórico<')
        ->and($html)->toContain('name="acao" value="limpar"')
        ->and($html)->toContain('href="/ollama?pagina=2"')
        ->and($html)->toContain('Apagar o histórico do Ollama? Não tem como desfazer.')
        ->and($html)->toContain('Os registros antigos na /win ficam.');
});

it('elapsedMs e parseProgress com duration_ms sustentam o finalize do pull', function () {
    expect(OllamaPage::elapsedMs(null))->toBe(0)
        ->and(Ollama::parseProgress('{"status":"success","duration_ms":15}'))
        ->toBe(['estado' => 'success', 'bytes' => 0, 'total' => 0, 'duration_ms' => 15]);
});
