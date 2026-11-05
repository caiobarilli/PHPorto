<?php

declare(strict_types=1);

/**
 * @var \App\Http\OllamaView $view
 */

use App\Http\OllamaView;
use App\Http\Respond;
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
    <table>
      <thead><tr><th>Modelo</th><th>Tamanho</th><th>VRAM mínima</th><th>Baixado</th><th></th></tr></thead>
      <tbody>
      <?php foreach (OllamaModel::cases() as $m): ?>
        <?php $tem = isset($view->models[$m->value]); ?>
        <?php $mbt = $view->modelButtons($m->value); ?>
        <tr>
          <td><?= Respond::e($m->value) ?><?php if ($view->isLoaded($m->value)): ?> <span class="tag">carregado</span><?php endif; ?></td>
          <td><?= $m->sizeMb() ?> MiB</td>
          <td><?= $m->minVramMb() ?> MiB<?php if ($view->fitsInGpu($m->value) === false): ?><br><span class="dica">não cabe inteiro na GPU (usa a CPU também)</span><?php endif; ?></td>
          <td><?= $view->models === null ? '—' : ($tem ? $mb($view->models[$m->value]) : 'não') ?></td>
          <td>
            <?= $form('pull', 'Baixar', $mbt['pull'], $m->value) ?>
            <?= $form('remove', 'Remover', $mbt['remove'], $m->value, 'btn btn-sm btn-danger') ?>
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
</div>
<?php if ($baixando): ?>
<script>
setTimeout(function () { location.replace('/ollama'); }, 5000);
</script>
<?php endif; ?>
<script>
(function () {
  'use strict';

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
