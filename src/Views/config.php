<?php

declare(strict_types=1);

/**
 * A tela /config: o banco ativo, as chaves que a tela pode alternar, e o
 * restaurar de fábrica.
 *
 * O .env continua sendo lido e nunca escrito. O que muda aqui vai para
 * storage/flags.json — ver a nota em App\Config\Flags.
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
  <p class="sub">A configuração vive no <code>.env</code> e continua sendo lida de lá. Esta tela alterna apenas as chaves abaixo, gravando em <code>storage/flags.json</code> — o <code>.env</code> nunca é reescrito.</p>

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
          <input type="checkbox" name="api_enabled" value="1" id="api-toggle"
                 <?= $view->apiEnabled ? 'checked' : '' ?>>
          <span class="trilho"></span>
          <span class="rotulo" id="api-rotulo"><?= $view->apiEnabled ? 'Ligada' : 'Desligada' ?></span>
        </label>
        <button type="submit" class="btn btn-sm" id="btn-salvar-api">Salvar</button>
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
        <?php /* Desligado, salvar só pode ligar: e ligar espera o UAC. */ ?>
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
          <input type="checkbox" name="hyperv_enabled" value="1" id="hyperv-toggle"
                 <?= $view->hypervEnabled ? 'checked' : '' ?>>
          <span class="trilho"></span>
          <span class="rotulo" id="hyperv-rotulo"><?= $view->hypervEnabled ? 'Ligado' : 'Desligado' ?></span>
        </label>
        <button type="submit" class="btn btn-sm" id="btn-salvar-hyperv">Salvar</button>
      </div>

      <p class="nota-estado">
        Libera a tela <strong>/hyperv</strong>, que lista as máquinas virtuais. Ligar
        <strong>confere antes</strong> se o Hyper-V está presente e ligado no Windows — se
        não estiver, o painel não liga e a tela diz o que falta.
        <br>
        <?php if ($view->hypervEnabled): ?>
          Ligado<?php if ($view->hypervEnabledAt !== null && $view->hypervEnabledAt !== ''): ?>
            desde <strong><?= Respond::e($view->hypervEnabledAt) ?></strong> (UTC)<?php endif; ?>.
        <?php else: ?>
          Desligado.
        <?php endif; ?>
        <br>
        Ao contrário do PowerShell elevado, este estado <strong>sobrevive a reiniciar</strong> o
        servidor e o Windows: é decisão sua, não prova de privilégio, e por isso mora em
        <code>storage/hyperv.json</code>, e não no marcador de elevação.
      </p>
    </form>
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
/*
 * Os formulários desta tela vão pelo script comum do layout, sem reload: o
 * miolo é trocado depois de cada ação, então tudo aqui é por delegação no
 * document, e o estado ("já estava ligada?") vem dos atributos data-* do
 * formulário, que chegam frescos em cada troca.
 */
(function () {
  'use strict';

  var ROTULOS = {
    'api-toggle':    ['api-rotulo', 'Ligada', 'Desligada'],
    'ps-toggle':     ['ps-rotulo', 'Ligado', 'Desligado'],
    'hyperv-toggle': ['hyperv-rotulo', 'Ligado', 'Desligado']
  };

  document.addEventListener('change', function (ev) {
    var r = ROTULOS[ev.target.id];
    if (r) { document.getElementById(r[0]).textContent = ev.target.checked ? r[1] : r[2]; }
  });

  function enviar(form) {
    form.setAttribute('data-confirmado', '');
    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
  }

  document.addEventListener('click', function (ev) {
    if (ev.target.closest('#btn-fabrica')) { document.getElementById('modal-fabrica').showModal(); }

    // O "tentar novamente" envia ps_enabled=1 pelo próprio botão, com o
    // interruptor ainda desligado — e não passa pelo modal, porque quem
    // clica nele já leu o aviso na tentativa anterior.
    if (ev.target.closest('#btn-retentar')) { document.getElementById('form-ps').setAttribute('data-confirmado', ''); }

    if (ev.target.closest('#btn-confirmar-api')) {
      document.getElementById('modal-api').close();
      enviar(document.getElementById('form-api'));
    }

    if (ev.target.closest('#btn-confirmar-ps')) {
      document.getElementById('modal-ps').close();
      enviar(document.getElementById('form-ps'));
    }
  });

  // Só LIGAR pergunta, na API e no PowerShell: desligar reduz superfície, e
  // confirmar seria cerimônia sem risco do outro lado. Ligar o PowerShell
  // abre um processo elevado, e o modal dele diz isso com essas palavras. O
  // Hyper-V não pergunta: a própria ação confere o recurso e recusa com aviso.
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
