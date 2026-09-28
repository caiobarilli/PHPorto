<?php

declare(strict_types=1);

/**
 * A tela /win: as quatorze ações do Windows, uma seção cada.
 *
 * Quatro partes, nesta ordem: a última execução; as três ações de um clique
 * (auditoria, memória, processos) na mesma linha; o menu de abas com as outras
 * onze; e o histórico. Os cinco painéis são desenhados, só o da aba aberta
 * visível, e o menu são links para /win?aba=<nome>#abas. Cada formulário posta
 * para a URL da página, que carrega a aba, e o 303 volta para ela.
 *
 * Cada seção é um FORMULÁRIO PRÓPRIO, e não um formulário só com um seletor
 * de ação: o token vale por UMA execução, e formulários separados mandam só o
 * que a ação usa.
 *
 * @var \App\Http\WinView $view
 */

use App\Http\Respond;
use App\Http\WinTab;

/** Abre uma seção com o título e a descrição dela. */
$secao = static function (string $titulo, string $descricao): string {
    return '<h2>' . Respond::e($titulo) . '</h2>'
        . '<p class="dica" style="margin-top:0">' . Respond::e($descricao) . '</p>';
};

/** Os nomes dos presets dos ajustes, e da seleção que não é nenhum deles. */
$presetRotulos = ['standard' => 'padrão', 'minimal' => 'mínimo', 'advanced' => 'avançado', 'custom' => 'personalizada'];

/** As subações, em português; o valor enviado continua sendo o da ação. */
$subRotulos = [
    'install'   => 'instalar',
    'status'    => 'ver o estado',
    'start'     => 'iniciar',
    'stop'      => 'parar',
    'metrics'   => 'ver as métricas',
    'firewall'  => 'liberar no firewall',
    'uninstall' => 'desinstalar',
    'disable'   => 'desligar',
    'enable'    => 'religar',
];

/** Uma opção de subação: o rótulo em português, com o valor da ação ao lado. */
$opcao = static function (string $valor) use ($subRotulos): string {
    return '<option value="' . Respond::e($valor) . '">'
        . Respond::e(($subRotulos[$valor] ?? $valor) . ' (' . $valor . ')') . '</option>';
};

$travado = $view->blocked !== null;
$dis     = $travado ? ' disabled' : '';

/**
 * Um formulário de um botão só, com um campo oculto.
 *
 * Recebe a ação, o nome e o valor do campo, o rótulo e se o botão é
 * secundário. Devolve o HTML do formulário.
 */
$botao = static function (string $acao, string $campo, string $valor, string $rotulo, bool $secundario) use ($view, $dis): string {
    return '<form method="post">'
        . '<input type="hidden" name="' . Respond::e($view->csrfField) . '" value="' . Respond::e($view->csrfToken) . '">'
        . '<input type="hidden" name="acao" value="' . Respond::e($acao) . '">'
        . '<input type="hidden" name="' . Respond::e($campo) . '" value="' . Respond::e($valor) . '">'
        . '<button type="submit" class="btn btn-sm' . ($secundario ? ' btn-ghost' : '') . '"' . $dis . '>'
        . Respond::e($rotulo) . '</button></form>';
};

/** Abre o painel de uma aba; o da aba que não está aberta nasce escondido. */
$painel = static function (WinTab $aba) use ($view): string {
    return '<div class="painel" id="painel-' . $aba->value . '" role="tabpanel"'
        . ($aba === $view->tab ? '' : ' hidden') . '>';
};

$aplicado      = static fn (\App\Win\WinAction $a): bool => in_array($a->value, $view->appliedActions, true);
$twReverte     = \App\Win\WinAction::tweaksRevert($view->tweaksChecked, $view->tweaksApplied);
$perfAplicado  = $aplicado(\App\Win\WinAction::Performance);
$optAplicado   = $aplicado(\App\Win\WinAction::Optimize);
$gdidAplicado  = $aplicado(\App\Win\WinAction::Gdid);
$rdpAplicado   = $aplicado(\App\Win\WinAction::Rdp);
$rdpPendente   = in_array(\App\Win\WinAction::Rdp->value, $view->pendingRebootActions, true);

$grupos = [];
foreach ($view->tweaks as $tw) {
    $grupos[$tw['category']][] = $tw;
}

