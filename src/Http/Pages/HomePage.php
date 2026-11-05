<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Http\HomeView;
use App\Http\Respond;

final class HomePage extends Page
{
    public function handle(): never
    {
        $status = $this->distroChecker->status();
        $win    = $this->elevation->state();

        $view = new HomeView(
            wslEnabled: $status->isUsable(),
            wslReason: $status->reason($this->config['wsl']['distro']),
            distro: $this->config['wsl']['distro'],
            status: $status,
            winEnabled: $win->on,
            winReason: $win->blocked
                ?? $win->detail
                ?? 'O PowerShell elevado está desligado. Ligue na configuração — ele não sobrevive a reiniciar o servidor.',
            hypervEnabled: $this->hyperv->enabled(),
            hypervReason: 'O painel do Hyper-V está desligado. Ligue na configuração — uma vez ligado, ele sobrevive a reiniciar.',
        );

        Respond::html('PHPorto', Respond::render('home.php', $view));
    }
}
