<?php

declare(strict_types=1);

namespace App\Win;

/**
 * O que a política do UAC desta máquina permite dizer sobre um prompt.
 *
 * QUATRO RESPOSTAS, e só a primeira libera ação sensível. As outras três
 * recusam, cada uma com a correção dela — ver UacPolicy::blockingReason().
 */
enum UacVerdict: string
{
    /** EnableLUA = 1 e ConsentPromptBehaviorAdmin entre 1 e 5: há prompt. */
    case Ok = 'ok';

    /** ConsentPromptBehaviorAdmin = 0: o Windows eleva sem perguntar. */
    case Silencioso = 'silencioso';

    /** EnableLUA = 0: sem token dividido, todo processo já é Administrador. */
    case Desligado = 'desligado';

    /** reg.exe falhou, a saída não é a esperada, ou não é Windows. */
    case Desconhecido = 'desconhecido';
}
