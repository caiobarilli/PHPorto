<?php

declare(strict_types=1);

/**
 * @var \App\Http\LayoutView $view
 */

use App\Http\Respond;

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<script id="tema">
(function () {
  try {
    localStorage.removeItem('phporto.wsl.console.tema');
    var t = localStorage.getItem('phporto.tema');
    if (t === 'claro' || t === 'escuro') { document.documentElement.setAttribute('data-tema', t); }
  } catch (e) { }
})();
</script>
<title><?= Respond::e($view->title) ?></title>
<style>
  :root {
    --ink:   #1b1f24;
    --mut:   #6b7280;
    --line:  #d6d9de;
    --bg:    #ffffff;
    --panel: #fafbfc;
    --btn:   #262626;
    --amber: #b45309;
    --btn-fg:    #ffffff;
    --btn-hover: #3a3a3a;
    --forte:     #262626;
    --ok:        #15803d;
    --bad:       #b91c1c;
    --bad-bg:    #fef2f2;
    --nota:      #92400e;
    --code-bg:   #f1f3f5;
    --tag-bg:    #ececf0;
    --tag-fg:    #3f3f46;
    --cuidado-borda: #d97706;
    --cuidado-bg:    #fffbeb;
    --trilho:        #d6d9de;
    --trilho-on:     #16a34a;
    --bolinha:       #ffffff;
    --claro-bg:      #ffffff;
    --claro-fg:      #1b1f24;
    --claro-hover:   #fafbfc;
    --term-borda:    none;
    --sombra-bolinha: rgba(0,0,0,.25);
    --sombra-modal:   rgba(0,0,0,.18);
    --sombra-aviso:   rgba(0,0,0,.14);
    --fundo-modal:    rgba(15,17,21,.45);
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
    --ohl-bg:          #FAFAFA;
    --ohl-fg:          #383A42;
    --ohl-red:         #E45649;
    --ohl-green:       #50A14F;
    --ohl-brightblack: #4F525D;
    --ohl-cursor:      #4F525D;
    color-scheme: light dark;
  }
  html[data-tema="claro"] { color-scheme: light; }
  html[data-tema="escuro"] {
    --ink:   #e6e8eb;
    --mut:   #9aa3ae;
    --line:  #343a43;
    --bg:    #16181d;
    --panel: #1d2026;
    --btn:   #e6e8eb;
    --amber: #fbbf24;
    --btn-fg:    #16181d;
    --btn-hover: #c9cdd3;
    --forte:     #e6e8eb;
    --ok:        #4ade80;
    --bad:       #f87171;
    --bad-bg:    #2a1517;
    --nota:      #fcd34d;
    --code-bg:   #262a31;
    --tag-bg:    #2a2e35;
    --tag-fg:    #d4d4d8;
    --cuidado-borda: #b45309;
    --cuidado-bg:    #241a0b;
    --trilho:        #4b5563;
    --trilho-on:     #16a34a;
    --bolinha:       #ffffff;
    --claro-bg:      #ffffff;
    --claro-fg:      #1b1f24;
    --claro-hover:   #e6e8eb;
    --term-borda:    1px solid var(--line);
    --sombra-bolinha: rgba(0,0,0,.5);
    --sombra-modal:   rgba(0,0,0,.5);
    --sombra-aviso:   rgba(0,0,0,.45);
    --fundo-modal:    rgba(0,0,0,.6);
    color-scheme: dark;
  }
  @media (prefers-color-scheme: dark) {
    html:not([data-tema]) {
      --ink:   #e6e8eb;
      --mut:   #9aa3ae;
      --line:  #343a43;
      --bg:    #16181d;
      --panel: #1d2026;
      --btn:   #e6e8eb;
      --amber: #fbbf24;
      --btn-fg:    #16181d;
      --btn-hover: #c9cdd3;
      --forte:     #e6e8eb;
      --ok:        #4ade80;
      --bad:       #f87171;
      --bad-bg:    #2a1517;
      --nota:      #fcd34d;
      --code-bg:   #262a31;
      --tag-bg:    #2a2e35;
      --tag-fg:    #d4d4d8;
      --cuidado-borda: #b45309;
      --cuidado-bg:    #241a0b;
      --trilho:        #4b5563;
      --trilho-on:     #16a34a;
      --bolinha:       #ffffff;
      --claro-bg:      #ffffff;
      --claro-fg:      #1b1f24;
      --claro-hover:   #e6e8eb;
      --term-borda:    1px solid var(--line);
      --sombra-bolinha: rgba(0,0,0,.5);
      --sombra-modal:   rgba(0,0,0,.5);
      --sombra-aviso:   rgba(0,0,0,.45);
      --fundo-modal:    rgba(0,0,0,.6);
      color-scheme: dark;
    }
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
    color: var(--btn-fg);
    font: 500 14px/1 var(--sans);
    text-decoration: none;
    cursor: pointer;
  }
  .btn:hover { background: var(--btn-hover); }
  .btn svg { width: 17px; height: 17px; display: block; }
  .btn.is-disabled {
    opacity: .38;
    cursor: not-allowed;
    pointer-events: none;
  }

  .btn-icone { min-width: 0; padding: 13px; }
  .btn-icone svg { width: 21px; height: 21px; }

  .home-notas { display: flex; flex-direction: column; gap: 7px; align-items: center; margin: 0; padding: 0; list-style: none; }
  .home-notas li { color: var(--mut); font-size: 13px; max-width: 52ch; }
  .home-notas strong { color: var(--ink); font-weight: 600; }

  .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
  .topbar { display: flex; align-items: baseline; gap: 14px; margin-bottom: 6px; }
  .topbar h1 { font-size: 17px; margin: 0; font-weight: 600; }
  .topbar a { color: var(--mut); font-size: 13px; text-decoration: none; }
  .topbar a:hover { color: var(--ink); }
  .sub { color: var(--mut); font-size: 12px; margin: 0 0 18px; }
  .sub code { background: var(--code-bg); padding: 1px 5px; border-radius: 4px; font-family: var(--mono); }

  section { border: 1px solid var(--line); border-radius: 10px; padding: 16px; margin-bottom: 16px; background: var(--bg); }
  h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .09em; color: var(--mut); margin: 0 0 10px; font-weight: 600; }

  .alert { border: 1px solid var(--line); border-left-width: 3px; border-radius: 8px; padding: 10px 13px; margin-bottom: 14px; font-size: 13px; background: var(--panel); }
  .alert-block { border-left-color: var(--bad); }
  .alert-note  { border-left-color: var(--forte); }
  .alert p { margin: 8px 0 0; }
  .alert details { margin-top: 8px; } .alert summary { color: var(--mut); cursor: pointer; font-size: 12px; }
  .alert details pre { margin: 6px 0 0; font: 12px/1.45 var(--mono); white-space: pre-wrap; max-height: 220px; overflow: auto; }

  textarea, input[type=text], .login input[type=password] {
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
  .login { max-width: 480px; padding-top: 12vh; }
  .dica { color: var(--mut); font-size: 12px; margin: 11px 0 0; }
  .dica code { background: var(--code-bg); padding: 1px 5px; border-radius: 4px; font-family: var(--mono); }

  .btn-sm { min-width: 0; padding: 8px 14px; border-radius: 8px; font-size: 13px; }
  .btn-ghost { background: var(--bg); color: var(--ink); border: 1px solid var(--line); }
  .btn-ghost:hover { background: var(--panel); }
  .btn-ghost[aria-pressed="true"] { background: var(--btn); color: var(--btn-fg); border-color: var(--btn); }
  .btn-danger { background: var(--bg); color: var(--bad); border: 1px solid var(--line); }
  .btn-danger:hover { background: var(--bad-bg); }
  button[disabled] { opacity: .5; cursor: progress; }

  .meta { color: var(--mut); font-size: 12px; margin-left: auto; font-variant-numeric: tabular-nums; }
  .ok { color: var(--ok); } .bad { color: var(--bad); }
  .vm-parada { color: var(--mut); }
  .vm-ip { font: 12px var(--mono); }

  .c-vm-acoes { width: 132px; }
  .vm-acoes { white-space: nowrap; }
  .vm-form { display: inline-block; margin: 0 2px 0 0; }
  .vm-acoes .btn-icone { padding: 6px; border-radius: 8px; }
  .vm-acoes .btn-icone svg { width: 16px; height: 16px; }
  .vm-start { color: var(--ok); } .vm-stop { color: var(--bad); } .vm-restart { color: var(--amber); }
  .vm-acoes button[disabled] { opacity: .3; cursor: not-allowed; }
  .vm-pendente { display: block; margin-top: 4px; color: var(--mut); font-size: 12px; white-space: normal; }

  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 1px solid var(--line); padding: 8px; vertical-align: top; text-align: left; overflow-wrap: anywhere; }
  th { background: var(--panel); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--mut); font-weight: 600; }
  td pre { margin: 0; font: 12px/1.45 var(--mono); white-space: pre-wrap; max-height: 220px; overflow: auto; }
  .c-in { width: 32%; } .c-out { width: 52%; } .c-dt { width: 16%; }
  .tag { display: inline-block; font-size: 10px; text-transform: uppercase; letter-spacing: .07em; padding: 2px 7px; border-radius: 10px; background: var(--tag-bg); color: var(--tag-fg); margin-bottom: 6px; }
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
  .caixa .nota { color: var(--nota); }
  .caixa .aplicado { color: var(--ok); font-weight: 600; }

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
  .grupo.cuidado { border-color: var(--cuidado-borda); background: var(--cuidado-bg); }
  .grupo.cuidado legend { color: var(--amber); font-weight: 600; }

  .term {
    --t-bg: var(--oh-bg); --t-fg: var(--oh-fg); --t-mut: var(--oh-brightblack);
    --t-ok: var(--oh-green); --t-bad: var(--oh-red); --t-cursor: var(--oh-cursor);
    --t-go-bg: #fff; --t-go-fg: #1b1f24;
    --term-bar:  color-mix(in srgb, var(--oh-black) 85%, #000);
    --term-line: color-mix(in srgb, var(--oh-brightblack) 45%, var(--oh-bg));
    background: var(--t-bg); color: var(--t-fg); color-scheme: dark;
    border: var(--term-borda); border-radius: 10px; overflow: hidden; margin-bottom: 0;
    font: 13px/1.45 var(--mono-console);
  }
  html[data-tema="claro"] .term {
    --t-bg: var(--ohl-bg); --t-fg: var(--ohl-fg); --t-mut: var(--ohl-brightblack);
    --t-ok: var(--ohl-green); --t-bad: var(--ohl-red); --t-cursor: var(--ohl-cursor);
    --t-go-bg: var(--ohl-fg); --t-go-fg: var(--ohl-bg);
    --term-bar:  color-mix(in srgb, var(--ohl-fg) 7%, var(--ohl-bg));
    --term-line: color-mix(in srgb, var(--ohl-brightblack) 25%, var(--ohl-bg));
    color-scheme: light;
  }
  @media (prefers-color-scheme: light) {
    html:not([data-tema]) .term {
      --t-bg: var(--ohl-bg); --t-fg: var(--ohl-fg); --t-mut: var(--ohl-brightblack);
      --t-ok: var(--ohl-green); --t-bad: var(--ohl-red); --t-cursor: var(--ohl-cursor);
      --t-go-bg: var(--ohl-fg); --t-go-fg: var(--ohl-bg);
      --term-bar:  color-mix(in srgb, var(--ohl-fg) 7%, var(--ohl-bg));
      --term-line: color-mix(in srgb, var(--ohl-brightblack) 25%, var(--ohl-bg));
      color-scheme: light;
    }
  }
  .term-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 8px 12px; background: var(--term-bar); color: var(--t-mut);
    font: 12px/1.4 var(--sans);
  }
  .term-bar strong { color: var(--t-fg); font-weight: 500; }
  .term-btns { display: flex; gap: 6px; flex-wrap: wrap; }
  .term-btn {
    background: transparent; color: var(--t-fg); border: 1px solid var(--term-line);
    border-radius: 6px; padding: 4px 9px; font: 500 12px/1 var(--sans); cursor: pointer;
  }
  .term-btn:hover { background: var(--term-line); }
  .term-scroll {
    margin: 0; min-height: 280px; max-height: 420px; overflow: auto;
    padding: 12px 14px 8px; font: inherit; color: inherit;
    white-space: pre-wrap; overflow-wrap: anywhere;
  }
  .term-prompt { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px 12px; border-top: 1px solid var(--term-line); }
  .term-ps { color: var(--t-ok); flex: none; line-height: 1.4; padding-top: 6px; }
  .term-prompt textarea, .term-prompt input[type=text] {
    flex: 1; min-width: 0; height: auto; max-height: 160px; padding: 6px 0; resize: none; overflow-y: auto;
    background: transparent; border: 0; border-radius: 0; outline: none;
    color: var(--t-fg); caret-color: var(--t-cursor); font: 13px/1.4 var(--mono-console);
  }
  .term-prompt textarea::placeholder, .term-prompt input[type=text]::placeholder { color: var(--t-mut); }
  .term-cmd { color: var(--t-mut); }
  .term-go { background: var(--t-go-bg); color: var(--t-go-fg); border: 0; border-radius: 6px; padding: 6px 12px; font: 600 12px/1 var(--sans); cursor: pointer; flex: none; margin-top: 2px; }
  .btn-claro { background: var(--claro-bg); color: var(--claro-fg); border: 1px solid var(--line); font-weight: 600; }
  .btn-claro:hover { background: var(--claro-hover); }
  .term-meta { display: flex; justify-content: space-between; gap: 12px; padding: 0 14px 10px; color: var(--t-mut); font: 11px/1.4 var(--sans); }
  .term-meta .ok { color: var(--t-ok); } .term-meta .bad { color: var(--t-bad); }
  .term-meta strong { font-weight: 600; }
  .term-dica { margin-bottom: 22px; }
  .anexo-arquivo {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden;
    clip: rect(0 0 0 0); white-space: nowrap; border: 0; opacity: 0;
  }
  .anexo-solta {
    display: block; border: 1.5px dashed var(--line); border-radius: 10px; padding: 22px 16px; margin-bottom: 14px;
    text-align: center; color: var(--mut); background: var(--panel); font-size: 13px; cursor: pointer;
  }
  .anexo-solta strong { display: block; color: var(--ink); font-weight: 600; margin-bottom: 4px; overflow-wrap: anywhere; }
  .anexo-solta small { font-size: 12px; }
  .anexo-solta:hover, .anexo-solta.arrastando { border-color: var(--ink); }
  .anexo-arquivo:focus-visible + .anexo-solta { outline: 2px solid var(--ink); outline-offset: 2px; }
  .anexo-destino { display: block; margin: 0 0 12px; }
  .anexo-destino label { display: block; width: auto; margin-bottom: 6px; }

  .switch { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
  .switch input { position: absolute; opacity: 0; width: 0; height: 0; }
  .switch .trilho {
    width: 40px; height: 22px; border-radius: 999px; background: var(--trilho);
    position: relative; transition: background .16s ease; flex: none;
  }
  .switch .trilho::after {
    content: ""; position: absolute; top: 3px; left: 3px;
    width: 16px; height: 16px; border-radius: 50%; background: var(--bolinha);
    transition: transform .16s ease; box-shadow: 0 1px 2px var(--sombra-bolinha);
  }
  .switch input:checked + .trilho { background: var(--trilho-on); }
  .switch input:checked + .trilho::after { transform: translateX(18px); }
  .switch input:focus-visible + .trilho { outline: 2px solid var(--btn); outline-offset: 2px; }
  .switch .rotulo { font-size: 13px; }

  .linha-acao { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 4px; }
  .nota-estado { color: var(--mut); font-size: 12px; margin: 10px 0 0; }
  .nota-estado strong { color: var(--ink); font-weight: 600; }

  dialog {
    border: 1px solid var(--line); border-radius: 12px; padding: 0;
    max-width: 460px; width: calc(100% - 32px); color: var(--ink); background: var(--bg);
    box-shadow: 0 12px 40px var(--sombra-modal);
  }
  dialog::backdrop { background: var(--fundo-modal); }
  .modal-corpo { padding: 20px 20px 4px; }
  .modal-corpo h3 { margin: 0 0 10px; font-size: 15px; }
  .modal-corpo p { margin: 0 0 12px; font-size: 13px; line-height: 1.5; color: var(--mut); }
  .modal-corpo p strong { color: var(--ink); }
  .modal-corpo code { background: var(--code-bg); padding: 1px 5px; border-radius: 4px; font-family: var(--mono); font-size: 12px; }
  .modal-opcao {
    display: flex; gap: 9px; align-items: flex-start; font-size: 13px;
    border: 1px solid var(--line); border-radius: 8px; padding: 11px 12px; margin-bottom: 12px; cursor: pointer;
  }
  .modal-opcao input { margin: 2px 0 0; flex: none; }
  .modal-opcao span { color: var(--mut); display: block; margin-top: 3px; font-size: 12px; }
  .modal-acoes { display: flex; justify-content: flex-end; gap: 8px; padding: 8px 20px 20px; }

  .aviso-rodape {
    position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%);
    width: min(720px, calc(100% - 32px)); display: flex; gap: 12px; align-items: flex-start;
    border: 1px solid var(--line); border-left: 3px solid var(--forte); border-radius: 8px;
    padding: 10px 13px; background: var(--bg); font-size: 13px; box-shadow: 0 6px 24px var(--sombra-aviso);
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
(function () {
  'use strict';

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

  function conferir() {
    mostrarAviso('Não deu para saber se a ação terminou. Recarregando a página para conferir…');
    location.replace(location.pathname + location.search);
  }

  function travar(form, botao) {
    document.querySelectorAll('#conteudo button:not([type=button]), #conteudo input[type=submit]').forEach(function (b) { b.disabled = true; });
    if (!botao || botao.tagName !== 'BUTTON') { botao = form.querySelector('button:not([type=button])'); }
    if (!botao) { return null; }

    var uac = botao.hasAttribute('data-uac') || form.querySelector('option[data-uac]:checked') !== null;
    var texto = uac ? 'aguardando o UAC… ' : 'executando… ';
    var t0 = Date.now();
    var alvo = botao.classList.contains('btn-icone') ? (botao.closest('td') || form).querySelector('[data-pendente]') : null;
    if (alvo) { alvo.hidden = false; botao.setAttribute('aria-busy', 'true'); } else { alvo = botao; }
    alvo.textContent = texto + '0 s';

    return setInterval(function () {
      alvo.textContent = texto + Math.round((Date.now() - t0) / 1000) + ' s';
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

    var token = novo.querySelector('input[name="' + CAMPO + '"]');
    if (token) {
      document.querySelectorAll('input[name="' + CAMPO + '"]').forEach(function (i) { i.value = token.value; });
    }

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

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (ev.defaultPrevented || !form.hasAttribute('data-sem-reload') || form.method.toLowerCase() !== 'post') { return; }
    ev.preventDefault();
    if (ocupado) { return; }
    ocupado = true;

    var botao = ev.submitter || (ultimoBotao && ultimoBotao.form === form && ultimoBotao.type === 'submit' ? ultimoBotao : null);
    var dados = new FormData(form);
    if (botao && botao.name) { dados.append(botao.name, botao.value); }

    document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });

    var foco = (botao && botao.id) || form.id || (form.closest('[id]:not(#conteudo)') || {}).id || '';
    aviso.hidden = true;
    var relogio = travar(form, botao);

    fetch(form.action, {
      method: 'POST',
      body: form.enctype === 'multipart/form-data' ? dados : new URLSearchParams(dados),
      credentials: 'same-origin',
      mode: 'same-origin',
      redirect: 'follow',
      headers: { 'X-PHPorto-Sem-Reload': '1' }
    }).then(function (r) {
      var html = (r.headers.get('Content-Type') || '').indexOf('text/html') === 0;
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
