<?php

declare(strict_types=1);

/**
 * @var \App\Http\ConfigView $view
 */

use App\Http\Respond;

?>
<div class="wrap">
  <div class="topbar">
    <h1>Configuração</h1>
    <a href="/">voltar</a>
  </div>
  <p class="sub">A configuração vive no <code>.env</code> e continua sendo lida de lá. Esta tela alterna apenas as chaves abaixo, gravando em <code>storage/flags.json</code> — o <code>.env</code> nunca é reescrito. A aparência fica só neste navegador.</p>

  <?php if ($view->notice !== null): ?>
    <div class="alert alert-note" data-aviso><?= Respond::e($view->notice) ?></div>
  <?php endif; ?>

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

  <section>
    <h2>API HTTP</h2>

    <form method="post" id="form-api" data-sem-reload data-ligada="<?= $view->apiEnabled ? '1' : '0' ?>">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="acao" value="api">

      <div class="linha-acao">
        <label class="switch">
          <input type="checkbox" name="api_enabled" value="1" id="api-toggle" data-envia-ao-mudar
                 <?= $view->apiEnabled ? 'checked' : '' ?>>
          <span class="trilho"></span>
          <span class="rotulo" id="api-rotulo"><?= $view->apiEnabled ? 'Ligada' : 'Desligada' ?></span>
        </label>
        <button type="submit" class="btn btn-sm" id="btn-salvar-api" data-so-sem-js>Salvar</button>
      </div>

      <p class="nota-estado">
        Expõe <code>/api/executions</code>, que <strong>executa comandos</strong> por JSON.
        Desligada, a rota responde 404.
        <br>
        No <code>.env</code>: <strong><?= $view->apiFromEnv ? 'ligada' : 'desligada' ?></strong><?php
          if ($view->apiOverridden): ?> — sobrescrito por esta tela<?php endif; ?>.
      </p>
    </form>
  </section>

  <section>
    <h2>Windows — PowerShell elevado</h2>

    <?php if (!$view->win->canTry()): ?>
      <div class="alert alert-block"><?= Respond::e($view->win->blocked) ?></div>
    <?php endif; ?>

    <form method="post" id="form-ps" data-sem-reload data-ligado="<?= $view->win->on ? '1' : '0' ?>">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="acao" value="powershell">

      <div class="linha-acao">
        <label class="switch">
          <input type="checkbox" name="ps_enabled" value="1" id="ps-toggle"
                 <?= $view->win->on ? 'checked' : '' ?>
                 <?= $view->win->canTry() ? '' : 'disabled' ?>>
          <span class="trilho"></span>
          <span class="rotulo" id="ps-rotulo"><?= $view->win->on ? 'Ligado' : 'Desligado' ?></span>
        </label>
        <button type="submit" class="btn btn-sm" id="btn-salvar-ps"<?= $view->win->on ? '' : ' data-uac' ?>
                <?= $view->win->canTry() ? '' : 'disabled' ?>>Salvar</button>

        <?php if ($view->winRetry): ?>
          <button type="submit" class="btn btn-sm btn-ghost" name="ps_enabled" value="1" id="btn-retentar" data-uac>
            Tentar novamente
          </button>
        <?php endif; ?>
      </div>

      <p class="nota-estado">
        Libera o botão <strong>WIN</strong>, que executa as ações do
        Windows — e elas pedem Administrador.
        <br>
        <?php if ($view->win->on): ?>
          De pé desde <strong><?= Respond::e($view->win->provedAt) ?></strong> (UTC), PID
          <strong><?= (int) $view->win->psPid ?></strong>.
        <?php elseif ($view->win->detail !== null): ?>
          <strong><?= Respond::e($view->win->detail) ?></strong>
        <?php endif; ?>
        <br>
        Estado de <strong>vida curta</strong>: reiniciar o servidor desliga. Não passa pelo
        <code>flags.json</code> de propósito — aquele arquivo existe para sobreviver a reinício,
        e aqui se quer o contrário.
      </p>

      <p class="nota-estado" id="uac-estado">
        As ações que <strong>abrem o UAC</strong> (instalar, ligar o RDP, abrir porta no
        firewall) não passam por este PowerShell: cada uma pede o próprio prompt, e roda mesmo
        com o interruptor desligado.
        <br>
        <?php if ($view->uacOk): ?>
          <?= Respond::e($view->uacSummary) ?>
        <?php else: ?>
          <strong><?= Respond::e($view->uacSummary) ?></strong>
        <?php endif; ?>
      </p>
    </form>
  </section>

  <section>
    <h2>Windows — Painel do Hyper-V</h2>

    <form method="post" id="form-hyperv" data-sem-reload>
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="acao" value="hyperv">

      <div class="linha-acao">
        <label class="switch">
          <input type="checkbox" name="hyperv_enabled" value="1" id="hyperv-toggle" data-envia-ao-mudar
                 <?= $view->hypervEnabled ? 'checked' : '' ?>>
          <span class="trilho"></span>
          <span class="rotulo" id="hyperv-rotulo"><?= $view->hypervEnabled ? 'Ligado' : 'Desligado' ?></span>
        </label>
        <button type="submit" class="btn btn-sm" id="btn-salvar-hyperv" data-so-sem-js>Salvar</button>
      </div>

      <p class="nota-estado">
        Mostra a tela Hyper-V com as máquinas virtuais deste computador. Só leitura.
        <br>
        <?php if ($view->hypervEnabled): ?>
          Ligado<?php if ($view->hypervEnabledAt !== null && $view->hypervEnabledAt !== ''): ?>
            desde <strong><?= Respond::e(Respond::dateTime($view->hypervEnabledAt, $view->tz)) ?></strong><?php endif; ?>.
        <?php else: ?>
          Desligado.
        <?php endif; ?>
      </p>

      <details class="nota-estado">
        <summary>Como funciona</summary>
        Ligar <strong>confere antes</strong> se o Hyper-V está presente e ligado no Windows — se
        não estiver, o painel não liga e a tela diz o que falta. Ao contrário do PowerShell
        elevado, este estado <strong>sobrevive a reiniciar</strong> o servidor e o Windows: é
        decisão sua, não prova de privilégio, e por isso mora em <code>storage/hyperv.json</code>,
        e não no marcador de elevação.
      </details>
    </form>
  </section>

  <?php if ($view->wslDistros !== null): ?>
    <?php $wslRodando = $view->wslRunning !== null && $view->wslRunning !== []; ?>
    <section id="wsl">
      <h2>WSL</h2>

      <form method="post" id="form-wsl" data-sem-reload>
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="wsl">

        <div class="linha-acao">
          <button type="submit" class="btn btn-sm" name="SubAction" value="wake" id="btn-wsl-acordar"
                  <?= $view->win->on && $view->wslDistros !== [] && !$wslRodando ? '' : 'disabled' ?>>Acordar</button>
          <button type="button" class="btn btn-danger btn-sm" id="btn-wsl-desligar"
                  <?= $view->win->on && $view->wslRunning !== [] ? '' : 'disabled' ?>>Desligar…</button>
        </div>

        <p class="nota-estado" id="wsl-estado">
          <?php if ($view->wslRunning === null): ?>
            Não foi possível ler quais distros estão rodando.
          <?php elseif ($view->wslRunning === []): ?>
            Nenhuma distro rodando.
          <?php else: ?>
            Rodando: <strong><?= Respond::e(implode(', ', $view->wslRunning)) ?></strong>.
          <?php endif; ?>
          <?php if ($view->wslDistros === []): ?>
            Nenhuma distro instalada.
          <?php else: ?>
            Instaladas: <?= Respond::e(implode(', ', $view->wslDistros)) ?>.
          <?php endif; ?>
          <br>
          <strong>Acordar</strong> sobe a distro padrão do Windows sem abrir janela. Sem nada
          rodando dentro, o WSL pode parar de novo depois de um tempo ocioso.
          <strong>Desligar</strong> para todas as distros e a VM do WSL 2.
          <?php if (!$view->win->on): ?>
            <br>
            <strong>Precisa do PowerShell elevado ligado, acima.</strong>
          <?php endif; ?>
        </p>

        <dialog id="modal-wsl">
          <div class="modal-corpo">
            <h3>Desligar o WSL?</h3>
            <p>
              Para <strong>todas as distros</strong> e a VM do WSL 2. Um comando da tela WSL que
              esteja no meio é interrompido, e o que não foi salvo dentro das distros se perde.
            </p>
          </div>
          <div class="modal-acoes">
            <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
            <button type="submit" class="btn btn-danger btn-sm" name="SubAction" value="shutdown">Desligar</button>
          </div>
        </dialog>
      </form>
    </section>
  <?php endif; ?>

  <section id="php">
    <h2>PHP</h2>

    <div class="linha-acao">
      <a href="/php" class="btn btn-sm btn-ghost" id="link-php">Ver o PHP em uso</a>
    </div>

    <p class="nota-estado">
      Versão, binário e <code>php.ini</code> do PHP que serve o PHPorto, e uma lista fechada de chaves do <code>php.ini</code> para gravar.
    </p>
  </section>

  <section id="aparencia">
    <h2>Aparência</h2>

    <div class="linha-acao" role="group" aria-label="Aparência">
      <button type="button" class="btn btn-sm btn-ghost" data-tema="claro" aria-pressed="false">Claro</button>
      <button type="button" class="btn btn-sm btn-ghost" data-tema="escuro" aria-pressed="false">Escuro</button>
      <button type="button" class="btn btn-sm btn-ghost" data-tema="sistema" aria-pressed="true">Sistema</button>
    </div>

    <p class="nota-estado">
      Vale para todas as páginas. Sistema segue o modo do Windows. A escolha fica
      guardada só neste navegador, e a restauração de fábrica não mexe nela.
    </p>
  </section>

  <section>
    <h2>Restaurar configurações de fábrica</h2>

    <form method="post" id="form-fabrica" data-sem-reload>
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="acao" value="fabrica">

      <div class="linha-acao">
        <button type="button" class="btn btn-danger btn-sm" id="btn-fabrica">Restaurar…</button>
      </div>

      <p class="nota-estado">
        Apaga <code>storage/flags.json</code>: tudo volta ao que o <code>.env</code> diz.
        Desliga também o painel do Hyper-V.
        Opcionalmente apaga também o arquivo do banco.
      </p>

      <dialog id="modal-fabrica">
        <div class="modal-corpo">
          <h3>Restaurar configurações de fábrica?</h3>
          <p>
            As chaves alternadas nesta tela voltam ao valor do <code>.env</code>.
            Como a API está <strong><?= $view->apiFromEnv ? 'ligada' : 'desligada' ?></strong> no arquivo,
            é assim que ela vai ficar.
          </p>

          <?php if ($view->dbPath !== ''): ?>
            <label class="modal-opcao">
              <input type="checkbox" name="apagar_banco" value="1">
              <span style="color:var(--ink);font-weight:600;margin:0">
                Apagar também o banco
                <span>Remove o arquivo <code><?= Respond::e(basename($view->dbPath)) ?></code> com todo o histórico de execuções. Não tem como desfazer<?= $view->dbExists ? '' : ' (não há arquivo no momento)' ?>.</span>
              </span>
            </label>
          <?php endif; ?>
        </div>
        <div class="modal-acoes">
          <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
          <button type="submit" class="btn btn-danger btn-sm">Restaurar</button>
        </div>
      </dialog>
    </form>
  </section>
