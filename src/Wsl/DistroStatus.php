<?php

declare(strict_types=1);

namespace App\Wsl;

/**
 * Por que o executor está ou não disponível.
 *
 * São três estados e não um booleano porque a tela precisa dizer QUAL é o
 * problema: "instale o WSL" e "a distro do .env não existe" têm soluções
 * diferentes, e uma mensagem genérica manda a pessoa procurar no lugar errado.
 *
 * Não existe estado "VM dormindo": a listagem não acorda a VM e não sabe se
 * ela está de pé — e não precisa saber. A VM sobe sozinha no primeiro comando.
 */
enum DistroStatus
{
    /** wsl.exe respondeu e a distro configurada está na listagem. */
    case Ok;

    /** wsl.exe não pôde ser executado: WSL não instalado, ou fora do PATH. */
    case WslMissing;

    /** wsl.exe respondeu, mas a distro configurada não está registrada. */
    case DistroMissing;


    /** Se a ferramenta pode ser usada. Ver a nota sobre "VM dormindo" acima. */
    public function isUsable(): bool
    {
        return $this === self::Ok;
    }

    /** Motivo em uma linha, para a tela. */
    public function reason(string $distro): string
    {
        return match ($this) {
            self::Ok            => '',
            self::WslMissing    => 'WSL não encontrado nesta máquina (wsl.exe não respondeu).',
            self::DistroMissing => $distro === ''
                ? 'PHPORTO_DISTRO está vazio no .env.'
                : sprintf('A distro "%s" do .env não aparece em "wsl -l -q".', $distro),
        };
    }
}
