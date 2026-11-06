<?php

declare(strict_types=1);

/**
 * @var \App\Http\OllamaView $view
 */

use App\Domain\OllamaExecution;
use App\Http\OllamaView;
use App\Http\Respond;
use App\Services\ExecutionLogService;
use App\Win\Ollama;
use App\Win\OllamaModel;

$st = $view->status;

$mb = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }

    return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
};

$txtDownload = $view->download === null ? null : OllamaView::progressText($view->download, $mb);
$txtPull     = $view->pull === null ? null : OllamaView::progressText($view->pull, $mb);

$form = static function (string $sub, string $rotulo, bool $ok, ?string $modelo = null, string $classe = 'btn btn-sm') use ($view): string {
    return '<form method="post" action="/ollama" style="display:inline">'
        . '<input type="hidden" name="' . Respond::e($view->csrfField) . '" value="' . Respond::e($view->csrfToken) . '">'
        . '<input type="hidden" name="SubAction" value="' . Respond::e($sub) . '">'
        . ($modelo !== null ? '<input type="hidden" name="Model" value="' . Respond::e($modelo) . '">' : '')
        . '<button type="submit" class="' . $classe . '"' . ($ok ? '' : ' disabled') . '>' . Respond::e($rotulo) . '</button></form>';
};

$baixando = $view->downloading() || $view->pulling();
$bt = $view->buttons();
$livreMb = $view->vramFreeMb();
$versaoOk = $view->versionMatch();

$servico = match (true) {
    $view->version !== null => 'em execução',
    $st === null => '—',
    !$st->installed => 'não instalado',
    $st->processes > 0 => 'processos ativos, API sem resposta',
    default => 'parado',
};

