<?php

declare(strict_types=1);

use App\Http\Pages\OllamaPage;
use App\Win\Ollama;
use App\Win\OllamaChannel;
use App\Win\OllamaModel;
use App\Win\OllamaStatus;
use App\Win\OllamaSub;
use App\Win\PsResult;
use App\Win\PsScriptBuilder;

it('a lista recomendada continua com os dois modelos conhecidos', function () {
    expect(array_map(static fn (OllamaModel $m): string => $m->value, OllamaModel::recommended()))
        ->toBe(['qwen3:4b', 'llama3.2:3b']);
    expect(OllamaModel::parse('qwen3:4b')->value)->toBe('qwen3:4b');
    expect(OllamaModel::parse('llama3.2:3b')->value)->toBe('llama3.2:3b');
    expect(OllamaModel::parse('gemma3:1b')->value)->toBe('gemma3:1b');
    expect(OllamaModel::parse('library/phi3:mini')->value)->toBe('library/phi3:mini');
});

it('recusa nome de modelo inválido ou com injeção', function (mixed $valor) {
    expect(fn () => OllamaModel::parse($valor))->toThrow(InvalidArgumentException::class);
})->with([
    ['QWEN3:4B'],
    [' qwen3:4b'],
    ['qwen3:4b; rm'],
    ['qwen3:4b$(reboot)'],
    ['qwen3:4b`id`'],
    ['../etc/passwd'],
    ['a/b/c/d'],
    ['model name'],
    ['model\\tag'],
    [''],
    [null],
    [['qwen3:4b']],
    [42],
    [str_repeat('a', 129)],
]);

it('aceita as oito subações e recusa o resto', function () {
    expect(array_map(static fn (OllamaSub $s): string => $s->value, OllamaSub::cases()))
        ->toBe(['status', 'list', 'install', 'uninstall', 'start', 'stop', 'pull', 'remove']);
    expect(fn () => OllamaSub::parse('serve'))->toThrow(InvalidArgumentException::class);
    expect(fn () => OllamaSub::parse(null))->toThrow(InvalidArgumentException::class);
});

it('recusa instalador com hash diferente do fixado', function () {
    $f = tempnam(sys_get_temp_dir(), 'ollama');
    file_put_contents($f, 'nao e o instalador');

    expect(Ollama::installerMatches($f))->toBeFalse();
    expect(Ollama::installerMatches($f . '.nada'))->toBeFalse();

    unlink($f);
});

it('fixa versão, hash e URL do instalador', function () {
    expect(Ollama::VERSION)->toBe('v0.40.13');
    expect(Ollama::SHA256)->toMatch('/\A[0-9a-f]{64}\z/');
    expect(Ollama::URL)->toBe('https://github.com/ollama/ollama/releases/download/v0.40.13/OllamaSetup.exe');
});

it('fala HTTP só com 127.0.0.1:11434 e só nos caminhos da lista', function () {
    expect(Ollama::apiUrl('/api/tags'))->toBe('http://127.0.0.1:11434/api/tags');
    expect(fn () => Ollama::apiUrl('/api/generate'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Ollama::apiUrl('@evil/api/tags'))->toThrow(InvalidArgumentException::class);
});

it('o fixture do Pester é o script que o PHP gera, em CRLF com BOM e ASCII', function () {
    $fixture = (string) file_get_contents(dirname(__DIR__) . '/Pester/fixtures/ollama-install.ps1');

    expect($fixture)->toBe(PsScriptBuilder::normalize(Ollama::installScript('C:\\PHPorto\\files\\ollama\\OllamaSetup.exe')));
});

it('todos os scripts gerados são ASCII e checam o nível antes de agir', function (string $body) {
    expect(preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $body))->toBe(0);
    expect($body)->toContain("if (\$n -ne 'medio') { exit 5 }");
})->with([
    [Ollama::statusScript()],
    [Ollama::installScript('C:\\x\\OllamaSetup.exe')],
    [Ollama::uninstallScript()],
    [Ollama::startScript()],
    [Ollama::stopScript()],
    [Ollama::childScript('C:\\php\\php.exe', 'C:\\x\\f.php')],
]);

