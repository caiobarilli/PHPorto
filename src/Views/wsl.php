<?php

declare(strict_types=1);

/**
 * A tela /wsl: o console (saída em cima, prompt embaixo), anexos e registros.
 *
 * O estado "executando" aparece no PRIMEIRO clique, com contador de segundos e
 * o aviso do custo de acordar a VM. Sem isso, os ~4,5 s do primeiro comando do
 * dia são tela parada, e tela parada faz a pessoa clicar de novo.
 *
 * @var \App\Http\WslView $view
 */

use App\Http\Respond;

$corta = static function (string $texto, int $maxLinhas = 12, int $maxChars = 1200): string {
    $texto  = rtrim($texto, "\n");
    $linhas = $texto === '' ? [] : explode("\n", $texto);
    $cortou = false;

    if (count($linhas) > $maxLinhas) {
        $linhas = array_slice($linhas, 0, $maxLinhas);
        $cortou = true;
    }

    $curto = implode("\n", $linhas);

    if (strlen($curto) > $maxChars) {
        $curto  = substr($curto, 0, $maxChars);
        $cortou = true;
    }

    return $cortou ? rtrim($curto) . "\n[... truncado ...]" : $curto;
};

/**
 * Os registros que os botões de copiar entregam, como JSON. Vão num atributo
 * data-logs da seção, e não numa variável do script: depois de uma ação sem
 * reload o miolo é trocado e o atributo chega fresco, o script não.
 */
