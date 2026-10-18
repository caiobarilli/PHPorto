<?php

declare(strict_types=1);

namespace App\Win;

enum UacVerdict: string
{
    case Ok = 'ok';

    case Silencioso = 'silencioso';

    case Desligado = 'desligado';

    case Desconhecido = 'desconhecido';
}