</div>

<dialog id="modal-ps">
  <div class="modal-corpo">
    <h3>Abrir um PowerShell com privilégio de Administrador?</h3>
    <p>
      Ligar não grava uma preferência: <strong>abre um processo elevado</strong> e espera a prova
      de que ele funciona. O Windows pode pedir confirmação — se pedir e ninguém confirmar,
      a tela avisa depois de <code><?= (int) $view->winProofTimeout ?> s</code>.
    </p>
    <p>
      Enquanto estiver ligado, esse processo aceita as ações do Windows
      vindas desta tela. Ele <strong>obedece a uma lista fechada</strong> de ações e
      parâmetros — o que a tela manda nunca é código.
    </p>
    <p>
      Some ao reiniciar o servidor, e desligar aqui manda ele sair. Desligar não pergunta nada.
    </p>
  </div>
  <div class="modal-acoes">
    <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
    <button type="button" class="btn btn-sm" id="btn-confirmar-ps">Abrir elevado</button>
  </div>
</dialog>

<dialog id="modal-api">
  <div class="modal-corpo">
    <h3>Ligar a API HTTP?</h3>
    <p>
      <code>/api/executions</code> passa a aceitar POST e <strong>executar comandos no WSL</strong>
      sem passar pelas telas.
    </p>
    <p>
      A proteção dessa rota é a checagem de origem — só
      <code><?= Respond::e($view->corsOrigin) ?></code> é aceita. É uma tranca mais fraca que o
      token das telas, porque <strong>nem toda requisição envia origem</strong>: quem chama fora
      do navegador não manda esse cabeçalho.
    </p>
    <p>Ligue enquanto precisar e desligue depois. Desligar não pergunta nada.</p>
  </div>
  <div class="modal-acoes">
    <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
    <button type="button" class="btn btn-sm" id="btn-confirmar-api">Ligar API</button>
  </div>
