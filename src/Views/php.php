<?php

declare(strict_types=1);

/**
 * @var \App\Http\PhpView $view
 */

use App\Http\Respond;

$ativo  = $view->active;
$medido = $view->pathBuild;

?>
<div class="wrap">
  <div class="topbar">
    <h1>PHP</h1>
    <a href="/">voltar</a>
    <a href="/config">configuração</a>
  </div>
  <p class="sub">O interpretador que serve o PHPorto, medido no próprio processo do servidor. Esta tela só lê: trocar a versão ou mudar o <code>php.ini</code> exige fechar o <code>php -S</code> e subir de novo.</p>

  <?php if ($ativo === null): ?>
    <div class="alert alert-block" id="php-falha">Não deu para medir o PHP deste Windows. Confira se <code>php.exe</code> existe e se o PATH está no processo do servidor.</div>
  <?php else: ?>
    <?php if ($view->pathDiverges): ?>
      <div class="alert alert-note" id="php-diverge">O <code>php</code> do PATH não é o que serve o PHPorto. Os dois estão abaixo. Quem serve esta tela é o de <strong>Em uso</strong>.</div>
    <?php endif; ?>

    <section id="php-ativo">
      <h2>Em uso</h2>
      <p class="provider"><?= Respond::e($ativo->version) ?> <span class="tag"><?= Respond::e($ativo->tag()) ?></span></p>
      <dl class="kv" style="margin-top:14px">
        <dt>Binário</dt><dd><?= Respond::e($ativo->binary) ?></dd>
        <dt>php.ini</dt><dd><?= Respond::e($ativo->ini ?? '—') ?></dd>
        <dt>Thread</dt><dd><?= Respond::e($ativo->thread()) ?></dd>
        <dt>SAPI</dt><dd><?= Respond::e($ativo->sapi) ?></dd>
        <dt>upload_progress</dt>
        <?php if ($ativo->uploadProgress === null): ?>
          <dd>— <span class="tag">sem sessão</span></dd>
        <?php else: ?>
          <dd<?= $ativo->uploadProgress && !$ativo->uploadProgressDefault ? ' class="bad"' : '' ?>><?= $ativo->uploadProgress ? 'On' : 'Off' ?><?php if ($ativo->uploadProgressDefault): ?> <span class="tag">default</span><?php endif; ?></dd>
        <?php endif; ?>
      </dl>
      <?php if ($ativo->ini === null): ?>
        <p class="dica">Nenhum <code>php.ini</code> carregado: valem os padrões do PHP.</p>
      <?php endif; ?>
      <?php if ($view->pathBinary === null): ?>
        <p class="dica">Nenhum <code>php</code> no PATH do processo do servidor.</p>
      <?php endif; ?>
    </section>

    <?php if ($view->pathDiverges): ?>
      <section id="php-path">
        <h2>PATH</h2>
        <?php if ($medido === null): ?>
          <p class="dica" style="margin-top:0">Não deu para medir este binário em <?= \App\Php\PhpProbe::MEASURE_TIMEOUT_S ?> s.</p>
          <dl class="kv" style="margin-top:14px">
            <dt>Binário</dt><dd><?= Respond::e($view->pathBinary) ?></dd>
          </dl>
        <?php else: ?>
          <p class="provider"><?= Respond::e($medido->version) ?> <span class="tag"><?= Respond::e($medido->tag()) ?></span></p>
          <dl class="kv" style="margin-top:14px">
            <dt>Binário</dt><dd><?= Respond::e($view->pathBinary) ?></dd>
            <dt>php.ini</dt><dd><?= Respond::e($medido->ini ?? '—') ?></dd>
            <dt>SAPI</dt><dd><?= Respond::e($medido->sapi) ?></dd>
          </dl>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</div>
