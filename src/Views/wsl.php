<?php

declare(strict_types=1);

/**
 * A tela /wsl: entrada, saída, anexos e a tabela de registros.
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

?>
<div class="wrap">
  <div class="topbar">
    <h1>WSL</h1>
    <a href="/">voltar</a>
  </div>
  <p class="sub">
    distro <code><?= Respond::e($view->distro) ?></code> &middot;
    raiz <code><?= Respond::e($view->root !== '' ? $view->root : '(não definida)') ?></code> &middot;
    timeout <?= $view->timeout ?>s &middot;
    <?= Respond::e($view->tz) ?> &middot; 127.0.0.1
  </p>

  <?php if ($view->blocked !== null): ?>
    <div class="alert alert-block"><strong>Bloqueado:</strong> <?= Respond::e($view->blocked) ?></div>
  <?php endif; ?>

  <?php if ($view->notice !== null): ?>
    <div class="alert alert-note"><?= Respond::e($view->notice) ?></div>
  <?php endif; ?>

  <section>
    <h2>Entrada</h2>
    <form method="post" action="/wsl" id="form-cmd">
      <input type="hidden" name="acao" value="comando">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <textarea id="input" name="cmd" spellcheck="false" autocomplete="off"
                placeholder="comando de shell&#10;roda dentro do WSL, a partir de PHPORTO_WSL_ROOT"></textarea>
      <div class="row">
        <button type="button" class="btn btn-sm btn-ghost" id="btn-limpar-campo">Limpar campo</button>
        <button type="submit" class="btn btn-sm" id="btn-enviar">Enviar</button>
        <span class="meta" id="estado-cmd"></span>
      </div>
    </form>
    <p class="dica">
      Comando de até <?= number_format($view->maxCommandBytes, 0, ',', '.') ?> bytes. A saída é cortada em
      <?= number_format($view->maxOutputBytes, 0, ',', '.') ?> bytes por execução, e o corte aparece na
      própria saída — para guardar tudo, redirecione para arquivo no comando (<code>… &gt; saida.txt</code>).
    </p>
  </section>

  <section>
    <h2>Saída<?= $view->result !== null ? ' — última execução' : '' ?></h2>
    <textarea id="output" disabled><?= Respond::e($view->result->output ?? '') ?></textarea>
    <div class="row">
      <button type="button" class="btn btn-sm btn-ghost" id="btn-copiar">Copiar</button>
      <button type="button" class="btn btn-sm btn-ghost" id="btn-copiar-json">Copiar como JSON</button>
      <?php if ($view->result !== null): ?>
        <span class="meta">
          <?= $view->result->kind->value === 'anexo' ? 'anexo &middot; ' : '' ?>
          <?= $view->result->timedOut ? 'timeout &middot; ' : '' ?>
          exit
          <strong class="<?= $view->result->exitCode === 0 ? 'ok' : 'bad' ?>"><?= $view->result->exitCode === null ? '-' : $view->result->exitCode ?></strong>
          &middot; <?= Respond::e(number_format($view->result->durationSeconds(), 2, ',', '.')) ?>s
          &middot; <?= Respond::e(Respond::dateTime($view->result->createdAt, $view->tz)) ?>
        </span>
      <?php endif; ?>
    </div>
  </section>

  <section>
    <h2>Anexos</h2>
    <form method="post" action="/wsl" id="form-anexo">
      <input type="hidden" name="acao" value="anexo">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <div class="campo">
        <label for="origem">Origem</label>
        <input type="text" id="origem" name="origem" spellcheck="false" autocomplete="off"
               placeholder="/mnt/c/Users/voce/pasta/arquivo.csv">
      </div>
      <div class="campo">
        <label for="destino">Destino</label>
        <input type="text" id="destino" name="destino" spellcheck="false" autocomplete="off"
               placeholder="~/pasta/arquivo.csv">
      </div>
      <div class="row">
        <button type="submit" class="btn btn-sm" id="btn-anexo">Enviar</button>
        <span class="meta" id="estado-anexo"></span>
      </div>
    </form>
    <p class="dica">
      Os <strong>dois</strong> caminhos são vistos de dentro do WSL — é isso que faz o card servir nos dois sentidos.<br>
      Do Windows para a distro: <code>/mnt/c/...</code> &rarr; <code>/home/voce/...</code><br>
      Da distro para o Windows: <code>/home/voce/...</code> &rarr; <code>/mnt/c/...</code><br>
      Cada caminho aceita até <?= number_format($view->maxPathBytes, 0, ',', '.') ?> bytes.
    </p>
  </section>

  <section>
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

    <form method="post" action="/wsl" id="form-apagar" hidden>
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
(function () {
  var COLD = <?= $view->coldStartSeconds ?>;

  var LOGS = <?= json_encode(
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
  ) ?>;

  var output = document.getElementById('output');

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
  // alguns segundos, e tela parada faz a pessoa clicar de novo.
  function ocupado(form, botao, estado) {
    if (!form) { return; }
    var enviado = false;
    form.addEventListener('submit', function (ev) {
      // Segunda barreira contra a mesma intencao virar duas execucoes. A
      // primeira, e a que garante, e o token de uso unico no servidor: esta
      // aqui so evita que o usuario veja uma recusa que nao precisava existir.
      if (enviado) { ev.preventDefault(); return; }
      enviado = true;
      var t0 = Date.now();
      botao.disabled = true;
      botao.textContent = 'Executando...';
      if (output) { output.value = 'executando...\n'; }
      estado.textContent = 'a VM do WSL pode levar ~' + COLD + 's para acordar';
      setInterval(function () {
        var s = ((Date.now() - t0) / 1000).toFixed(1);
        estado.textContent = s + 's — a VM do WSL pode levar ~' + COLD + 's para acordar';
      }, 100);
    });
  }

  ocupado(
    document.getElementById('form-cmd'),
    document.getElementById('btn-enviar'),
    document.getElementById('estado-cmd')
  );
  ocupado(
    document.getElementById('form-anexo'),
    document.getElementById('btn-anexo'),
    document.getElementById('estado-anexo')
  );

  document.getElementById('btn-limpar-campo').addEventListener('click', function () {
    var input = document.getElementById('input');
    input.value = '';
    input.focus();
  });

  document.getElementById('btn-copiar').addEventListener('click', function () {
    copy(output.value);
    flash(this);
  });

  document.getElementById('btn-copiar-json').addEventListener('click', function () {
    copy(JSON.stringify(LOGS.length ? LOGS[0] : null, null, 2));
    flash(this);
  });

  document.getElementById('btn-json').addEventListener('click', function () {
    copy(JSON.stringify(LOGS, null, 2));
    flash(this);
  });

  // Destrutivo e sem desfazer: confirma antes.
  document.getElementById('btn-apagar').addEventListener('click', function () {
    if (confirm('Apagar os registros do WSL? Os do Windows ficam. Não tem como desfazer.')) {
      document.getElementById('form-apagar').submit();
    }
  });

  document.getElementById('input').focus();
})();
</script>
