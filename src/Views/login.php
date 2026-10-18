<?php

declare(strict_types=1);

/**
 * A entrada das telas: um campo de senha para o token, no lugar do diálogo
 * nativo do Basic. Sem data-sem-reload: o login é navegação comum, e o 303
 * que volta leva para a tela pedida.
 *
 * @var \App\Http\LoginView $view
 */

use App\Http\Respond;

?>
<div class="wrap login">
  <div class="topbar">
    <h1>PHPorto</h1>
  </div>
  <p class="sub">Cole o token de acesso para entrar. Quem gera é o <code>php token.php</code>.</p>

  <?php if ($view->aviso !== ''): ?>
    <div class="alert alert-block" role="alert"><?= Respond::e($view->aviso) ?></div>
  <?php endif; ?>

  <?php if ($view->bloqueio !== ''): ?>
    <div class="alert alert-block" role="alert"><strong>Bloqueado.</strong> <?= Respond::e($view->bloqueio) ?></div>
  <?php else: ?>
    <section>
      <form method="post" action="<?= Respond::e($view->action) ?>">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <div class="campo">
          <label for="login-token">Token</label>
          <input type="password" id="login-token" name="<?= Respond::e($view->field) ?>"
                 autocomplete="current-password" spellcheck="false" required autofocus>
        </div>
        <div class="row">
          <button class="btn btn-sm" type="submit">Enviar</button>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>