?>
<div class="wrap">
  <div class="topbar">
    <h1>Windows</h1>
    <a href="/">voltar</a>
    <a href="/config">configuração</a>
  </div>
  <p class="sub">
    As treze ações do Windows, executadas por um PowerShell com privilégio de
    Administrador. Cada ação é registrada no banco com tipo <code>windows</code>.
  </p>

  <?php if ($view->notice !== null): ?>
    <div class="alert alert-note"><?= Respond::e($view->notice) ?></div>
  <?php endif; ?>

  <?php if ($travado): ?>
    <div class="alert alert-block">
      <?= Respond::e($view->blocked) ?>
      <?php if ($view->win->canTry() && !$view->win->on): ?>
        <br>Ligue o interruptor de PowerShell na <a href="/config">configuração</a>.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ---------------------------------------------------------------- saída -->
  <section>
    <h2>Última execução do Windows</h2>

    <?php if ($view->result === null): ?>
      <p class="empty">Nada executado ainda por esta tela.</p>
    <?php else: ?>
      <div class="row" style="margin-top:0">
        <span class="tag">windows</span>
        <span class="meta">
          <?= Respond::e(Respond::dateTime($view->result->createdAt, $view->tz)) ?>
          &middot; <?= number_format($view->result->durationSeconds(), 1, ',', '.') ?> s
          &middot;
          <?php if ($view->result->timedOut): ?>
            <span class="bad">cancelada por tempo esgotado</span>
          <?php elseif ($view->result->exitCode === 0): ?>
            <span class="ok">saída 0</span>
          <?php elseif ($view->result->exitCode === null): ?>
            <span class="bad">sem código de saída</span>
          <?php else: ?>
            <span class="bad">saída <?= (int) $view->result->exitCode ?></span>
          <?php endif; ?>
        </span>
      </div>
      <div class="campo" style="margin-top:10px">
        <textarea readonly disabled><?= Respond::e($view->result->command) ?></textarea>
      </div>
      <div class="campo">
        <textarea readonly disabled><?= Respond::e($view->result->output) ?></textarea>
      </div>
    <?php endif; ?>

    <p class="dica">
      Saída cortada em <?= number_format($view->maxOutputBytes, 0, ',', '.') ?> bytes por execução;
      o corte aparece na própria saída. Cada ação é cancelada depois de
      <strong><?= (int) $view->timeout ?> s</strong>.
    </p>
  </section>

  <!-- ------------------------------------------------------ principais -->
  <div class="principais">
    <section id="acao-audit">
      <?= $secao('Auditoria', 'Gera o log completo do sistema em C:\log\DD.MM.AAAA — oito blocos.') ?>
      <div class="row">
        <form method="post">
          <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
          <input type="hidden" name="acao" value="audit">
          <input type="hidden" name="SubAction" value="run">
          <button type="submit" class="btn btn-sm"<?= $dis ?>>Gerar auditoria</button>
        </form>
        <form method="post">
          <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
          <input type="hidden" name="acao" value="audit">
          <input type="hidden" name="SubAction" value="open">
          <button type="submit" class="btn btn-sm btn-ghost"<?= $dis ?>>Explorar</button>
        </form>
      </div>
      <p class="dica">
        <strong>Explorar</strong> abre a pasta do log de hoje no Explorer — da máquina onde o servidor
        roda, não da que está com o navegador. Sem auditoria de hoje, abre <code>C:\log</code>.
      </p>
    </section>
    <section id="acao-memory">
      <?= $secao('Memória', 'Limpa a RAM com o WinMemoryCleaner, baixado na primeira execução.') ?>
      <form method="post">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="memory">
        <div class="row"><button type="submit" class="btn btn-sm"<?= $dis ?>>Limpar RAM</button></div>
      </form>
    </section>
    <section id="acao-processes">
      <?= $secao('Processos', 'Lista os 30 processos que mais consomem RAM.') ?>
      <form method="post">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="processes">
        <div class="row"><button type="submit" class="btn btn-sm"<?= $dis ?>>Listar processos</button></div>
      </form>
    </section>
  </div>

  <!-- ------------------------------------------------------------- abas -->
  <nav class="abas" id="abas" aria-label="Grupos de ações">
    <?php foreach (WinTab::cases() as $aba): ?>
      <a href="<?= Respond::e($aba->url()) ?>#abas" data-aba="<?= Respond::e($aba->value) ?>"<?= $aba === $view->tab ? ' class="ativa" aria-current="page"' : '' ?>><?= Respond::e($aba->label()) ?></a>
    <?php endforeach; ?>
  </nav>

  <?= $painel(WinTab::Sistema) ?>
    <section id="acao-tweaks">
      <?= $secao('Ajustes', 'Os ajustes do WinUtil, lidos do src/Win/config/tweaks.json, com o texto em português do tweaks.pt-BR.json. Registro e serviços do Windows. Nenhum vem marcado: marque um a um, ou comece por um preset.') ?>
      <?php if ($view->tweaksProblem !== null): ?>
        <p class="bad"><?= Respond::e($view->tweaksProblem) ?></p>
      <?php else: ?>
        <form method="post" id="form-tweaks">
          <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
          <input type="hidden" name="acao" value="tweaks">
          <div class="row">
            <span class="meta" style="margin:0">Preset</span>
            <?php foreach ($view->tweakPresets as $nome => $chaves): ?>
              <button type="button" class="btn btn-sm btn-ghost" data-preset="<?= Respond::e($nome) ?>"<?= $dis ?>><?= Respond::e($presetRotulos[$nome] ?? $nome) ?></button>
            <?php endforeach; ?>
            <button type="button" class="btn btn-sm btn-ghost" data-preset=""<?= $dis ?>>desmarcar tudo</button>
            <span class="meta" style="margin:0">Seleção: <strong id="tw-match"><?= Respond::e($view->tweaksMatch !== '' ? ($presetRotulos[$view->tweaksMatch] ?? $view->tweaksMatch) : 'nenhuma') ?></strong></span>
          </div>
          <?php foreach ($grupos as $categoria => $itens): ?>
            <fieldset class="grupo<?= $itens[0]['caution'] ? ' cuidado' : '' ?>">
              <legend><?= Respond::e((string) $categoria) ?></legend>
              <div class="colunas-4">
                <?php foreach ($itens as $tw): ?>
                  <label class="caixa">
                    <input type="checkbox" name="Items[]" value="<?= Respond::e($tw['key']) ?>"<?= in_array($tw['key'], $view->tweaksChecked, true) ? ' checked' : '' ?><?= $dis ?>>
                    <span>
                      <?= Respond::e($tw['content']) ?>
                      <?php if ($tw['description'] !== ''): ?><small><?= Respond::e($tw['description']) ?></small><?php endif; ?>
                      <?php if ($tw['explorer']): ?><small class="nota">Pode só valer no próximo login ou depois de reiniciar o Explorer.</small><?php endif; ?>
                      <?php if (in_array($tw['key'], $view->tweaksApplied, true)): ?><small class="aplicado">aplicado</small><?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          <?php endforeach; ?>
          <div class="row">
            <input type="hidden" name="Undo" value="1" id="tw-auto-undo"<?= $twReverte ? '' : ' disabled' ?>>
            <label class="switch">
              <input type="checkbox" name="Undo" value="1" id="tw-undo"<?= $dis ?>>
              <span class="trilho"></span>
              <span class="rotulo">Reverter (-Undo)</span>
            </label>
            <button type="submit" class="btn btn-sm" id="tw-botao"<?= $dis ?>><?= $twReverte ? 'Reverter' : 'Aplicar' ?></button>
          </div>
          <?php if ($view->tweaksApplied !== []): ?>
            <p class="dica">Reverter exige marcar só o que já está aplicado; com seleção mista, o botão aplica.</p>
          <?php endif; ?>
          <p class="dica">
            O preset marca as caixas dele; a seleção que não corresponde a nenhum se chama
            <strong>personalizada</strong>. O que vai para a ação são as caixas marcadas, e elas ficam guardadas
            para a próxima visita.
          </p>
        </form>
      <?php endif; ?>
    </section>
    <section id="acao-debloat">
      <?= $secao('Remover apps', 'Remove os pacotes APPX marcados, lidos do src/Win/config/debloat.json — o mesmo arquivo que a ação lê.') ?>
      <?php if ($view->debloatProblem !== null): ?>
        <p class="bad"><?= Respond::e($view->debloatProblem) ?></p>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
          <input type="hidden" name="acao" value="debloat">
          <input type="hidden" name="PackagesForm" value="1">
          <div class="colunas-4">
            <?php foreach ($view->debloatPackages as $pacote): ?>
              <label class="caixa caixa-mono">
                <input type="checkbox" name="Packages[]" value="<?= Respond::e($pacote) ?>"<?= in_array($pacote, $view->debloatChecked, true) ? ' checked' : '' ?><?= $dis ?>>
                <span><?= Respond::e($pacote) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="row"><button type="submit" class="btn btn-sm"<?= $dis ?>>Remover marcados</button></div>
          <p class="dica">Sem seleção guardada, os <?= count($view->debloatPackages) ?> vêm marcados, que é a lista inteira do arquivo.</p>
        </form>
      <?php endif; ?>
    </section>
  </div>

  <?= $painel(WinTab::Rede) ?>
    <section id="acao-dns">
      <?= $secao('DNS', 'Troca o DNS dos adaptadores de rede ativos. A lista vem do src/Win/config/dns.json.') ?>
      <?php if ($view->dnsProblem !== null): ?>
        <p class="bad"><?= Respond::e($view->dnsProblem) ?></p>
      <?php else: ?>
        <form method="post" id="form-dns">
          <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
          <input type="hidden" name="acao" value="dns">
          <div class="colunas-4">
            <?php foreach ($view->dnsProviders as $dns): ?>
              <label class="caixa">
                <input type="radio" name="Provider" value="<?= Respond::e($dns['key']) ?>"<?= strcasecmp($view->dnsChosen['Provider'] ?? '', $dns['key']) === 0 ? ' checked' : '' ?><?= $dis ?>>
                <span>
                  <?= Respond::e($dns['label']) ?>
                  <?php if ($dns['text'] !== ''): ?><small><?= Respond::e($dns['text']) ?></small><?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <div id="dns-custom">
            <div class="campo">
              <label for="dns-p1">Primário</label>
              <input type="text" id="dns-p1" name="PrimaryDNS" placeholder="192.168.1.10"
                     value="<?= Respond::e($view->dnsChosen['PrimaryDNS'] ?? '') ?>"<?= $dis ?>>
            </div>
            <div class="campo">
              <label for="dns-p2">Secundário</label>
              <input type="text" id="dns-p2" name="SecondaryDNS" placeholder="opcional"
                     value="<?= Respond::e($view->dnsChosen['SecondaryDNS'] ?? '') ?>"<?= $dis ?>>
            </div>
          </div>
          <div class="row"><button type="submit" class="btn btn-sm"<?= $dis ?>>Aplicar DNS</button></div>
          <p class="dica">
            O DNS próprio exige o primário, e só aceita endereço IP válido. A escolha e os endereços
            ficam guardados para a próxima visita.
          </p>
        </form>
      <?php endif; ?>
    </section>
    <section id="acao-network">
      <?= $secao('Captura de rede', 'Captura pacotes com o TShark e gera relatório em runtime/Reports, na pasta do projeto.') ?>
      <form method="post">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="network">
        <div class="campo">
          <label for="net-if">Interface</label>
          <input type="text" id="net-if" name="Interface" placeholder="Ethernet"<?= $dis ?>>
        </div>
        <div class="campo">
          <label for="net-dur">Duração</label>
          <input type="text" id="net-dur" name="Duration" placeholder="30"<?= $dis ?>>
        </div>
        <div class="row"><button type="submit" class="btn btn-sm"<?= $dis ?>>Capturar</button></div>
        <p class="dica">
          A interface é <strong>obrigatória</strong>: sem ela a ação recusa e pede por
          <code>Read-Host</code>, e num processo não interativo isso falha com erro que não explica
          nada. Duração em segundos, de <?= \App\Win\WinAction::NETWORK_DURATION_MIN ?> a
          <?= \App\Win\WinAction::NETWORK_DURATION_MAX ?>. Lembre do timeout de
          <?= (int) $view->timeout ?> s.
        </p>
      </form>
    </section>
  </div>

  <?= $painel(WinTab::Aplicativos) ?>
    <section id="acao-install">
      <?= $secao('Instalar apps', 'Instala apps pelo winget, por ID, separados por vírgula. As caixas abaixo somam os principais ao campo.') ?>
      <form method="post">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="install">
        <div class="campo">
          <label for="ins-apps">Apps</label>
          <input type="text" id="ins-apps" name="Apps"
                 placeholder="Mozilla.Firefox,Notepad++.Notepad++"<?= $dis ?>>
          <button type="submit" class="btn btn-campo"<?= $dis ?>>Instalar</button>
        </div>
        <div class="colunas-4">
          <?php foreach (\App\Win\WinAction::INSTALL_SUGGESTIONS as $id => $nome): ?>
            <label class="caixa">
              <input type="checkbox" name="AppsMarcados[]" value="<?= Respond::e($id) ?>"<?= $dis ?>>
              <span>
                <?= Respond::e($nome) ?>
                <small><code><?= Respond::e($id) ?></code></small>
                <?php if ($id === 'VB-Audio.Voicemeeter.Potato'): ?><small class="nota">Exige reiniciar o Windows para funcionar.</small><?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="dica">
          O campo é texto livre e manda: o catálogo do winget é aberto demais para uma lista curada.
          Cada app é instalado pelo ID exato. O teto de <?= number_format($view->maxParamBytes, 0, ',', '.') ?>
          bytes é recusado <strong>no servidor</strong>, não no navegador. O VoiceMeeter Potato só passa
          a funcionar depois de reiniciar o Windows.
        </p>
      </form>
    </section>
  </div>

  <?= $painel(WinTab::Servicos) ?>
    <section id="acao-exporter">
      <?= $secao('Métricas do Windows', 'Instala e controla o windows_exporter, para o Prometheus, na porta 9182.') ?>
      <form method="post">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="exporter">
        <div class="campo">
          <label for="exp-sub">Subação</label>
          <select id="exp-sub" name="SubAction"<?= $dis ?>>
            <?php foreach (\App\Win\WinAction::EXPORTER_SUBACTIONS as $s): ?>
              <?= $opcao($s) ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row"><button type="submit" class="btn btn-sm"<?= $dis ?>>Executar</button></div>
      </form>
    </section>
    <section id="acao-gpu">
      <?= $secao('Métricas da GPU', 'Instala e controla o nvidia_gpu_exporter, para o Prometheus.') ?>
      <form method="post">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="gpu">
        <div class="campo">
          <label for="gpu-sub">Subação</label>
          <select id="gpu-sub" name="SubAction"<?= $dis ?>>
            <?php foreach (\App\Win\WinAction::GPU_SUBACTIONS as $s): ?>
              <?= $opcao($s) ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row"><button type="submit" class="btn btn-sm"<?= $dis ?>>Executar</button></div>
        <p class="dica">Conjunto diferente das métricas do Windows: aqui existe <strong>desinstalar</strong> e não
          existe <strong>liberar no firewall</strong>.</p>
      </form>
    </section>
    <section id="acao-optimize">
      <?= $secao('Otimizar', 'Para processos de interface e desabilita os serviços por trás deles.') ?>
      <?php if ($optAplicado): ?>
        <div class="row"><?= $botao('optimize', 'Undo', '1', 'Reverter', false) ?></div>
      <?php endif; ?>
      <form method="post" id="form-optimize">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="optimize">
        <div class="campo">
          <label for="opt-preset">Preset</label>
          <select id="opt-preset" name="Preset"<?= $dis ?>>
            <option value="">(nenhum)</option>
            <option value="ssh">ssh — modo servidor</option>
            <option value="kill-rdp">kill-rdp — depois de desconectar o RDP</option>
          </select>
        </div>
        <div class="campo">
          <label for="opt-kill">Matar</label>
          <input type="text" id="opt-kill" name="Kill" placeholder="notepad,calc"<?= $dis ?>>
        </div>
        <div class="campo">
          <label for="opt-keep">Preservar</label>
          <input type="text" id="opt-keep" name="KeepUser" placeholder="usuário do RDP a não deslogar"<?= $dis ?>>
        </div>
        <div class="row">
          <label class="switch">
            <input type="checkbox" name="Undo" value="1" id="opt-undo"<?= $dis ?>>
            <span class="trilho"></span>
            <span class="rotulo">Restaurar (-Undo)</span>
          </label>
          <button type="submit" class="btn btn-sm<?= $optAplicado ? ' btn-ghost' : '' ?>"<?= $dis ?>>Executar</button>
        </div>
        <p class="dica">
          Precisa de pelo menos um: preset, lista de processos, ou restaurar. Os dois presets
          <strong>mexem nesta sessão</strong> — a tela avisa antes, dizendo exatamente o quê.
        </p>
      </form>
    </section>
    <section id="acao-gdid">
      <?= $secao('Dispositivos conectados (GDID)', 'Liga e desliga o pipeline de Connected Devices: serviços, histórico de atividades, domínios no hosts e o cache.') ?>
      <?php if ($gdidAplicado): ?>
        <div class="row"><?= $botao('gdid', 'SubAction', 'enable', 'Reverter', false) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
        <input type="hidden" name="acao" value="gdid">
        <div class="campo">
          <label for="gdid-sub">Subação</label>
          <select id="gdid-sub" name="SubAction"<?= $dis ?>>
            <?php foreach (\App\Win\WinAction::GDID_SUBACTIONS as $s): ?>
              <?= $opcao($s) ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row"><button type="submit" class="btn btn-sm<?= $gdidAplicado ? ' btn-ghost' : '' ?>"<?= $dis ?>>Executar</button></div>
        <p class="dica">
          O <strong>desligar</strong> (<code>disable</code>) <strong>corta as notificações do Windows</strong>: os domínios do
          WNS entram no bloqueio junto com os do GDID, e apps da Store param de receber aviso.
          É recuperável — <strong>religar</strong> (<code>enable</code>) devolve tudo, incluindo o startup original dos
          serviços. Comece por <strong>ver o estado</strong> (<code>status</code>), que só lê.
        </p>
      </form>
    </section>
    <section id="acao-performance">
      <?= $secao('Desempenho', 'Troca o plano de energia do Windows.') ?>
      <div class="row">
        <?php if ($perfAplicado): ?>
          <?= $botao('performance', 'State', 'off', 'Reverter', false) ?>
          <?= $botao('performance', 'State', 'on', 'Ativar desempenho máximo', true) ?>
        <?php else: ?>
          <?= $botao('performance', 'State', 'on', 'Ativar desempenho máximo', false) ?>
          <?= $botao('performance', 'State', 'off', 'Voltar ao Balanceado', true) ?>
        <?php endif; ?>
      </div>
      <p class="dica">
        <strong>Desempenho máximo</strong> deixa o processador sempre pronto para trabalhar no limite: a
        máquina responde mais rápido, mas gasta mais energia e esquenta mais. Se o Windows não tiver esse
        plano, usa o de alto desempenho. Em notebook, a bateria dura menos.
        <strong><?= $perfAplicado ? 'Reverter' : 'Voltar ao Balanceado' ?></strong> devolve o plano padrão
        do Windows, o Balanceado, que economiza quando a máquina está parada.
      </p>
    </section>
  </div>

  <?= $painel(WinTab::AcessoRemoto) ?>
    <section id="acao-rdp">
      <?= $secao('Área de Trabalho Remota (RDP)', 'Chegar a esta máquina de outro computador, pela Área de Trabalho Remota do Windows.') ?>
      <div class="row">
        <?= $botao('rdp', 'SubAction', 'status', 'Ver o estado', true) ?>
      </div>
      <div class="row">
        <?php if ($rdpAplicado): ?>
          <?= $botao('rdp', 'SubAction', 'off', 'Desligar acesso remoto', false) ?>
          <?= $botao('rdp', 'SubAction', 'on', 'Ligar acesso remoto', true) ?>
        <?php else: ?>
          <?= $botao('rdp', 'SubAction', 'on', 'Ligar acesso remoto', false) ?>
          <?= $botao('rdp', 'SubAction', 'off', 'Desligar acesso remoto', true) ?>
        <?php endif; ?>
      </div>
      <p class="dica">
        <strong>Ligar</strong> deixa esta máquina aceitar conexão de Área de Trabalho Remota de outro
        computador, e abre a porta dela no firewall do Windows. Vale na hora.
        <strong><?= $rdpAplicado ? 'Desligar' : 'Ligar' ?></strong> é a volta: a máquina passa a recusar
        de novo. Comece por <strong>Ver o estado</strong>, que só lê e não muda nada.
      </p>
      <?php if ($rdpPendente): ?>
        <div class="alert alert-note">
          O vídeo H.264/UDP foi ligado por esta tela e <strong>só passa a valer depois de reiniciar o
          Windows</strong>. Reverter antes de reiniciar desfaz essa mudança.
        </div>
        <div class="row">
          <?= $botao('rdp', 'SubAction', 'h264-off', 'Reverter vídeo H.264/UDP', false) ?>
        </div>
      <?php else: ?>
        <div class="row">
          <?= $botao('rdp', 'SubAction', 'h264-on', 'Ativar vídeo H.264/UDP', true) ?>
        </div>
      <?php endif; ?>
      <p class="dica">
        O <strong>vídeo H.264/UDP</strong> deixa a imagem da conexão mais fluida e com menos atraso,
        gastando um pouco mais de processador. Ao contrário de ligar e desligar,
        <strong>só passa a valer depois de reiniciar o Windows</strong> — por isso fica marcado como
        pendente de reinício até você reiniciar ou reverter.
      </p>
    </section>
    <section id="acesso-sunshine">
      <?= $secao('Sunshine', 'Transmitir esta máquina pelo Sunshine, com baixa latência.') ?>
      <p class="empty">Em breve.</p>
    </section>
  </div>

  <!-- ------------------------------------------------------------- histórico -->
  <section>
    <h2>Histórico do Windows</h2>

    <?php if ($view->rows === []): ?>
      <p class="empty">Nenhuma execução do Windows registrada.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th class="c-in">Comando</th><th class="c-out">Saída</th><th class="c-dt">Quando</th></tr>
        </thead>
        <tbody>
          <?php foreach ($view->rows as $row): ?>
            <tr>
              <td>
                <span class="tag">windows</span>
                <pre><?= Respond::e($row->command) ?></pre>
              </td>
              <td><pre><?= Respond::e($row->output) ?></pre></td>
              <td>
                <?= Respond::e(Respond::dateTime($row->createdAt, $view->tz)) ?>
                <br>
                <span class="meta" style="margin:0">
                  <?= number_format($row->durationSeconds(), 1, ',', '.') ?> s
                </span>
                <br>
                <?php if ($row->timedOut): ?>
                  <span class="bad">tempo esgotado</span>
                <?php elseif ($row->exitCode === 0): ?>
                  <span class="ok">0</span>
                <?php else: ?>
                  <span class="bad"><?= $row->exitCode === null ? '—' : (int) $row->exitCode ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post" style="margin-top:14px">
      <input type="hidden" name="<?= Respond::e($view->csrfField) ?>" value="<?= Respond::e($view->csrfToken) ?>">
      <input type="hidden" name="acao" value="limpar">
      <div class="row">
        <button type="submit" class="btn btn-danger btn-sm">Limpar histórico do Windows</button>
      </div>
      <p class="dica">Apaga só os registros de tipo <code>windows</code> — o histórico do WSL fica.</p>
    </form>
  </section>

  <p class="sub" style="margin-top:18px">
    Motor: <code>src/Win/</code>
    <?php if ($view->win->on): ?>
      &middot; PowerShell elevado de pé, PID <?= (int) $view->win->psPid ?>
    <?php endif; ?>
  </p>