ob_start();
?>
  <section id="ollama-estado">
    <h2>Estado</h2>
    <dl class="kv">
      <dt>Serviço</dt><dd><?= Respond::e($servico) ?></dd>
      <dt>Endpoint</dt><dd><code><?= Ollama::HOST ?>:<?= Ollama::PORT ?></code></dd>
      <dt>Versão</dt>
      <dd><?php if ($view->version === null): ?>sem resposta em 127.0.0.1:<?= Ollama::PORT ?><?php elseif ($versaoOk === true): ?><?= Respond::e($view->version) ?> <span class="tag">igual à fixada <?= Ollama::VERSION ?></span><?php else: ?><?= Respond::e($view->version) ?> <span class="tag">fixada <?= Ollama::VERSION ?></span><?php endif; ?></dd>
      <dt>Instalado</dt><dd><?= $st === null ? '—' : ($st->installed ? 'sim' : 'não') ?></dd>
      <dt>Processos</dt><dd><?= $st === null ? '—' : $st->processes ?></dd>
      <dt>Placa de vídeo</dt>
      <dd><?php if ($st?->gpu === null): ?>—<?php else: ?><?= Respond::e($st->gpu) ?><?php if ($st->vramApprox): ?> <span class="tag">aproximado</span><?php endif; ?><?php endif; ?></dd>
      <dt>VRAM</dt>
      <dd><?php if ($st?->vramMb === null): ?>—<?php else: ?>total <?= (int) $st->vramMb ?> MiB<?php if ($st->vramUsedMb !== null): ?>, usada <?= $st->vramUsedMb ?> MiB, livre <?= (int) $livreMb ?> MiB<?php endif; ?><?php endif; ?></dd>
      <dt>Versão fixada</dt><dd><?= Ollama::VERSION ?> <span class="tag">sha256 <?= substr(Ollama::SHA256, 0, 12) ?>…</span></dd>
      <dt>Modelos carregados</dt>
      <dd><?php if ($view->loaded === null): ?>—<?php elseif ($view->loaded === []): ?>nenhum<?php else: ?>
        <ul>
        <?php foreach ($view->loaded as $m): ?>
          <li><code><?= Respond::e($m['name']) ?></code><?php if ($m['size_vram'] > 0): ?> — <?= intdiv($m['size_vram'], 1048576) ?> MiB na GPU<?php endif; ?><?php if ($m['expires_at'] !== null && $m['expires_at'] !== ''): ?> · expira <?= Respond::e($m['expires_at']) ?><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?></dd>
    </dl>
    <?php if ($st?->hostEnv !== null): ?>
      <div class="alert alert-note" id="ollama-hostenv">A variável <code>OLLAMA_HOST</code> do seu usuário vale <code><?= Respond::e($st->hostEnv) ?></code>. O ícone da bandeja usa esse valor; o Iniciar daqui força <code>127.0.0.1:<?= Ollama::PORT ?></code>.</div>
    <?php endif; ?>
    <p class="dica"><a class="btn btn-sm" href="/ollama?parte=completa" id="ollama-atualizar" data-atualizar>Atualizar status</a></p>
  </section>

  <section id="ollama-instalacao">
    <h2>Instalação</h2>
    <p>
      <?= $form('install', $view->installerReady ? 'Instalar' : 'Baixar instalador', $bt['install']) ?>
      <?= $form('uninstall', 'Desinstalar', $bt['uninstall'], null, 'btn btn-sm btn-danger') ?>
      <?= $form('start', 'Iniciar', $bt['start']) ?>
      <?= $form('stop', 'Parar', $bt['stop']) ?>
    </p>
    <?php if ($txtDownload !== null): ?>
      <p class="dica" id="ollama-download">Instalador: <?= Respond::e($txtDownload) ?></p>
    <?php endif; ?>
  </section>

  <section id="ollama-modelos">
    <h2>Modelos</h2>
    <p>
      <button type="button" class="btn btn-sm" id="btn-ollama-adicionar"<?= $view->canAddModel() ? '' : ' disabled' ?>>Adicionar novo</button>
    </p>
    <table>
      <thead><tr><th>Modelo</th><th>Tamanho</th><th>VRAM mínima</th><th>Baixado</th><th></th></tr></thead>
      <tbody>
      <?php foreach (OllamaModel::recommended() as $m): ?>
        <?php $tem = isset($view->models[$m->value]); ?>
        <?php $mbt = $view->modelButtons($m->value); ?>
        <tr>
          <td><?= Respond::e($m->value) ?><?php if ($view->isLoaded($m->value)): ?> <span class="tag">carregado</span><?php endif; ?></td>
          <td><?= (int) $m->sizeMb() ?> MiB</td>
          <td><?= (int) $m->minVramMb() ?> MiB<?php if ($view->fitsInGpu($m->value) === false): ?><br><span class="dica">não cabe inteiro na GPU (usa a CPU também)</span><?php endif; ?></td>
          <td><?= $view->models === null ? '—' : ($tem ? $mb($view->models[$m->value]) : 'não') ?></td>
          <td>
            <?= $form('pull', 'Baixar', $mbt['pull'], $m->value) ?>
            <?= $form('remove', 'Remover', $mbt['remove'], $m->value, 'btn btn-sm btn-danger') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($view->extraModels() as $nome => $tamanho): ?>
        <?php $mbt = $view->modelButtons($nome); ?>
        <tr>
          <td><code><?= Respond::e($nome) ?></code><?php if ($view->isLoaded($nome)): ?> <span class="tag">carregado</span><?php endif; ?></td>
          <td><?= OllamaModel::sizeMbFromBytes($tamanho) ?> MiB</td>
          <td><?= OllamaModel::minVramFromBytes($tamanho) ?> MiB<?php if ($view->fitsInGpu($nome) === false): ?><br><span class="dica">não cabe inteiro na GPU (usa a CPU também)</span><?php endif; ?></td>
          <td><?= $mb($tamanho) ?></td>
          <td>
            <?= $form('remove', 'Remover', $mbt['remove'], $nome, 'btn btn-sm btn-danger') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($txtPull !== null): ?>
      <p class="dica" id="ollama-pull">Último download de modelo: <?= Respond::e($txtPull) ?></p>
    <?php endif; ?>
  </section>
<?php
$corpo = ob_get_clean();

if ($view->parte === OllamaView::PARTE_ESTADO) {
    echo '<div id="ollama-leitura">' . $corpo . '</div>';

    return;
}
?>
<div class="wrap">
  <div class="topbar">
    <h1>Ollama</h1>
    <a href="/">voltar</a>
    <a href="/config">configuração</a>
  </div>
  <p class="sub">Modelos locais rodando como o seu usuário, sem administrador. O servidor escuta só em <code>127.0.0.1:<?= Ollama::PORT ?></code>; o PHPorto não mexe no firewall.</p>

  <?php if ($view->notice !== null): ?>
    <div class="alert alert-note" data-aviso><?= Respond::e($view->notice) ?></div>
  <?php endif; ?>

  <?php if ($view->blocked !== null): ?>
    <div class="alert alert-block" id="ollama-recusa"><?= Respond::e($view->blocked) ?></div>
  <?php endif; ?>

  <div id="ollama-leitura">
