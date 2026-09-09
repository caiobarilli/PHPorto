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
</style>
</head>
<body>
<?= $view->content ?>
</body>
</html>