it('lê o nível de integridade e recusa o alto', function () {
    expect(Ollama::nivel("NIVEL=alto\r\n"))->toBe('alto');
    expect(Ollama::nivel("lixo\n"))->toBe('desconhecido');
    expect(OllamaPage::ollamaLevelRefusal(OllamaStatus::parse("NIVEL=alto\n")))->toContain('administrador');
    expect(OllamaPage::ollamaLevelRefusal(OllamaStatus::parse("NIVEL=desconhecido\n")))->not->toBeNull();
    expect(OllamaPage::ollamaLevelRefusal(OllamaStatus::parse("NIVEL=medio\n")))->toBeNull();
    expect(OllamaPage::ollamaLevelRefusal(null))->not->toBeNull();
});

it('script que voltou com nível alto vira recusa', function () {
    $r = new PsResult(output: "NIVEL=alto\r\n", exitCode: 5, timedOut: false, durationMs: 10);

    expect(OllamaPage::ollamaNotice($r))->toContain('administrador');
});

it('lê GPU do nvidia-smi e, sem ele, do CIM como aproximado', function () {
    $s = OllamaStatus::parse("NIVEL=medio\r\nEXE=sim\r\nPROC=2\r\nGPU=NVIDIA GeForce GTX 1650, 4096, 312\r\n");
    expect([$s->installed, $s->processes, $s->gpu, $s->vramMb, $s->vramUsedMb, $s->vramApprox])
        ->toBe([true, 2, 'NVIDIA GeForce GTX 1650', 4096, 312, false]);

    $a = OllamaStatus::parse("NIVEL=medio\nEXE=nao\nPROC=0\nGPUAPROX=NVIDIA GeForce GTX 1650,4095\n");
    expect([$a->installed, $a->vramMb, $a->vramApprox])->toBe([false, 4095, true]);
});

it('recusa pull sem VRAM medida ou com VRAM pequena', function () {
    $gtx = OllamaStatus::parse("NIVEL=medio\nGPU=GTX 1650, 4096, 0\n");
    $pequena = OllamaStatus::parse("NIVEL=medio\nGPU=GT 710, 2048, 0\n");

    expect(Ollama::vramRefusal(OllamaModel::parse('qwen3:4b'), $gtx))->toBeNull();
    expect(Ollama::vramRefusal(OllamaModel::parse('llama3.2:3b'), $gtx))->toBeNull();
    expect(Ollama::vramRefusal(OllamaModel::parse('llama3.2:3b'), $pequena))->not->toBeNull();
    expect(Ollama::vramRefusal(OllamaModel::parse('qwen3:4b'), OllamaStatus::parse("NIVEL=medio\n")))->not->toBeNull();
    expect(Ollama::vramRefusal(OllamaModel::parse('gemma3:1b'), $pequena))->toBeNull();
});

it('lê /api/tags e o progresso do pull e do download', function () {
    expect(Ollama::parseTags('{"models":[{"name":"qwen3:4b","size":2620788019}]}'))->toBe(['qwen3:4b' => 2620788019]);
    expect(Ollama::parseTags('lixo'))->toBe([]);
    expect(Ollama::parseProgress('{"status":"pulling abc","total":100,"completed":40}'))
        ->toBe(['estado' => 'pulling abc', 'bytes' => 40, 'total' => 100]);
    expect(Ollama::parseProgress('{"error":"x"}'))->toBe(['estado' => 'erro: x', 'bytes' => 0, 'total' => 0]);
    expect(Ollama::parseProgress('{"estado":"baixando","bytes":5}'))->toBe(['estado' => 'baixando', 'bytes' => 5, 'total' => 0]);
});

it('o download e o pull filhos são PHP válido com os valores fixos', function () {
    $d = Ollama::downloadChild('C:\\x\\OllamaSetup.exe', 'C:\\x\\baixar.json');
    $p = Ollama::pullChild(OllamaModel::parse('qwen3:4b'), 'C:\\x\\pull.json');

    foreach ([$d, $p] as $codigo) {
        $f = tempnam(sys_get_temp_dir(), 'olc') . '.php';
        file_put_contents($f, $codigo);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f), $saida, $rc);
        unlink($f);
        expect($rc)->toBe(0);
    }

    expect($d)->toContain(var_export(Ollama::SHA256, true))->toContain(var_export(Ollama::URL, true));
    expect($p)->toContain('http://127.0.0.1:11434/api/pull')->toContain('qwen3:4b');
});

it('fora do Windows o canal recusa sem rodar nada', function () {
    $c = new OllamaChannel(sys_get_temp_dir(), false);

    expect($c->blocked())->not->toBeNull();
    expect($c->status())->toBeNull();
    expect($c->api('GET', '/api/tags'))->toBeNull();
});