<?= $corpo ?>
  </div>

  <section id="ollama-historico">
    <h2>Histórico</h2>
    <?php if ($view->historyFailed !== null): ?>
      <div class="alert alert-block"><?= Respond::e($view->historyFailed) ?></div>
    <?php elseif ($view->history === []): ?>
      <p class="empty">Nenhuma ação do Ollama registrada ainda.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Ação</th>
            <th>Modelo</th>
            <th>Saída</th>
            <th>Usuário</th>
            <th>Status</th>
            <th>Quando</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($view->history as $row): ?>
            <tr>
              <td><code><?= Respond::e($row->action->value) ?></code><?php if ($row->prompt !== null): ?> <span class="tag">chat</span><?php endif; ?></td>
              <td><?= $row->model === null ? '—' : '<code>' . Respond::e($row->model) . '</code>' ?></td>
              <td><pre><?= Respond::e($row->output) ?></pre><?php if ($row->prompt !== null && $row->prompt !== ''): ?><pre class="meta"><?= Respond::e($row->prompt) ?></pre><?php endif; ?></td>
              <td><?= Respond::e(OllamaExecution::USER) ?></td>
              <td>
                <?php if ($row->status->value === 'ok'): ?>
                  <span class="ok">ok</span>
                <?php elseif ($row->status->value === 'iniciado'): ?>
                  <span class="tag">iniciado</span>
                <?php else: ?>
                  <span class="bad">erro</span>
                <?php endif; ?>
                <br>
                <span class="meta"><?= Respond::e(number_format($row->durationSeconds(), 1, ',', '.')) ?> s</span>
              </td>
              <td><?= Respond::e(Respond::dateTime($row->createdAt, $view->tz)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($view->historyPages > 1): ?>
        <p class="row">
          <?php if ($view->historyPage > 1): ?>
            <a class="btn btn-sm btn-ghost" href="/ollama?pagina=<?= (int) ($view->historyPage - 1) ?>">Anterior</a>
          <?php endif; ?>
          <span class="meta">página <?= (int) $view->historyPage ?> de <?= (int) $view->historyPages ?> (<?= (int) $view->historyTotal ?> registro(s), <?= (int) ExecutionLogService::OLLAMA_PAGE_SIZE ?> por página)</span>
          <?php if ($view->historyPage < $view->historyPages): ?>
            <a class="btn btn-sm btn-ghost" href="/ollama?pagina=<?= (int) ($view->historyPage + 1) ?>">Próxima</a>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p class="meta"><?= (int) $view->historyTotal ?> registro(s)</p>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="/ollama" id="form-ollama-apagar" style="margin-top:14px">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="acao" value="limpar">
      <div class="row">
        <button type="submit" class="btn btn-danger btn-sm" id="btn-ollama-apagar">Apagar histórico</button>
      </div>
      <p class="dica">Apaga só o histórico do Ollama. Os registros antigos na /win ficam.</p>
    </form>
  </section>
</div>
<dialog id="modal-ollama-adicionar">
  <form method="post" action="/ollama">
    <div class="modal-corpo">
      <h3>Adicionar modelo</h3>
      <p>
        Digite o nome exato do modelo (por exemplo <code>gemma3:1b</code>).
        Para procurar nomes, abra a
        <a href="https://ollama.com/library" target="_blank" rel="noopener noreferrer">biblioteca do Ollama</a>.
      </p>
      <div class="campo">
        <label for="ollama-modelo-novo">Nome</label>
        <input type="text" id="ollama-modelo-novo" name="Model" required maxlength="128" spellcheck="false" autocomplete="off" placeholder="gemma3:1b" pattern="([a-z0-9]([a-z0-9._-]*[a-z0-9])?/)?([a-z0-9]([a-z0-9._-]*[a-z0-9])?/)?[a-z0-9]([a-z0-9._-]*[a-z0-9])?(:[a-z0-9]([a-z0-9._-]*[a-z0-9])?)?">
      </div>
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="SubAction" value="pull">
    </div>
    <div class="modal-acoes">
      <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
      <button type="submit" class="btn btn-sm">Baixar</button>
    </div>
  </form>
</dialog>
<?php if ($view->confirmPull()): ?>
<?php $confirmNome = (string) $view->confirmModel; ?>
<dialog id="modal-ollama-confirmar">
  <form method="post" action="/ollama">
    <div class="modal-corpo">
      <h3>Baixar modelo sem tamanho conhecido?</h3>
      <p>O tamanho e a VRAM necessária de <code><?= Respond::e($confirmNome) ?></code> são desconhecidos antes do download.</p>
      <p><?= Respond::e($view->confirmVramText()) ?></p>
      <p><?= Respond::e($view->confirmDiskText()) ?></p>
      <?php if ($view->confirmLibraryUrl() !== null): ?>
        <p>Confira o tamanho na <a href="<?= Respond::e($view->confirmLibraryUrl()) ?>" target="_blank" rel="noopener noreferrer">página do modelo na origem</a>.</p>
      <?php else: ?>
        <p>confira o tamanho na origem do modelo</p>
      <?php endif; ?>
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="SubAction" value="pull">
      <input type="hidden" name="Model" value="<?= Respond::e($confirmNome) ?>">
      <input type="hidden" name="<?= Respond::e(Ollama::CONFIRM_PULL) ?>" value="1">
    </div>
    <div class="modal-acoes">
      <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
      <button type="submit" class="btn btn-sm" id="btn-ollama-confirmar-baixar">Baixar mesmo assim</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($baixando): ?>
<script>
setTimeout(function () { location.replace('/ollama'); }, 5000);
</script>
<?php endif; ?>
<script>
(function () {
  'use strict';

  var formApagar = document.getElementById('form-ollama-apagar');
  if (formApagar) {
    formApagar.addEventListener('submit', function (ev) {
      if (!confirm('Apagar o histórico do Ollama? Não tem como desfazer.')) {
        ev.preventDefault();
      }
    });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('#btn-ollama-adicionar');
    if (!btn || btn.disabled) { return; }
    var modal = document.getElementById('modal-ollama-adicionar');
    if (modal && modal.showModal) { modal.showModal(); }
  });

  var confirmar = document.getElementById('modal-ollama-confirmar');
  if (confirmar && confirmar.showModal) { confirmar.showModal(); }

  if (!window.fetch || !window.DOMParser) { return; }

  var lendo = false;

  function falhou(alvo) {
    var aviso = alvo.querySelector('[data-lendo]') || alvo.appendChild(document.createElement('p'));
    aviso.setAttribute('data-lendo', '');
    aviso.className = 'sub';
    aviso.textContent = 'A leitura não voltou. Use Atualizar status para tentar de novo.';
    var link = document.createElement('a');
    link.href = '/ollama?parte=completa';
    link.setAttribute('data-atualizar', '');
    link.className = 'btn btn-sm';
    link.textContent = 'Atualizar status';
    aviso.appendChild(document.createTextNode(' '));
    aviso.appendChild(link);
  }

  function ler() {
    var alvo = document.getElementById('ollama-leitura');
    if (!alvo || lendo) { return; }
    lendo = true;
    alvo.setAttribute('aria-busy', 'true');
    document.querySelectorAll('[data-atualizar]').forEach(function (a) { a.textContent = 'lendo…'; });

    fetch('/ollama?parte=estado', {
      credentials: 'same-origin',
      mode: 'same-origin',
      cache: 'no-store',
      headers: { 'X-PHPorto-Sem-Reload': '1' }
    }).then(function (r) {
      var html = (r.headers.get('Content-Type') || '').indexOf('text/html') === 0;
      if (!r.ok || !html || new URL(r.url).origin !== location.origin || new URL(r.url).pathname !== '/ollama') {
        location.assign('/ollama?parte=completa');
        return null;
      }
      return r.text();
    }).then(function (texto) {
      lendo = false;
      if (texto === null) { return; }
      var novo = new DOMParser().parseFromString(texto, 'text/html').getElementById('ollama-leitura');
      var atual = document.getElementById('ollama-leitura');
      if (!novo || !atual) { throw new Error('sem estado'); }
      atual.replaceWith(document.adoptNode(novo));
    }).catch(function () {
      lendo = false;
      var atual = document.getElementById('ollama-leitura');
      if (atual) { atual.removeAttribute('aria-busy'); falhou(atual); }
    });
  }

  document.addEventListener('click', function (ev) {
    var link = ev.target.closest('[data-atualizar]');
    if (!link || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey) { return; }
    ev.preventDefault();
    ler();
  });
})();
</script>
