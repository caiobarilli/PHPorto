<?php

declare(strict_types=1);

/**
 * A tela /hyperv: a faixa do hospedeiro e a tabela de máquinas virtuais.
 *
 * A FAIXA DECIDE SE HÁ TABELA. Bloqueio (PowerShell elevado desligado), saída
 * fora do formato, ou Hyper-V desligado no Windows: em qualquer um a faixa é a
 * tela inteira e a tabela não aparece. Só com o hospedeiro ligado vem a lista —
 * ou, sem nenhuma VM, uma frase no lugar dela.
 *
 * @var \App\Http\HypervView $view
 */

use App\Http\Respond;

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
    <div class="alert alert-block">
      <strong>Não deu para listar as máquinas virtuais.</strong>
      <?= Respond::e($view->blocked) ?>
    </div>
  <?php elseif ($listing === null): ?>
    <div class="alert alert-block">A leitura das máquinas virtuais não voltou.</div>
  <?php elseif ($listing->problem !== null): ?>
    <div class="alert alert-block"><?= Respond::e($listing->problem) ?></div>
  <?php elseif ($listing->hypervOn === false): ?>
    <div class="alert alert-block">
      <strong>O Hyper-V não está ligado no Windows.</strong>
      Ligá-lo é uma mudança no próprio Windows e exige reiniciar o computador — faça pelo
      "Ativar ou desativar recursos do Windows" e reinicie.
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
