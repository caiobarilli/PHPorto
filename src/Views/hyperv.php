<?php

declare(strict_types=1);

/**
 * A tela /hyperv: a faixa do hospedeiro e a tabela de máquinas virtuais.
 *
 * A FAIXA DECIDE SE HÁ TABELA. Bloqueio (PowerShell elevado desligado),
 * leitura cancelada no teto, saída fora do formato, ou hospedeiro fora — com o
 * motivo real: recurso desligado, serviço parado ou outro erro —: em qualquer
 * um a faixa é a tela inteira e a tabela não aparece. Só o recurso desligado
 * manda reiniciar; os outros não pedem uma ação cara sem motivo. Só com o hospedeiro ligado vem a lista —
 * ou, sem nenhuma VM, uma frase no lugar dela.
 *
 * @var \App\Http\HypervView $view
 */

use App\Http\Respond;
use App\Win\HypervListing;

/** Os estados do Get-VM em português; o desconhecido cai na grafia original. */
$estados = [
    'Running'       => 'rodando',
    'Off'           => 'desligada',
    'Paused'        => 'pausada',
    'Saved'         => 'salva',
    'Starting'      => 'iniciando',
    'Stopping'      => 'parando',
    'Saving'        => 'salvando',
    'Pausing'       => 'pausando',
    'Resuming'      => 'retomando',
    'Reset'         => 'reiniciando',
    'FastSaved'     => 'salva (rápido)',
    'Hibernated'    => 'hibernada',
];

$memoria = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }

    return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
};

$tempo = static function (int $segundos): string {
    if ($segundos < 60) {
        return 'menos de 1 min';
    }

    $dias  = intdiv($segundos, 86400);
    $horas = intdiv($segundos % 86400, 3600);
    $min   = intdiv($segundos % 3600, 60);

    $partes = [];
    if ($dias > 0) {
        $partes[] = $dias . 'd';
    }
    if ($horas > 0) {
        $partes[] = $horas . 'h';
    }
    if ($min > 0 || $partes === []) {
        $partes[] = $min . 'min';
    }

    return implode(' ', $partes);
};

$listing = $view->listing;

?>
<div class="wrap">
  <div class="topbar">
    <h1>Hyper-V</h1>
    <a href="/">voltar</a>
  </div>

  <?php if ($view->blocked !== null): ?>
    <div class="alert alert-block" id="hyperv-faixa">
      <strong>Para ver as máquinas virtuais, o PHPorto precisa do PowerShell elevado ligado.</strong>
      <?= Respond::e($view->blocked) ?>
      <p><a class="btn btn-sm" href="/config#form-ps">Abrir configuração</a></p>
    </div>
  <?php elseif ($view->timedOut): ?>
    <div class="alert alert-block" id="hyperv-faixa">
      <strong>A leitura demorou demais e foi cancelada.</strong>
      Tente atualizar em instantes.
      <p><a class="btn btn-sm" href="/hyperv">Atualizar</a></p>
    </div>
  <?php elseif ($listing === null): ?>
    <div class="alert alert-block" id="hyperv-faixa">A leitura das máquinas virtuais não voltou.</div>
  <?php elseif ($listing->problem !== null): ?>
    <div class="alert alert-block" id="hyperv-faixa"><?= Respond::e($listing->problem) ?></div>
  <?php elseif ($listing->hypervOn === false && $listing->reason === HypervListing::REASON_FEATURE_OFF): ?>
    <div class="alert alert-block" id="hyperv-faixa">
      <strong>O Hyper-V não está ligado no Windows.</strong>
      Ligá-lo é uma mudança no próprio Windows e exige reiniciar o computador — faça pelo
      "Ativar ou desativar recursos do Windows" e reinicie.
    </div>
  <?php elseif ($listing->hypervOn === false && $listing->reason === HypervListing::REASON_SERVICE_STOPPED): ?>
    <div class="alert alert-block" id="hyperv-faixa">
      <strong>O serviço de máquinas virtuais do Windows está parado.</strong>
      Reiniciar o computador costuma resolver; se não, abra <em>Serviços</em> e inicie
      "Gerenciamento de Máquina Virtual do Hyper-V". O PHPorto não inicia o serviço por você.
      <p><a class="btn btn-sm" href="/hyperv">Atualizar</a></p>
    </div>
  <?php elseif ($listing->hypervOn === false): ?>
    <div class="alert alert-block" id="hyperv-faixa">
      <strong>Não deu para ler as máquinas virtuais.</strong>
      O Hyper-V está ligado no Windows, mas a leitura falhou.
      <?php if ($listing->error !== null): ?>
        <details>
          <summary>detalhes técnicos</summary>
          <pre><?= Respond::e($listing->error) ?></pre>
        </details>
      <?php endif; ?>
      <p><a class="btn btn-sm" href="/hyperv">Atualizar</a></p>
    </div>
  <?php else:
      $vms     = $listing->vms;
      $total   = count($vms);
      $rodando = count(array_filter($vms, static fn (\App\Win\HypervVm $v): bool => $v->running));
      ?>
    <p class="sub">
      Hyper-V <span class="ok">ligado</span> &middot;
      <?= $total ?> <?= $total === 1 ? 'máquina virtual' : 'máquinas virtuais' ?> &middot;
      <?= $rodando ?> rodando &middot;
      lido em <?= Respond::e(Respond::dateTime($view->readAtUtc, $view->tz)) ?>
      &middot; <a href="/hyperv" id="hyperv-atualizar">Atualizar</a>
    </p>

    <?php if ($vms === []): ?>
      <p class="empty">Nenhuma máquina virtual neste hospedeiro.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>nome</th>
            <th>estado</th>
            <th>memória</th>
            <th>uptime</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($vms as $vm): ?>
          <tr>
            <td><?= Respond::e($vm->name !== '' ? $vm->name : '(sem nome)') ?></td>
            <td><?= Respond::e($estados[$vm->state] ?? strtolower($vm->state)) ?></td>
            <td><?= $vm->running ? Respond::e($memoria($vm->memoryBytes)) : '—' ?></td>
            <td><?= $vm->running ? Respond::e($tempo($vm->uptimeSeconds)) : '—' ?></td>
            <td>
              <?php if (!$vm->running): ?>
                —
              <?php elseif ($vm->ip === []): ?>
                <span class="meta">não foi possível ler o IP</span>
              <?php else: ?>
                <?= Respond::e(implode(', ', $vm->ip)) ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