$logs = (string) json_encode(
    array_map(static fn (\App\Domain\Execution $e): array => [
        'kind'        => $e->kind->value,
        'command'     => $e->command,
        'output'      => $e->output,
        'exit_code'   => $e->exitCode,
        'duration_ms' => $e->durationMs,
        'timed_out'   => $e->timedOut,
        'created_at'  => $e->createdAt,
    ], $view->rows),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

?>
<div class="wrap">
  <div class="topbar">
    <h1>WSL</h1>
    <a href="/">voltar</a>
  </div>
  <p class="sub">
    distro <code><?= Respond::e($view->distro) ?></code>
    <?php if ($view->awake === true): ?>
      <span class="ok">acordada</span>
    <?php elseif ($view->awake === false): ?>
      <span class="meta">dormindo — o primeiro comando a acorda, em ~<?= $view->coldStartSeconds ?> s</span>
    <?php endif; ?>
    &middot;
    raiz <code><?= Respond::e($view->root !== '' ? $view->root : '(não definida)') ?></code> &middot;
    timeout <?= $view->timeout ?>s &middot;
    <?= Respond::e($view->tz) ?> &middot; 127.0.0.1
  </p>

  <?php if ($view->blocked !== null): ?>
    <div class="alert alert-block"><strong>Bloqueado:</strong> <?= Respond::e($view->blocked) ?></div>
  <?php endif; ?>

  <?php if ($view->notice !== null): ?>
    <div class="alert alert-note" data-aviso><?= Respond::e($view->notice) ?></div>
  <?php endif; ?>

  <div class="term" aria-label="Console WSL">
    <div class="term-bar">
      <span><strong>wsl</strong> &middot; <?= Respond::e($view->distro) ?><?= $view->root !== '' ? ' &middot; ' . Respond::e($view->root) : '' ?></span>
      <div class="term-btns">
        <button type="button" class="term-btn" id="btn-copiar">Copiar</button>
        <button type="button" class="term-btn" id="btn-copiar-json">Copiar JSON</button>
      </div>
    </div>
    <pre class="term-scroll" id="output"><?= Respond::e($view->result->output ?? '') ?></pre>
    <form method="post" action="/wsl" id="form-cmd" class="term-prompt" data-sem-reload>
      <input type="hidden" name="acao" value="comando">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <span class="term-ps" aria-hidden="true">$</span>
      <textarea id="input" name="cmd" rows="1" spellcheck="false" autocomplete="off" aria-label="comando"
                placeholder="comando de shell — roda no WSL, a partir de PHPORTO_WSL_ROOT"></textarea>
      <button type="submit" class="term-go" id="btn-enviar">Enviar</button>
    </form>
    <div class="term-meta">
      <span>Enter envia, Shift+Enter quebra a linha. Não é PTY. <span id="estado-cmd"></span></span>
      <?php if ($view->result !== null): ?>
        <span>
          <?= $view->result->kind->value === 'anexo' ? 'anexo &middot; ' : '' ?>
          <?= $view->result->timedOut ? 'timeout &middot; ' : '' ?>
          exit
          <strong class="<?= $view->result->exitCode === 0 ? 'ok' : 'bad' ?>"><?= $view->result->exitCode === null ? '-' : $view->result->exitCode ?></strong>
          &middot; <?= Respond::e(number_format($view->result->durationSeconds(), 2, ',', '.')) ?>s
          &middot; <?= Respond::e(Respond::dateTime($view->result->createdAt, $view->tz)) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <p class="dica term-dica">
    Comando de até <?= number_format($view->maxCommandBytes, 0, ',', '.') ?> bytes. A saída é cortada em
    <?= number_format($view->maxOutputBytes, 0, ',', '.') ?> bytes por execução, e o corte aparece na
    própria saída — para guardar tudo, redirecione para arquivo no comando (<code>… &gt; saida.txt</code>).
  </p>

  <section class="anexos">
    <h2>Anexos</h2>
    <form method="post" action="/wsl" id="form-anexo" data-sem-reload>
      <input type="hidden" name="acao" value="anexo">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <div class="anexo-caminhos">
        <div class="campo">
          <label for="origem">Origem</label>
          <input type="text" id="origem" name="origem" value="" spellcheck="false" autocomplete="off" placeholder="/mnt/c/…">
        </div>
        <div class="campo">
          <label for="destino">Destino</label>
          <input type="text" id="destino" name="destino" value="" spellcheck="false" autocomplete="off" placeholder="~/…">
        </div>
      </div>
      <div class="row">
        <button type="submit" class="btn btn-sm btn-claro" id="btn-anexo">Enviar</button>
        <button type="button" class="btn btn-sm btn-ghost" id="btn-inverter" title="Inverter origem e destino">Inverter</button>
        <button type="button" class="btn btn-sm btn-ghost" id="btn-preencher"
                data-origem="<?= Respond::e($view->windowsRoot) ?>" data-destino="<?= Respond::e($view->root) ?>">Preencher</button>
        <span class="meta" id="estado-anexo"></span>
      </div>
    </form>
    <p class="dica">
      Os dois caminhos são vistos de dentro do WSL. Cada caminho aceita até <?= number_format($view->maxPathBytes, 0, ',', '.') ?> bytes.
    </p>
  </section>

  <section id="registros" data-logs="<?= Respond::e($logs) ?>">
    <h2>Registros</h2>
    <?php if ($view->rows === []): ?>
      <p class="empty">Nenhuma execução registrada ainda.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th class="c-in">comando</th><th class="c-out">saída</th><th class="c-dt">quando</th></tr>
        </thead>
        <tbody>
        <?php foreach ($view->rows as $row): ?>
          <tr>
            <td>
              <?php if ($row->kind->value === 'anexo'): ?><span class="tag">anexo</span><?php endif; ?>
              <?php if ($row->timedOut): ?><span class="tag">timeout</span><?php endif; ?>
              <pre><?= Respond::e($row->command) ?></pre>
            </td>
            <td><pre><?= Respond::e($corta($row->output)) ?></pre></td>
            <td>
              <?= Respond::e(Respond::dateTime($row->createdAt, $view->tz)) ?><br>
              <span class="meta">exit <?= $row->exitCode === null ? '-' : $row->exitCode ?>
                &middot; <?= Respond::e(number_format($row->durationSeconds(), 2, ',', '.')) ?>s</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post" action="/wsl" id="form-apagar" data-sem-reload hidden>
      <input type="hidden" name="acao" value="limpar">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
    </form>

    <div class="row">
      <button type="button" class="btn btn-sm btn-ghost" id="btn-json">Copiar como JSON</button>
      <button type="button" class="btn btn-sm btn-danger" id="btn-apagar">Apagar registros do WSL</button>
      <span class="meta"><?= count($view->rows) ?> registro(s)</span>
    </div>
  </section>
</div>

<script>
/*
 * Os formulários desta tela vão pelo script comum do layout, sem reload: o
 * miolo é trocado depois de cada ação, então tudo aqui é por delegação no
 * document, e os registros para copiar vêm do data-logs, fresco a cada troca.
 */
(function () {
  'use strict';

  var COLD = <?= $view->coldStartSeconds ?>;
  var relogio = null;

  function logs() {
    var secao = document.getElementById('registros');
    return secao ? JSON.parse(secao.getAttribute('data-logs') || '[]') : [];
  }

  function copy(text) {
    text = text == null ? '' : String(text);
    try {
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).catch(function () { legacy(text); });
        return;
      }
    } catch (e) { /* segue para o fallback */ }
    legacy(text);
  }

  function legacy(text) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    } catch (e) { /* silencio */ }
  }

  function flash(btn) {
    var old = btn.textContent;
    btn.textContent = 'Copiado';
    setTimeout(function () { btn.textContent = old; }, 900);
  }

  // O estado de trabalho entra no PRIMEIRO clique: acordar a VM do WSL custa
  // alguns segundos, e tela parada faz a pessoa clicar de novo. O botão e a
  // trava são do script comum; aqui fica o aviso da VM, e o cronômetro para
  // quando o miolo é trocado.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var estado = form.id === 'form-cmd' ? 'estado-cmd' : (form.id === 'form-anexo' ? 'estado-anexo' : null);
    if (!estado || ev.defaultPrevented) { return; }

    estado = document.getElementById(estado);
    var output = document.getElementById('output');
    var t0 = Date.now();
    if (output) { output.textContent = 'executando...\n'; }
    clearInterval(relogio);
    relogio = setInterval(function () {
      var s = ((Date.now() - t0) / 1000).toFixed(1);
      estado.textContent = s + 's — a VM do WSL pode levar ~' + COLD + 's para acordar';
    }, 100);
  });

  var veioDoConsole = false;
  document.addEventListener('submit', function (ev) { veioDoConsole = ev.target.id === 'form-cmd'; });

  document.addEventListener('phporto:trocou', function () {
    clearInterval(relogio);
    if (veioDoConsole) { document.getElementById('input').focus(); }
  });

  // O campo parece uma linha: Enter envia pelo botão, Shift+Enter quebra a
  // linha e o campo cresce até um teto, sem virar outra caixa.
  document.addEventListener('keydown', function (ev) {
    if (ev.target.id !== 'input' || ev.key !== 'Enter' || ev.shiftKey || ev.isComposing) { return; }
    ev.preventDefault();
    var botao = document.getElementById('btn-enviar');
    if (botao.disabled) { return; }
    if (botao.form.requestSubmit) { botao.form.requestSubmit(botao); } else { botao.click(); }
  });

  document.addEventListener('input', function (ev) {
    if (ev.target.id !== 'input') { return; }
    ev.target.style.height = 'auto';
    ev.target.style.height = Math.min(ev.target.scrollHeight, 160) + 'px';
  });

  var ACOES = {
    'btn-inverter': function () {
      var origem  = document.getElementById('origem');
      var destino = document.getElementById('destino');
      var antes   = origem.value;
      origem.value  = destino.value;
      destino.value = antes;
    },
    'btn-preencher': function (b) {
      var origem  = b.getAttribute('data-origem') || '';
      var destino = b.getAttribute('data-destino') || '';
      if (origem !== '') { document.getElementById('origem').value = origem; }
      if (destino !== '') { document.getElementById('destino').value = destino; }
    },
    'btn-copiar': function (b) { copy(document.getElementById('output').textContent); flash(b); },
    'btn-copiar-json': function (b) { var l = logs(); copy(JSON.stringify(l.length ? l[0] : null, null, 2)); flash(b); },
    'btn-json': function (b) { copy(JSON.stringify(logs(), null, 2)); flash(b); },
    // Destrutivo e sem desfazer: confirma antes.
    'btn-apagar': function () {
      if (confirm('Apagar os registros do WSL? Os do Windows ficam. Não tem como desfazer.')) {
        var form = document.getElementById('form-apagar');
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
      }
    }
  };

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[id]');
    if (b && ACOES[b.id]) { ACOES[b.id](b); }
  });

  document.getElementById('input').focus();
})();
</script>
