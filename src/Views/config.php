<?php

declare(strict_types=1);

/**
 * A tela /config: só o banco ativo, só leitura.
 *
 * @var \App\Http\ConfigView $view
 */

use App\Http\Respond;

?>
<div class="wrap">
  <div class="topbar">
    <h1>Configuração</h1>
    <a href="/">voltar</a>
  </div>
  <p class="sub">Somente leitura. A configuração vive no <code>.env</code>, e é lá que se muda.</p>

  <section>
    <h2>Banco ativo</h2>
    <p class="provider"><?= Respond::e($view->provider) ?></p>

    <?php if ($view->details !== []): ?>
      <dl class="kv" style="margin-top:14px">
        <?php foreach ($view->details as $item): ?>
          <dt><?= Respond::e($item['label']) ?></dt>
          <dd><?= Respond::e($item['value'] !== '' ? $item['value'] : '—') ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </section>
</div>