</div>

<!-- ------------------------------------------------------ modais do optimize -->
<!--
  Cada preset ganha o SEU modal, nomeando a consequência exata em vez de um
  aviso genérico. Aviso genérico ensina a clicar sem ler; dizer "o botão WSL
  vai apagar" e "o explorer desta sessão vai morrer" é o que faz a pessoa
  parar e pensar.
-->
<dialog id="modal-ssh">
  <div class="modal-corpo">
    <h3>O preset <code>ssh</code> desabilita o WslService</h3>
    <p>
      Ele para os processos de interface e <strong>desabilita os serviços por trás deles</strong> —
      e um deles é o <code>WslService</code>.
    </p>
    <p>
      Consequência direta aqui: <strong>o botão WSL desta ferramenta vai apagar</strong>, e a tela
      <code>/wsl</code> passa a dizer que o WSL não respondeu, até alguém rodar
      <code>optimize -Undo</code>. Não é perda de dado, e é reversível — mas é uma metade do
      PHPorto desligando a outra.
    </p>
    <p>
      Também para <code>LogonUI</code>, <code>SearchHost</code>, <code>StartMenuExperienceHost</code>,
      <code>TextInputHost</code>, <code>msedgewebview2</code>, <code>LDSvc</code> (iLok) e
      <code>OfficeClickToRun</code>.
    </p>
  </div>
  <div class="modal-acoes">
    <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
    <button type="button" class="btn btn-danger btn-sm" data-confirmar-optimize>Executar mesmo assim</button>
  </div>
