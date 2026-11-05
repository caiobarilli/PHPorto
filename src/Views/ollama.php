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

  <section id="ollama-estado">
    <h2>Estado</h2>
    <dl class="kv">
      <dt>Instalado</dt><dd><?= $st === null ? '—' : ($st->installed ? 'sim' : 'não') ?></dd>
      <dt>Processos</dt><dd><?= $st === null ? '—' : $st->processes ?></dd>
      <dt>API</dt><dd><?= $view->version !== null ? 'respondendo, versão ' . Respond::e($view->version) : 'sem resposta em 127.0.0.1:' . Ollama::PORT ?></dd>
      <dt>Placa de vídeo</dt>
      <dd><?php if ($st?->gpu === null): ?>—<?php else: ?><?= Respond::e($st->gpu) ?>, <?= (int) $st->vramMb ?> MiB<?= $st->vramUsedMb !== null ? ' (' . $st->vramUsedMb . ' MiB em uso)' : '' ?><?php if ($st->vramApprox): ?> <span class="tag">aproximado</span><?php endif; ?><?php endif; ?></dd>
      <dt>Versão fixada</dt><dd><?= Ollama::VERSION ?> <span class="tag">sha256 <?= substr(Ollama::SHA256, 0, 12) ?>…</span></dd>
    </dl>
    <?php if ($st?->hostEnv !== null): ?>
      <div class="alert alert-note" id="ollama-hostenv">A variável <code>OLLAMA_HOST</code> do seu usuário vale <code><?= Respond::e($st->hostEnv) ?></code>. O ícone da bandeja usa esse valor; o Iniciar daqui força <code>127.0.0.1:<?= Ollama::PORT ?></code>.</div>
    <?php endif; ?>
    <p class="dica"><a href="/ollama">Atualizar</a></p>
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
          <td><?= Respond::e($m->value) ?></td>
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
</div>
<?php if ($baixando): ?>
<script>
setTimeout(function () { location.replace('/ollama'); }, 5000);
</script>
<?php endif; ?>
