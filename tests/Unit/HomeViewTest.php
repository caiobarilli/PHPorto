<?php

declare(strict_types=1);

use App\Http\HomeView;
use App\Http\Respond;
use App\Wsl\DistroStatus;

/** A home com o WIN e o painel do Hyper-V dados; o WSL fica sempre usável. */
function homeHtml(bool $win, bool $hyperv): string
{
    return Respond::render('home.php', new HomeView(
        wslEnabled: true,
        wslReason: '',
        distro: 'Ubuntu',
        status: DistroStatus::Ok,
        winEnabled: $win,
        winReason: 'O PowerShell elevado está desligado.',
        hypervEnabled: $hyperv,
        hypervReason: 'O painel do Hyper-V está desligado.',
    ));
}

it('painel ligado e PowerShell elevado desligado: botão ativo, e a nota avisa antes do clique', function () {
    $html = homeHtml(win: false, hyperv: true);

    expect($html)->toContain('<a class="btn" href="/hyperv">')
        ->and($html)->toContain('<strong>Hyper-V:</strong> precisa do PowerShell elevado ligado');
});

it('painel ligado e PowerShell elevado ligado: nenhuma nota do Hyper-V', function () {
    $html = homeHtml(win: true, hyperv: true);

    expect($html)->toContain('<a class="btn" href="/hyperv">')
        ->and($html)->not->toContain('<strong>Hyper-V:</strong>');
});

it('painel desligado: a nota é a de sempre, e não a do PowerShell', function () {
    $html = homeHtml(win: false, hyperv: false);

    expect($html)->toContain('<strong>Hyper-V:</strong> O painel do Hyper-V está desligado.')
        ->and($html)->not->toContain('precisa do PowerShell elevado ligado');
});
