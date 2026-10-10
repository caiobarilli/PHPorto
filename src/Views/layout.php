<?php

declare(strict_types=1);

/**
 * O documento. White mode, sem framework, sem CDN: CSS e JS embutidos e
 * servidos pelo próprio PHP — a ferramenta não tem nenhum arquivo estático, e
 * é isso que permite o router ser lista de permissão.
 *
 * @var \App\Http\LayoutView $view
 */

use App\Http\Respond;

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Respond::e($view->title) ?></title>
<style>
  :root {
    --ink:   #1b1f24;
    --mut:   #6b7280;
    --line:  #d6d9de;
    --bg:    #ffffff;
    --panel: #fafbfc;
    --btn:   #262626;
    --mono:  ui-monospace, Consolas, "Courier New", monospace;
    --sans:  "Segoe UI Variable Display", "Segoe UI", system-ui, sans-serif;
    --mono-console: "JetBrainsMono Nerd Font", Consolas, ui-monospace, monospace;
    --oh-bg:          #282C34;
    --oh-fg:          #DCDFE4;
    --oh-black:       #282C34;
    --oh-red:         #E06C75;
    --oh-green:       #98C379;
    --oh-yellow:      #E5C07B;
    --oh-blue:        #61AFEF;
    --oh-purple:      #C678DD;
    --oh-cyan:        #56B6C2;
    --oh-brightblack: #5A6374;
    --oh-cursor:      #FFFFFF;
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font: 14px/1.5 var(--sans);
    -webkit-font-smoothing: antialiased;
  }
  a { color: inherit; }

  /* ---- home ---- */
  .home {
    min-height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 34px;
    padding: 40px 24px;
    text-align: center;
  }
  .home h1 {
    margin: 0;
    font-family: var(--sans);
    font-size: clamp(48px, 12vw, 104px);
    font-weight: 600;
    letter-spacing: -0.02em;
    line-height: 1;
  }
  .home-actions { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; }
  .home-note { color: var(--mut); font-size: 13px; margin: 0; max-width: 46ch; }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-width: 132px;
    padding: 13px 22px;
    border: 0;
    border-radius: 12px;
    background: var(--btn);
    color: #fff;
    font: 500 14px/1 var(--sans);
    text-decoration: none;
    cursor: pointer;
  }
  .btn:hover { background: #3a3a3a; }
  .btn svg { width: 17px; height: 17px; display: block; }
  .btn.is-disabled {
    opacity: .38;
    cursor: not-allowed;
    pointer-events: none;
  }

  /* ---- Botão só de ícone -------------------------------------------------
     A engrenagem se lê sem legenda; WIN e WSL não. O min-width de 132px do
     .btn deixaria o ícone sozinho perdido no meio de um botão largo, então
     este modificador o solta e deixa o botão quadrado. O nome fica no
     aria-label, que é o que o leitor de tela anuncia. */
  .btn-icone { min-width: 0; padding: 13px; }
  .btn-icone svg { width: 21px; height: 21px; }

  /* A home pode ter mais de um botão desabilitado, e cada um por um motivo
     diferente — o WSL por não achar a distro, o WIN por não haver PowerShell
     elevado. Uma nota por motivo, e não um parágrafo só tentando dizer os
     dois. */
  .home-notas { display: flex; flex-direction: column; gap: 7px; align-items: center; margin: 0; padding: 0; list-style: none; }
  .home-notas li { color: var(--mut); font-size: 13px; max-width: 52ch; }
  .home-notas strong { color: var(--ink); font-weight: 600; }

  /* ---- páginas internas ---- */
  .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
  .topbar { display: flex; align-items: baseline; gap: 14px; margin-bottom: 6px; }
  .topbar h1 { font-size: 17px; margin: 0; font-weight: 600; }
  .topbar a { color: var(--mut); font-size: 13px; text-decoration: none; }
  .topbar a:hover { color: var(--ink); }
  .sub { color: var(--mut); font-size: 12px; margin: 0 0 18px; }
  .sub code { background: #f1f3f5; padding: 1px 5px; border-radius: 4px; font-family: var(--mono); }

  section { border: 1px solid var(--line); border-radius: 10px; padding: 16px; margin-bottom: 16px; background: var(--bg); }
  h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .09em; color: var(--mut); margin: 0 0 10px; font-weight: 600; }

  .alert { border: 1px solid var(--line); border-left-width: 3px; border-radius: 8px; padding: 10px 13px; margin-bottom: 14px; font-size: 13px; background: var(--panel); }
  .alert-block { border-left-color: #b91c1c; }
  .alert-note  { border-left-color: #262626; }
  .alert p { margin: 8px 0 0; }
  .alert details { margin-top: 8px; } .alert summary { color: var(--mut); cursor: pointer; font-size: 12px; }
  .alert details pre { margin: 6px 0 0; font: 12px/1.45 var(--mono); white-space: pre-wrap; max-height: 220px; overflow: auto; }

  textarea, input[type=text] {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--bg);
    color: var(--ink);
    font: 13px/1.45 var(--mono);
  }
  textarea { height: 190px; resize: vertical; }
  textarea:disabled { background: var(--panel); color: var(--ink); }
  .campo { display: flex; align-items: center; gap: 12px; margin-bottom: 9px; }
  .campo label { width: 74px; flex: none; color: var(--mut); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; }
  .row { display: flex; gap: 8px; margin-top: 11px; align-items: center; flex-wrap: wrap; }
  .dica { color: var(--mut); font-size: 12px; margin: 11px 0 0; }
  .dica code { background: #f1f3f5; padding: 1px 5px; border-radius: 4px; font-family: var(--mono); }

  .btn-sm { min-width: 0; padding: 8px 14px; border-radius: 8px; font-size: 13px; }
  .btn-ghost { background: var(--bg); color: var(--ink); border: 1px solid var(--line); }
  .btn-ghost:hover { background: var(--panel); }
  .btn-danger { background: var(--bg); color: #b91c1c; border: 1px solid var(--line); }
  .btn-danger:hover { background: #fef2f2; }
  button[disabled] { opacity: .5; cursor: progress; }

  .meta { color: var(--mut); font-size: 12px; margin-left: auto; font-variant-numeric: tabular-nums; }
  .ok { color: #15803d; } .bad { color: #b91c1c; }
  .vm-parada { color: var(--mut); }
  .vm-ip { font: 12px var(--mono); }

  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 1px solid var(--line); padding: 8px; vertical-align: top; text-align: left; overflow-wrap: anywhere; }
  th { background: var(--panel); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--mut); font-weight: 600; }
  td pre { margin: 0; font: 12px/1.45 var(--mono); white-space: pre-wrap; max-height: 220px; overflow: auto; }
  .c-in { width: 32%; } .c-out { width: 52%; } .c-dt { width: 16%; }
  .tag { display: inline-block; font-size: 10px; text-transform: uppercase; letter-spacing: .07em; padding: 2px 7px; border-radius: 10px; background: #ececf0; color: #3f3f46; margin-bottom: 6px; }
  .empty { color: var(--mut); padding: 12px 0; }

  .kv { display: grid; grid-template-columns: 130px 1fr; gap: 8px 16px; font-size: 13px; }
  .kv dt { color: var(--mut); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; padding-top: 2px; }
  .kv dd { margin: 0; font-family: var(--mono); overflow-wrap: anywhere; }
  .provider { font: 600 22px/1 var(--sans); letter-spacing: -0.01em; }

  .colunas-4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px 16px; margin: 10px 0 0; }
  @media (max-width: 720px) { .colunas-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  .caixa { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; line-height: 1.35; overflow-wrap: anywhere; cursor: pointer; }
  .caixa input { margin: 2px 0 0; flex: none; }
  .caixa small { display: block; color: var(--mut); font-size: 11.5px; line-height: 1.4; margin-top: 2px; }
  .caixa .nota { color: #92400e; }
  .caixa .aplicado { color: #15803d; font-weight: 600; }

  .principais { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
  .principais > section { margin: 0; }
  @media (max-width: 900px) { .principais { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  .abas { display: flex; gap: 4px; flex-wrap: wrap; border-bottom: 1px solid var(--line); margin: 0 0 16px; }
  .abas a { padding: 9px 14px; color: var(--mut); text-decoration: none; font-size: 13px; border-bottom: 2px solid transparent; margin-bottom: -1px; }
  .abas a:hover { color: var(--ink); }
  .abas a.ativa { color: var(--ink); font-weight: 600; border-bottom-color: var(--btn); }
  .caixa-mono { font: 12px/1.5 var(--mono); }
  .btn-campo { align-self: stretch; min-width: 0; padding: 0 18px; border-radius: 8px; flex: none; }
  .grupo { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px 12px; margin: 12px 0 0; }
  .grupo legend { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--mut); padding: 0 6px; }
  .grupo.cuidado { border-color: #d97706; background: #fffbeb; }
  .grupo.cuidado legend { color: #b45309; font-weight: 600; }

  /* ---- Interruptor da /config -------------------------------------------
     Um checkbox de verdade por baixo: o rótulo continua clicável, o teclado
     continua funcionando e o formulário continua enviando sem JS. O visual
     é só a pintura do :checked. */
  .switch { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
  .switch input { position: absolute; opacity: 0; width: 0; height: 0; }
  .switch .trilho {
    width: 40px; height: 22px; border-radius: 999px; background: #d6d9de;
    position: relative; transition: background .16s ease; flex: none;
  }
  .switch .trilho::after {
    content: ""; position: absolute; top: 3px; left: 3px;
    width: 16px; height: 16px; border-radius: 50%; background: #fff;
    transition: transform .16s ease; box-shadow: 0 1px 2px rgba(0,0,0,.25);
  }
  .switch input:checked + .trilho { background: #16a34a; }
  .switch input:checked + .trilho::after { transform: translateX(18px); }
  .switch input:focus-visible + .trilho { outline: 2px solid var(--btn); outline-offset: 2px; }
  .switch .rotulo { font-size: 13px; }

  .linha-acao { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 4px; }
  .nota-estado { color: var(--mut); font-size: 12px; margin: 10px 0 0; }
  .nota-estado strong { color: var(--ink); font-weight: 600; }

  /* ---- Modal ------------------------------------------------------------
     <dialog> nativo em vez de confirm(): o confirm() do navegador não cabe
     texto de risco, não aceita uma segunda opção dentro dele, e trava a
     página inteira enquanto está aberto. */
  dialog {
    border: 1px solid var(--line); border-radius: 12px; padding: 0;
    max-width: 460px; width: calc(100% - 32px); color: var(--ink); background: var(--bg);
    box-shadow: 0 12px 40px rgba(0,0,0,.18);
  }
  dialog::backdrop { background: rgba(15,17,21,.45); }
  .modal-corpo { padding: 20px 20px 4px; }
  .modal-corpo h3 { margin: 0 0 10px; font-size: 15px; }
  .modal-corpo p { margin: 0 0 12px; font-size: 13px; line-height: 1.5; color: var(--mut); }
  .modal-corpo p strong { color: var(--ink); }
  .modal-corpo code { background: #f1f3f5; padding: 1px 5px; border-radius: 4px; font-family: var(--mono); font-size: 12px; }
  .modal-opcao {
    display: flex; gap: 9px; align-items: flex-start; font-size: 13px;
    border: 1px solid var(--line); border-radius: 8px; padding: 11px 12px; margin-bottom: 12px; cursor: pointer;
  }
  .modal-opcao input { margin: 2px 0 0; flex: none; }
  .modal-opcao span { color: var(--mut); display: block; margin-top: 3px; font-size: 12px; }
  .modal-acoes { display: flex; justify-content: flex-end; gap: 8px; padding: 8px 20px 20px; }

  /* ---- Aviso no rodapé ----------------------------------------------------
     Depois de uma ação sem reload a página fica onde estava, e o aviso do topo
     pode estar fora da vista. A faixa repete o aviso embaixo, e o aria-live
     faz o leitor de tela anunciar. */
  .aviso-rodape {
    position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%);
    width: min(720px, calc(100% - 32px)); display: flex; gap: 12px; align-items: flex-start;
    border: 1px solid var(--line); border-left: 3px solid #262626; border-radius: 8px;
    padding: 10px 13px; background: var(--bg); font-size: 13px; box-shadow: 0 6px 24px rgba(0,0,0,.14);
  }
  .aviso-rodape[hidden] { display: none; }
  .aviso-rodape span { flex: 1; }
  .aviso-rodape button { border: 0; background: none; color: var(--mut); font-size: 16px; line-height: 1; cursor: pointer; }
</style>
</head>
<body>
<main id="conteudo"><?= $view->content ?></main>

<div class="aviso-rodape" id="aviso-rodape" role="status" aria-live="polite" hidden>
  <span id="aviso-rodape-texto"></span>
  <button type="button" id="aviso-rodape-fechar" aria-label="Fechar aviso">&times;</button>
</div>

<script>
/*
 * Ações sem reload ("PRG por fetch"). O servidor não muda: o formulário
 * marcado com data-sem-reload manda o MESMO POST, recebe o MESMO 303, e quem
 * segue o 303 é este script. O HTML que volta já traz o aviso, o token novo,
 * os painéis e o histórico, desenhados pelo PHP — daqui só se troca o miolo
 * (o .wrap dentro do #conteudo). Os <dialog> e os <script> das telas ficam
 * fora do .wrap e não são trocados; as telas ligam os eventos por delegação
 * e ouvem "phporto:trocou" para repintar o que for preciso.
 *
 * Sem fetch/DOMParser (navegador muito velho), não faz nada: o envio nativo
 * segue igual a antes. Em erro de rede NUNCA reenvia o POST: faz um GET da
 * tela para conferir o que aconteceu.
 */
(function () {
  'use strict';

  // Cancelar em qualquer modal fecha o modal, e só isso: nada é enviado.
  // O último botão clicado fica guardado para o navegador sem ev.submitter.
  var ultimoBotao = null;
  document.addEventListener('click', function (ev) {
    var fechar = ev.target.closest('dialog [data-fechar]');
    if (fechar) { fechar.closest('dialog').close(); }
    ultimoBotao = ev.target.closest('button, input[type=submit]');
  });

  if (!window.fetch || !window.DOMParser || !window.URLSearchParams || !window.FormData) { return; }

  var CAMPO = <?= json_encode(\App\Http\Csrf::fieldName(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var aviso = document.getElementById('aviso-rodape');
  var avisoTexto = document.getElementById('aviso-rodape-texto');
  var ocupado = false;

  document.getElementById('aviso-rodape-fechar').addEventListener('click', function () { aviso.hidden = true; });

  function mostrarAviso(texto) {
    avisoTexto.textContent = texto;
    aviso.hidden = texto === '';
  }

  function miolo(raiz) { return raiz.querySelector('#conteudo .wrap'); }

  // Na falha, a página é recarregada por GET, nunca pelo POST: não se sabe se
  // a ação rodou, e o histórico é quem conta.
  function conferir() {
    mostrarAviso('Não deu para saber se a ação terminou. Recarregando a página para conferir…');
    location.replace(location.pathname + location.search);
  }

  // Um envio por vez: o token é um só por página, e um segundo envio seria
  // recusado de qualquer jeito. O botão clicado conta o tempo.
  function travar(form, botao) {
    document.querySelectorAll('#conteudo button:not([type=button]), #conteudo input[type=submit]').forEach(function (b) { b.disabled = true; });
    if (!botao || botao.tagName !== 'BUTTON') { botao = form.querySelector('button:not([type=button])'); }
    if (!botao) { return null; }

    var uac = botao.hasAttribute('data-uac') || form.querySelector('option[data-uac]:checked') !== null;
    var texto = uac ? 'aguardando o UAC… ' : 'executando… ';
    var t0 = Date.now();
    botao.textContent = texto + '0 s';

    return setInterval(function () {
      botao.textContent = texto + Math.round((Date.now() - t0) / 1000) + ' s';
    }, 1000);
  }

  function trocar(html, url, foco) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var novo = miolo(doc);
    var atual = miolo(document);
    if (!novo || !atual) { throw new Error('sem miolo'); }

    var y = window.scrollY;
    atual.replaceWith(document.adoptNode(novo));
    window.scrollTo(0, y);
    document.title = doc.title;
    history.replaceState(null, '', url);

    // O token velho foi queimado: todo campo de token da página, inclusive os
    // de dentro de <dialog> fora do miolo, recebe o novo.
    var token = novo.querySelector('input[name="' + CAMPO + '"]');
    if (token) {
      document.querySelectorAll('input[name="' + CAMPO + '"]').forEach(function (i) { i.value = token.value; });
    }

    // Segredo não fica em campo depois da resposta, mesmo fora do miolo.
    document.querySelectorAll('input[type=password], [data-segredo]').forEach(function (i) { i.value = ''; });

    var nota = novo.querySelector('[data-aviso]');
    mostrarAviso(nota ? nota.textContent.replace(/\s+/g, ' ').trim() : '');

    var alvo = foco && document.getElementById(foco);
    if (alvo) {
      var focavel = alvo.matches('button, input, select, textarea') ? alvo : alvo.querySelector('button:not([disabled]), input:not([type=hidden]):not([disabled])');
      if (focavel) { focavel.focus({ preventScroll: true }); }
    }

    document.dispatchEvent(new CustomEvent('phporto:trocou'));
  }

  // Ouve no document, então vale também para formulários que acabaram de ser
  // trocados. As telas que confirmam em modal cancelam o submit antes
  // (preventDefault) e chamam requestSubmit() depois da confirmação.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (ev.defaultPrevented || !form.hasAttribute('data-sem-reload') || form.method.toLowerCase() !== 'post') { return; }
    ev.preventDefault();
    if (ocupado) { return; }
    ocupado = true;

    var botao = ev.submitter || (ultimoBotao && ultimoBotao.form === form && ultimoBotao.type === 'submit' ? ultimoBotao : null);
    var dados = new FormData(form);
    // O valor do botão que enviou (o "Tentar novamente" manda ps_enabled=1 por ele).
    if (botao && botao.name) { dados.append(botao.name, botao.value); }

    document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });

    var foco = (botao && botao.id) || form.id || (form.closest('[id]:not(#conteudo)') || {}).id || '';
    aviso.hidden = true;
    var relogio = travar(form, botao);

    fetch(form.action, {
      method: 'POST',
      body: new URLSearchParams(dados),
      credentials: 'same-origin',
      mode: 'same-origin',
      redirect: 'follow',
      headers: { 'X-PHPorto-Sem-Reload': '1' }
    }).then(function (r) {
      var html = (r.headers.get('Content-Type') || '').indexOf('text/html') === 0;
      // 401, 404 de superfície desligada, 429, outra origem: navegação normal
      // por GET, e o navegador faz o que faria sem este script.
      if (!r.ok || !html || new URL(r.url).origin !== location.origin) {
        location.assign(r.url || location.href);
        return null;
      }
      return r.text().then(function (texto) { trocar(texto, r.url, foco); });
    }).then(function () {
      clearInterval(relogio);
      ocupado = false;
    }).catch(conferir);
  });
})();
</script>
</body>
</html>
