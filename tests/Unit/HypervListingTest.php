<?php

declare(strict_types=1);

use App\Win\HypervListing;

/**
 * A leitura do JSON que o Invoke-Hyperv devolve.
 *
 * É a costura entre o PowerShell e a tela: a saída atravessou o worker e chega
 * como texto, e aqui vira dado tipado ou vira problema — nunca exceção. Testa-se
 * alimentando texto, sem subir PowerShell nenhum.
 */

it('lê o hospedeiro ligado, as VMs e os campos de cada uma', function () {
    $json = '{"hyperv":true,"vms":['
        . '{"name":"Ubuntu","state":"Running","running":true,"memoryBytes":2147483648,"uptimeSeconds":3661,"ip":["192.168.1.5","fe80::1"]},'
        . '{"name":"Win11","state":"Off","running":false,"memoryBytes":0,"uptimeSeconds":0,"ip":[]}'
        . ']}';

    $listing = HypervListing::fromOutput($json);

    expect($listing->problem)->toBeNull()
        ->and($listing->hypervOn)->toBeTrue()
        ->and($listing->vms)->toHaveCount(2);

    $ubuntu = $listing->vms[0];
    expect($ubuntu->name)->toBe('Ubuntu')
        ->and($ubuntu->state)->toBe('Running')
        ->and($ubuntu->running)->toBeTrue()
        ->and($ubuntu->memoryBytes)->toBe(2147483648)
        ->and($ubuntu->uptimeSeconds)->toBe(3661)
        ->and($ubuntu->ip)->toBe(['192.168.1.5', 'fe80::1']);

    $win = $listing->vms[1];
    expect($win->running)->toBeFalse()
        ->and($win->memoryBytes)->toBe(0)
        ->and($win->ip)->toBe([]);
});

it('hospedeiro fora: lê o motivo fechado e a mensagem, sem VMs e sem problema', function (string $motivo) {
    $listing = HypervListing::fromOutput('{"hyperv":false,"motivo":"' . $motivo . '","erro":"Hyper-V is not enabled","vms":[]}');

    expect($listing->hypervOn)->toBeFalse()
        ->and($listing->vms)->toBe([])
        ->and($listing->problem)->toBeNull()
        ->and($listing->reason)->toBe($motivo)
        ->and($listing->error)->toBe('Hyper-V is not enabled');
})->with([
    HypervListing::REASON_FEATURE_OFF,
    HypervListing::REASON_SERVICE_STOPPED,
    HypervListing::REASON_ERROR,
]);

it('motivo ausente ou desconhecido cai em "erro", NUNCA em recurso desligado', function (string $json) {
    $listing = HypervListing::fromOutput($json);

    expect($listing->hypervOn)->toBeFalse()
        ->and($listing->reason)->toBe(HypervListing::REASON_ERROR);
})->with([
    'sem motivo'     => '{"hyperv":false,"erro":"x","vms":[]}',
    'desconhecido'   => '{"hyperv":false,"motivo":"reinicie","vms":[]}',
    'tipo errado'    => '{"hyperv":false,"motivo":1,"vms":[]}',
]);

it('erro vazio ou de tipo errado vira null, não texto em branco', function (string $json) {
    expect(HypervListing::fromOutput($json)->error)->toBeNull();
})->with([
    '{"hyperv":false,"motivo":"erro","erro":"   ","vms":[]}',
    '{"hyperv":false,"motivo":"erro","erro":42,"vms":[]}',
    '{"hyperv":false,"motivo":"erro","vms":[]}',
]);

it('hospedeiro ligado não carrega motivo nem erro', function () {
    $listing = HypervListing::fromOutput('{"hyperv":true,"motivo":"erro","erro":"x","vms":[]}');

    expect($listing->reason)->toBeNull()
        ->and($listing->error)->toBeNull();
});

it('hospedeiro ligado sem nenhuma VM: lista vazia, sem problema', function () {
    $listing = HypervListing::fromOutput('{"hyperv":true,"vms":[]}');

    expect($listing->hypervOn)->toBeTrue()
        ->and($listing->vms)->toBe([])
        ->and($listing->problem)->toBeNull();
});

it('RECORTA a nota que o canal do worker prefixa antes do JSON', function () {
    // O JobChannel pode colar "[phporto] ..." na frente ou atrás da saída. O
    // recorte do primeiro { ao último } acha o JSON no meio do ruído.
    $sujo = "[phporto] Execução recuperada depois do fato.\n"
        . '{"hyperv":true,"vms":[{"name":"Solo","state":"Running","running":true,"memoryBytes":1073741824,"uptimeSeconds":120,"ip":["10.0.0.1"]}]}'
        . "\n[phporto] fim\n";

    $listing = HypervListing::fromOutput($sujo);

    expect($listing->problem)->toBeNull()
        ->and($listing->hypervOn)->toBeTrue()
        ->and($listing->vms)->toHaveCount(1)
        ->and($listing->vms[0]->name)->toBe('Solo')
        ->and($listing->vms[0]->ip)->toBe(['10.0.0.1']);
});

it('saída sem JSON nenhum vira PROBLEMA, não exceção', function (string $saida) {
    $listing = HypervListing::fromOutput($saida);

    expect($listing->hypervOn)->toBeNull()
        ->and($listing->vms)->toBe([])
        ->and($listing->problem)->toBeString();
})->with([
    '',
    '   ',
    '[phporto] A allowlist do PowerShell elevado recusou esta ação.',
    'texto solto sem chaves',
]);

it('JSON sem a chave hyperv vira problema: forma inesperada', function () {
    $listing = HypervListing::fromOutput('{"vms":[]}');

    expect($listing->hypervOn)->toBeNull()
        ->and($listing->problem)->toBeString();
});

it('campos ausentes ou de tipo errado caem em padrões seguros', function () {
    $json = '{"hyperv":true,"vms":[{"name":123,"running":"sim","memoryBytes":"x","uptimeSeconds":null,"ip":["1.1.1.1",42,""]}]}';

    $listing = HypervListing::fromOutput($json);
    $vm      = $listing->vms[0];

    expect($vm->name)->toBe('')
        ->and($vm->state)->toBe('')
        // "sim" não é o booleano true: running fica falso.
        ->and($vm->running)->toBeFalse()
        ->and($vm->memoryBytes)->toBe(0)
        ->and($vm->uptimeSeconds)->toBe(0)
        // Só as strings não vazias entram na lista de IP.
        ->and($vm->ip)->toBe(['1.1.1.1']);
});
