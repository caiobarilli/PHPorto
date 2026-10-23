<?php

declare(strict_types=1);

/**
 * @var \App\Http\PhpView $view
 */

use App\Http\Respond;
use App\Php\PhpIni;
use App\Php\PhpReleases;

$ativo  = $view->active;
$medido = $view->pathBuild;
$ini    = $view->ini;
$update = $view->update;

?>
<div class="wrap">
  <div class="topbar">
    <h1>PHP</h1>
    <a href="/">voltar</a>
    <a href="/config">configuração</a>
  </div>
  <p class="sub">O interpretador que serve o PHPorto, medido no próprio processo do servidor. O que for gravado no <code>php.ini</code> só vale depois de fechar o <code>php -S</code> e subir de novo.</p>

  <?php if ($view->notice !== null): ?>
    <div class="alert alert-note" data-aviso><?= Respond::e($view->notice) ?></div>
  <?php endif; ?>

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

    <?php if ($update !== null): ?>
      <section id="php-atualizar">
        <h2>Atualizar</h2>
        <p class="dica" style="margin-top:0"><?= Respond::e($update->status) ?></p>
        <?php if ($update->available() && $update->release !== null && $update->dir !== null): ?>
          <form method="post" action="/php" id="form-atualizar" data-sem-reload>
            <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
            <input type="hidden" name="<?= PhpReleases::POST_FIELD ?>" value="<?= Respond::e($update->release->version) ?>">
            <dl class="kv">
              <dt>Zip oficial</dt><dd><?= Respond::e($update->release->url()) ?></dd>
              <dt>SHA256</dt><dd><code><?= Respond::e($update->release->sha256) ?></code></dd>
              <dt>Pasta</dt><dd><?= Respond::e($update->dir) ?></dd>
            </dl>
            <div class="row">
              <button type="button" class="btn btn-sm" id="btn-atualizar-php">Atualizar PHP…</button>
            </div>
            <dialog id="modal-atualizar">
              <div class="modal-corpo">
                <h3>Atualizar para o <?= Respond::e($update->release->version) ?>?</h3>
                <p>Abre o UAC. Baixa o zip oficial, confere o SHA256 e só então copia a pasta atual para <code><?= Respond::e($update->dir . '-' . $ativo->version) ?></code> ao lado dela. Se já existir, usa <code>-2</code>, <code>-3</code> e assim por diante, sem sobrescrever.</p>
                <p>Extrai na mesma pasta, devolve o <code>php.ini</code> de antes e confere o <code>php -v</code>. Se algo falhar, a versão anterior volta. PATH e permissões ficam como estão.</p>
                <p>Depois, feche o <code>php -S</code> e suba de novo: até lá, o servidor segue no <?= Respond::e($ativo->version) ?>.</p>
              </div>
              <div class="modal-acoes">
                <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
                <button type="submit" class="btn btn-sm" id="btn-confirmar-atualizar" data-uac>Atualizar</button>
              </div>
            </dialog>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>

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
    <section id="php-ini">
      <h2>php.ini</h2>
      <?php if ($ini === null): ?>
        <p class="dica" style="margin-top:0">Sem <code>php.ini</code> carregado, esta tela não grava. Crie o arquivo ao lado do <code>php.exe</code> e suba o <code>php -S</code> de novo.</p>
      <?php elseif ($ini->error !== null): ?>
        <div class="alert alert-block" id="ini-erro"><?= Respond::e($ini->error) ?></div>
      <?php else: ?>
        <p class="dica" style="margin-top:0">Não é editor livre: só as chaves abaixo. Antes de gravar, o arquivo atual vira <code><?= Respond::e(basename($ini->path) . PhpIni::BACKUP_SUFFIX) ?></code> na mesma pasta. O resto do arquivo fica igual, byte a byte.</p>
        <?php if (!$ini->writable): ?>
          <div class="alert alert-block" id="ini-somente-leitura">Este usuário não pode gravar <code><?= Respond::e($ini->path) ?></code> ou a pasta dele. Esta tela não grava com UAC: edite à mão ou use um PHP numa pasta sua, como <code>C:\php</code>.</div>
        <?php endif; ?>
        <form method="post" action="/php" id="form-ini" data-sem-reload>
          <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
          <input type="hidden" name="<?= PhpIni::HASH_FIELD ?>" value="<?= Respond::e($ini->hash) ?>">
          <table>
            <thead>
              <tr><th style="width:32%">Chave</th><th style="width:28%">No arquivo</th><th>Situação</th></tr>
            </thead>
            <tbody>
              <?php foreach ($ini->rows as $linha): ?>
                <?php $campo = PhpIni::FIELD . '[' . $linha->key->value . ']'; ?>
                <tr data-chave="<?= Respond::e($linha->key->value) ?>">
                  <td><label for="ini-<?= Respond::e($linha->key->name) ?>"><code><?= Respond::e($linha->key->value) ?></code></label></td>
                  <td>
                    <?php if ($linha->key->isSwitch()): ?>
                      <select name="<?= Respond::e($campo) ?>" id="ini-<?= Respond::e($linha->key->name) ?>">
                        <option value="On"<?= $linha->value === 'On' ? ' selected' : '' ?>>On</option>
                        <option value="Off"<?= $linha->value === 'Off' ? ' selected' : '' ?>>Off</option>
                      </select>
                    <?php else: ?>
                      <input type="text" name="<?= Respond::e($campo) ?>" id="ini-<?= Respond::e($linha->key->name) ?>" value="<?= Respond::e($linha->value) ?>" maxlength="64" spellcheck="false" autocomplete="off">
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($linha->differsFromDefault()): ?>
                      <span class="tag" data-difere>difere do padrão</span> padrão <code><?= Respond::e($linha->key->default()) ?></code>
                    <?php else: ?>
                      <span class="tag">padrão</span>
                    <?php endif; ?>
                    <?php if (!$linha->inFile): ?>
                      <br><span class="dica">Não declarada no arquivo.</span>
                    <?php endif; ?>
                    <?php if ($linha->pendingRestart()): ?>
                      <br><span class="dica" data-pendente>Em uso agora: <code><?= Respond::e((string) $linha->running) ?></code>, até subir o <code>php -S</code> de novo.</span>
                    <?php endif; ?>
                    <?php if ($linha->overriddenBy !== null): ?>
                      <br><span class="dica bad">Declarada também em <code><?= Respond::e($linha->overriddenBy) ?></code>, que vence.</span>
                    <?php endif; ?>
                    <br><span class="dica"><?= Respond::e($linha->key->hint()) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="row">
            <?php if ($ini->writable): ?>
              <button type="button" class="btn btn-sm" id="btn-salvar-ini">Salvar…</button>
            <?php endif; ?>
            <a href="/php" class="btn btn-sm btn-ghost" id="btn-reler-ini">Reler do disco</a>
          </div>
          <p class="nota-estado">Depois de gravar, feche o <code>php -S</code> e suba de novo: o servidor continua com os valores antigos até lá.</p>

          <?php if ($ini->writable): ?>
            <dialog id="modal-ini">
              <div class="modal-corpo">
                <h3>Gravar php.ini?</h3>
                <p>Vai copiar o atual para <code><?= Respond::e(basename($ini->path) . PhpIni::BACKUP_SUFFIX) ?></code> e mudar só as chaves desta lista que você alterou. O servidor continua com o valor antigo até o <code>php -S</code> subir de novo.</p>
              </div>
              <div class="modal-acoes">
                <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
                <button type="submit" class="btn btn-sm" id="btn-gravar-ini">Gravar</button>
              </div>
            </dialog>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>

<script>
(function () {
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('#btn-salvar-ini')) { document.getElementById('modal-ini').showModal(); }
    if (ev.target.closest('#btn-atualizar-php')) { document.getElementById('modal-atualizar').showModal(); }
  });
})();
</script>