</dialog>

<script>
(function () {
  'use strict';

  var ROTULOS = {
    'api-toggle':    ['api-rotulo', 'Ligada', 'Desligada'],
    'ps-toggle':     ['ps-rotulo', 'Ligado', 'Desligado'],
    'hyperv-toggle': ['hyperv-rotulo', 'Ligado', 'Desligado']
  };

  function rotular(caixa) {
    var r = ROTULOS[caixa.id];
    if (r) { document.getElementById(r[0]).textContent = caixa.checked ? r[1] : r[2]; }
  }

  var aoMudar = !!(window.fetch && window.DOMParser && window.URLSearchParams && window.FormData && HTMLFormElement.prototype.requestSubmit);

  function esconderSalvar() {
    if (!aoMudar) { return; }
    document.querySelectorAll('[data-so-sem-js]').forEach(function (b) { b.hidden = true; });
  }

  document.addEventListener('change', function (ev) {
    rotular(ev.target);
    if (aoMudar && ev.target.hasAttribute('data-envia-ao-mudar') && ev.target.form) { ev.target.form.requestSubmit(); }
  });

  document.addEventListener('phporto:trocou', esconderSalvar);
  esconderSalvar();

  var apiConfirmada = false;
  var modalApi = document.getElementById('modal-api');
  if (modalApi) {
    modalApi.addEventListener('close', function () {
      var caixa = document.getElementById('api-toggle');
      var form = document.getElementById('form-api');
      if (!apiConfirmada && caixa && form) {
        caixa.checked = form.getAttribute('data-ligada') === '1';
        rotular(caixa);
      }
      apiConfirmada = false;
    });
  }

  var TEMA = 'phporto.tema';

  function temaAtual() {
    var t = document.documentElement.getAttribute('data-tema');
    return t === 'claro' || t === 'escuro' ? t : 'sistema';
  }

  function pintarTema() {
    var atual = temaAtual();
    document.querySelectorAll('#aparencia [data-tema]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-tema') === atual ? 'true' : 'false');
    });
  }

  function trocarTema(modo) {
    var raiz = document.documentElement;
    var fixo = modo === 'claro' || modo === 'escuro';
    if (fixo) { raiz.setAttribute('data-tema', modo); } else { raiz.removeAttribute('data-tema'); }
    try {
      if (fixo) { localStorage.setItem(TEMA, modo); } else { localStorage.removeItem(TEMA); }
    } catch (e) { }
    pintarTema();
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('#aparencia [data-tema]');
    if (b) { trocarTema(b.getAttribute('data-tema')); }
  });

  document.addEventListener('phporto:trocou', pintarTema);
  pintarTema();

  function enviar(form) {
    form.setAttribute('data-confirmado', '');
    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
  }

  document.addEventListener('click', function (ev) {
    if (ev.target.closest('#btn-fabrica')) { document.getElementById('modal-fabrica').showModal(); }

    if (ev.target.closest('#btn-wsl-desligar')) { document.getElementById('modal-wsl').showModal(); }

    if (ev.target.closest('#btn-retentar')) { document.getElementById('form-ps').setAttribute('data-confirmado', ''); }

    if (ev.target.closest('#btn-confirmar-api')) {
      apiConfirmada = true;
      document.getElementById('modal-api').close();
      enviar(document.getElementById('form-api'));
    }

    if (ev.target.closest('#btn-confirmar-ps')) {
      document.getElementById('modal-ps').close();
      enviar(document.getElementById('form-ps'));
    }
  });

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form.hasAttribute('data-confirmado')) { form.removeAttribute('data-confirmado'); return; }

    var modal = null;
    if (form.id === 'form-api' && document.getElementById('api-toggle').checked && form.getAttribute('data-ligada') !== '1') {
      modal = 'modal-api';
    }
    if (form.id === 'form-ps' && document.getElementById('ps-toggle').checked && form.getAttribute('data-ligado') !== '1') {
      modal = 'modal-ps';
    }

    if (modal) {
      ev.preventDefault();
      document.getElementById(modal).showModal();
    }
  });
})();
</script>
