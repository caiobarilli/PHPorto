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

/**
 * Os estados do Get-VM em português leigo; o desconhecido cai na grafia
 * original. "Salva" sozinho não diz nada para quem não conhece Hyper-V.
 */
$estados = [
    'Running'       => 'rodando',
    'Off'           => 'desligada',
    'Paused'        => 'pausada',
    'Saved'         => 'salva (parada, retoma de onde estava)',
    'Starting'      => 'iniciando',
    'Stopping'      => 'desligando',
    'Saving'        => 'salvando',
    'Pausing'       => 'pausando',
    'Resuming'      => 'retomando',
    'Reset'         => 'reiniciando',
    'FastSaved'     => 'salva (parada, retoma de onde estava)',
    'Hibernated'    => 'hibernada',
];

/** Parada de vez: cinza. Rodando é verde; o resto (transitório) fica neutro. */
$paradas = ['Off', 'Saved', 'FastSaved', 'Hibernated'];

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
      $lidoEm  = Respond::dateTime($view->readAtUtc, $view->tz);
      ?>
    <p class="sub" id="hyperv-resumo">
      <?php if ($total === 0): ?>
        Hyper-V <span class="ok">ligado</span>
      <?php else: ?>
        <strong><?= $rodando ?> de <?= $total ?> <?= $total === 1 ? 'ligada' : 'ligadas' ?></strong>
      <?php endif; ?>
      &middot; lido às <span title="<?= Respond::e($lidoEm) ?>"><?= Respond::e(preg_match('/ (\d\d:\d\d)/', $lidoEm, $hora) === 1 ? $hora[1] : $lidoEm) ?></span>
      &middot; <a href="/hyperv" id="hyperv-atualizar">Atualizar</a>
    </p>

    <?php if ($vms === []): ?>
      <p class="empty">Nenhuma máquina virtual neste computador.</p>
    <?php else: ?>
      <table id="hyperv-tabela">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Situação</th>
            <th>Memória em uso</th>
            <th>Ligada há</th>
            <th>Endereço IP</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($vms as $vm):
            $classe = $vm->running ? 'ok' : (in_array($vm->state, $paradas, true) ? 'vm-parada' : '');
            $uteis  = $vm->usefulIps();
            $ip     = $uteis[0] ?? null;
            $outros = array_slice($uteis, 1);
            ?>
          <tr>
            <td><?= Respond::e($vm->name !== '' ? $vm->name : '(sem nome)') ?></td>
            <td><span<?= $classe !== '' ? ' class="' . $classe . '"' : '' ?>>&#9679;</span> <?= Respond::e($estados[$vm->state] ?? strtolower($vm->state)) ?></td>
            <td><?= $vm->running ? Respond::e($memoria($vm->memoryBytes)) : '—' ?></td>
            <td><?= $vm->running ? Respond::e($tempo($vm->uptimeSeconds)) : '—' ?></td>
            <td>
              <?php if (!$vm->running): ?>
                —
              <?php elseif ($ip === null): ?>
                <span class="vm-parada">não foi possível ler o IP</span>
              <?php else: ?>
                <code class="vm-ip"><?= Respond::e($ip) ?></code>
                <button type="button" class="btn btn-ghost btn-sm" data-copiar="<?= Respond::e($ip) ?>" hidden>copiar</button>
                <?php if ($outros !== []): ?>
                  <details>
                    <summary>mais endereços</summary>
                    <?= Respond::e(implode(', ', $outros)) ?>
                  </details>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
/*
 * "copiar" ao lado do IP. Só no navegador, sem requisição: em 127.0.0.1 o
 * navigator.clipboard funciona; fora de contexto seguro, seleciona o texto
 * para o Ctrl+C. Sem JavaScript o botão nem aparece.
 */
(function () {
  'use strict';

  document.querySelectorAll('[data-copiar]').forEach(function (b) { b.hidden = false; });

  document.addEventListener('click', function (ev) {
    var botao = ev.target.closest('[data-copiar]');
    if (!botao) { return; }

    var texto = botao.getAttribute('data-copiar');
    var avisar = function (rotulo) {
      botao.textContent = rotulo;
      setTimeout(function () { botao.textContent = 'copiar'; }, 1500);
    };
    var selecionar = function () {
      var alvo = botao.previousElementSibling;
      if (!alvo) { return; }
      var faixa = document.createRange();
      faixa.selectNodeContents(alvo);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(faixa);
      avisar('selecionado');
    };

    if (window.isSecureContext && navigator.clipboard) {
      navigator.clipboard.writeText(texto).then(function () { avisar('copiado'); }, selecionar);
    } else {
      selecionar();
    }
  });
})();
</script>
