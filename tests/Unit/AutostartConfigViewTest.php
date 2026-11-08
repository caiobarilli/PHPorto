<?php

declare(strict_types=1);

use App\Http\ConfigView;
use App\Http\Respond;
use App\Win\ElevationState;

/**
 * @param array<string, mixed> $extra
 */
function autostartConfigHtml(bool $autostart, ?string $at = null, bool $network = false, array $extra = []): string
{
    $args = [
        'provider' => 'sqlite',
        'details' => [],
        'apiEnabled' => false,
        'apiFromEnv' => false,
        'apiOverridden' => false,
        'corsOrigin' => 'http://127.0.0.1:4001',
        'dbPath' => '',
        'dbExists' => false,
        'notice' => null,
        'csrfToken' => 'tok',
        'csrfField' => '_csrf',
        'win' => new ElevationState(on: false),
        'winRetry' => false,
        'winProofTimeout' => 30,
        'hypervEnabled' => false,
        'hypervEnabledAt' => null,
        'uacOk' => true,
        'uacSummary' => 'UAC ok.',
        'tz' => 'America/Sao_Paulo',
        'networkEnabled' => $network,
        'networkAddress' => $network ? 'http://192.168.1.20:4001' : null,
        'networkAllow' => $network ? ['192.168.1.0/24'] : [],
        'networkUpdatedAt' => $network ? '2026-11-07T10:00:00+00:00' : null,
        'autostartEnabled' => $autostart,
        'autostartUpdatedAt' => $at,
    ];

    return Respond::render('config.php', new ConfigView(...array_replace($args, $extra)));
}

it('a seção Iniciar com o Windows é separada da rede e tem os três botões com CSRF', function () {
    $html = autostartConfigHtml(false);

    expect($html)->toContain('id="iniciar"')->toContain('Iniciar com o Windows')
        ->and($html)->toContain('value="autostart-ligar"')->toContain('value="autostart-desligar"')->toContain('value="autostart-parar"')->toContain('value="autostart-reiniciar"')->toContain('Reiniciar o servidor pela tarefa')
        ->and($html)->toContain('Parar agora')
        ->and(substr_count($html, 'name="_csrf" value="tok"'))->toBeGreaterThanOrEqual(4)
        ->and($html)->toContain('Estado: <strong>desligado</strong>')
        ->and($html)->toContain('id="rede"')
        ->and($html)->toContain('ligar um não liga o outro');
});

it('a seção mostra ligado e a data, independente da rede', function (bool $network) {
    $html = autostartConfigHtml(true, '2026-11-07T10:00:00+00:00', $network);

    expect($html)->toContain('Estado: <strong>ligado</strong>')->toContain('desde <strong>');
})->with([true, false]);

it('a tela não pede, recebe nem guarda senha para o início com o Windows', function () {
    $html = autostartConfigHtml(true, '2026-11-07T10:00:00+00:00');
    [, $trecho] = explode('id="iniciar"', $html, 2);
    $trecho = explode('</section>', $trecho, 2)[0];

    expect($trecho)->not->toMatch('/type="password"|name="[^"]*(senha|password|pass)[^"]*"/i');

    $pagina = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages/ConfigPage.php');
    $inicio = (int) strpos($pagina, 'private function handleAutostart');
    $metodo = substr($pagina, $inicio, (int) strpos($pagina, 'private function handleNetworkOff') - $inicio);

    expect($metodo)->not->toMatch('/\$_POST|password|senha|credential/i');
});

it('só ligar, desligar, reiniciar e parar passam pelo POST da /config, depois do CSRF', function () {
    $pagina = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Pages/ConfigPage.php');

    expect($pagina)->toContain("['autostart-ligar', 'autostart-desligar', 'autostart-parar', 'autostart-reiniciar']")
        ->and(strpos($pagina, 'Csrf::consume()'))->toBeLessThan((int) strpos($pagina, "'autostart-ligar'"));
});

function autostartBotao(string $html, string $id): string
{
    preg_match('/<button[^>]*id="' . $id . '"[^>]*>/', $html, $m);

    return $m[0] ?? '';
}

it('Ligar fica desativado quando a tarefa já está como o esperado, e Desligar e Reiniciar quando não há tarefa', function () {
    $ligado = autostartConfigHtml(true, null, false, ['autostartPresence' => 'presente', 'autostartMatches' => true, 'autostartLogon' => 'Password', 'autostartLevel' => 'médio']);
    $desligado = autostartConfigHtml(false, null, false, ['autostartPresence' => 'ausente', 'autostartMatches' => false, 'autostartLevel' => 'médio']);
    $desconhecido = autostartConfigHtml(false);

    expect(autostartBotao($ligado, 'btn-autostart-ligar'))->toContain(' disabled')
        ->and(autostartBotao($ligado, 'btn-autostart-desligar'))->not->toContain(' disabled')
        ->and(autostartBotao($ligado, 'btn-autostart-reiniciar'))->not->toContain(' disabled')
        ->and(autostartBotao($desligado, 'btn-autostart-ligar'))->not->toContain(' disabled')
        ->and(autostartBotao($desligado, 'btn-autostart-desligar'))->toContain(' disabled')
        ->and(autostartBotao($desligado, 'btn-autostart-reiniciar'))->toContain(' disabled')
        ->and(autostartBotao($desconhecido, 'btn-autostart-ligar'))->not->toContain(' disabled')
        ->and(autostartBotao($desconhecido, 'btn-autostart-desligar'))->not->toContain(' disabled')
        ->and(autostartBotao($ligado, 'btn-autostart-parar'))->not->toContain(' disabled');
});

it('a seção mostra o nível do servidor, o logon da tarefa e avisa do S4U e do nível alto', function () {
    $ok = autostartConfigHtml(true, null, false, ['autostartPresence' => 'presente', 'autostartMatches' => true, 'autostartLogon' => 'Password', 'autostartLevel' => 'médio']);
    $ruim = autostartConfigHtml(true, null, false, ['autostartPresence' => 'presente', 'autostartMatches' => false, 'autostartLogon' => 'S4U', 'autostartLevel' => 'alto']);
    $cego = autostartConfigHtml(false);

    expect($ok)->toContain('Logon da tarefa: <strong>Password</strong>')->toContain('Nível em que o servidor roda: <strong>médio</strong>')->toContain('como o esperado')
        ->and($ok)->not->toContain('recusa subir assim')->not->toContain('sobe elevado')
        ->and($ruim)->toContain('Logon da tarefa: <strong>S4U</strong>')->toContain('sobe elevado')->toContain('diferente do esperado')
        ->and($ruim)->toContain('Nível em que o servidor roda: <strong>alto</strong>')->toContain('recusa subir assim')
        ->and($cego)->toContain('Tarefa no Agendador: <strong>não foi possível ler</strong>')->toContain('Nível em que o servidor roda: <strong>não foi possível ler</strong>');
});

it('a seção explica que a senha é do diálogo do Windows e mostra o XML gerado', function () {
    $html = autostartConfigHtml(false, null, false, ['autostartXmlPath' => 'C:/x/files/PHPorto-tarefa.xml']);

    expect($html)->toContain('senha da conta é pedida só pelo diálogo do Windows')->toContain('taskschd.msc')->toContain('C:/x/files/PHPorto-tarefa.xml');
});
