<?php

declare(strict_types=1);

/**
 * A tela /hyperv.
 *
 * @var \App\Http\HypervView $view
 */

use App\Http\Respond;

?>
<div class="wrap">
  <div class="topbar">
    <h1>Hyper-V</h1>
    <a href="/">voltar</a>
  </div>
  <p class="sub">
    máquinas virtuais &middot; <?= Respond::e($view->tz) ?> &middot; 127.0.0.1
  </p>

  <p class="dica">A lista das máquinas virtuais aparece aqui.</p>
</div>