</dialog>

<dialog id="modal-killrdp">
  <div class="modal-corpo">
    <h3>O preset <code>kill-rdp</code> mata o explorer desta sessão</h3>
    <p>
      Ele faz logoff das sessões RDP desconectadas e <strong>limpa o que elas deixam para trás</strong>
      — e a limpeza inclui <code>explorer</code>, <code>dwm</code> e <code>sihost</code>.
    </p>
    <p>
      Consequência direta aqui: <strong>é a área de trabalho onde este navegador está aberto</strong>.
      A barra de tarefas e a área de trabalho somem. A janela do navegador tende a sobreviver, e o
      <code>dwm</code> volta sozinho, mas você fica sem shell até reiniciar o
      <code>explorer</code> ou a máquina.
    </p>
    <p>
      Este preset foi escrito para ser chamado <strong>por SSH</strong>, de fora da sessão gráfica.
      Chamá-lo do navegador é chamá-lo de dentro do que ele derruba.
    </p>
  </div>
  <div class="modal-acoes">
    <button type="button" class="btn btn-ghost btn-sm" data-fechar>Cancelar</button>
    <button type="button" class="btn btn-danger btn-sm" data-confirmar-optimize>Executar mesmo assim</button>
  </div>
</dialog>

<script>
(function () {
  'use strict';

  document.querySelectorAll('dialog [data-fechar]').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('dialog').close(); });
  });

  // ---- DNS: os campos de IP só aparecem no DNS próprio -------------------
  // Sem JavaScript eles ficam sempre à vista, e o DNS próprio continua usável.
  var dnsForm   = document.getElementById('form-dns');
  var dnsCustom = document.getElementById('dns-custom');

  function pintarDns() {
    var marcado = dnsForm.querySelector('input[name="Provider"]:checked');
    dnsCustom.hidden = !marcado || marcado.value.toLowerCase() !== 'custom';
  }

  if (dnsForm) {
    dnsForm.querySelectorAll('input[name="Provider"]').forEach(function (r) { r.addEventListener('change', pintarDns); });
    pintarDns();
  }

  // ---- Tweaks: o preset marca as caixas, e a seleção diz o nome ---------
  var PRESETS = <?= json_encode((object) $view->tweakPresets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var ROTULOS = <?= json_encode($presetRotulos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var formTw  = document.getElementById('form-tweaks');

  function nomeDaSelecao(marcadas) {
    if (marcadas.length === 0) { return 'nenhuma'; }
    var alvo = marcadas.slice().sort().join(',');
    for (var nome in PRESETS) {
      if (Object.prototype.hasOwnProperty.call(PRESETS, nome) && PRESETS[nome].slice().sort().join(',') === alvo) {
        return nome;
      }
    }
    return 'custom';
  }

  // ---- Tweaks: o botão só diz Reverter quando todas as marcadas estão aplicadas
  var APLICADOS = <?= json_encode($view->tweaksApplied, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  if (formTw) {
    var caixasTw = formTw.querySelectorAll('input[name="Items[]"]');
    var rotuloTw = document.getElementById('tw-match');
    var autoUndo = document.getElementById('tw-auto-undo');
    var undoTw   = document.getElementById('tw-undo');
    var botaoTw  = document.getElementById('tw-botao');

    var pintarTw = function () {
      var marcadas = [];
      caixasTw.forEach(function (c) { if (c.checked) { marcadas.push(c.value); } });
      var nome = nomeDaSelecao(marcadas);
      rotuloTw.textContent = ROTULOS[nome] || nome;

      var reverte = marcadas.length > 0 && marcadas.every(function (k) { return APLICADOS.indexOf(k) !== -1; });
      autoUndo.disabled = !reverte;
      botaoTw.textContent = reverte || undoTw.checked ? 'Reverter' : 'Aplicar';
    };

    caixasTw.forEach(function (c) { c.addEventListener('change', pintarTw); });
    undoTw.addEventListener('change', pintarTw);

    formTw.querySelectorAll('[data-preset]').forEach(function (b) {
      b.addEventListener('click', function () {
        var chaves = PRESETS[b.getAttribute('data-preset')] || [];
        caixasTw.forEach(function (c) { c.checked = chaves.indexOf(c.value) !== -1; });
        pintarTw();
      });
    });
  }

  // ---- Optimize: cada preset tem o seu aviso -----------------------------
  var formOpt = document.getElementById('form-optimize');
  var optPre  = document.getElementById('opt-preset');
  var forcar  = false;

  if (formOpt) {
    formOpt.addEventListener('submit', function (ev) {
      if (forcar) { return; }

      var modal = null;
      if (optPre.value === 'ssh') { modal = document.getElementById('modal-ssh'); }
      if (optPre.value === 'kill-rdp') { modal = document.getElementById('modal-killrdp'); }

      // -Undo é o botão de socorro: restaurar não precisa de aviso.
      if (modal && !document.getElementById('opt-undo').checked) {
        ev.preventDefault();
        modal.showModal();
      }
    });

    document.querySelectorAll('[data-confirmar-optimize]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.closest('dialog').close();
        forcar = true;
        formOpt.submit();
      });
    });
  }

  // ---- Abas: troca o painel sem recarregar -------------------------------
  // SEGUNDA CAMADA, PARA CONFORTO: sem JavaScript, o link /win?aba=<nome>#abas
  // recarrega a página já posicionada no menu. Aqui o clique só mostra o painel
  // e troca a URL com replaceState, porque os formulários postam para a URL da
  // página e o 303 volta para a aba que ela carrega.
  var abas = document.getElementById('abas');

  if (abas && window.history && history.replaceState) {
    abas.addEventListener('click', function (ev) {
      var link = ev.target.closest('a[data-aba]');
      if (!link || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }

      var painel = document.getElementById('painel-' + link.getAttribute('data-aba'));
      if (!painel) { return; }

      ev.preventDefault();
      document.querySelectorAll('.painel').forEach(function (p) { p.hidden = p !== painel; });
      abas.querySelectorAll('a[data-aba]').forEach(function (a) {
        var atual = a === link;
        a.classList.toggle('ativa', atual);
        if (atual) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
      });
      history.replaceState(null, '', link.getAttribute('href'));
    });
  }

  // ---- Segunda barreira contra duplo envio -------------------------------
  // CONFORTO, NÃO PROTEÇÃO: a garantia de "uma intenção, uma execução" é o
  // token de uso único no servidor. Isto só evita o segundo clique ansioso
  // numa ação que pode levar minutos sem dar sinal.
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = 'executando…'; }
    });
  });
})();
</script>
