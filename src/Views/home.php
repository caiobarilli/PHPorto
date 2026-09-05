<?php

declare(strict_types=1);

/**
 * A home: o nome, e dois botões.
 *
 * O botão do WSL só desabilita quando o WSL não está instalado ou a distro do
 * .env não existe — não quando a VM está dormindo. A VM dormir é normal, ela
 * sobe sozinha no primeiro comando, e desabilitar por isso mentiria.
 *
 * @var \App\Http\HomeView $view
 */

use App\Http\Respond;

?>
<main class="home">
  <h1>PHPorto</h1>

  <div class="home-actions">
    <a class="btn" href="/config" aria-label="Configuração">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="3"></circle>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.35.4.64.73.83H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
      </svg>
      Configuração
    </a>

    <?php if ($view->wslEnabled): ?>
      <a class="btn" href="/wsl">WSL</a>
    <?php else: ?>
      <span class="btn is-disabled" aria-disabled="true">WSL</span>
    <?php endif; ?>
  </div>

  <?php if (!$view->wslEnabled): ?>
    <p class="home-note"><?= Respond::e($view->wslReason) ?></p>
  <?php endif; ?>
</main>
