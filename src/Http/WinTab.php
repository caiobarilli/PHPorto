<?php

declare(strict_types=1);

namespace App\Http;

/**
 * As abas da /win, agrupadas pelo que a ação faz na máquina.
 *
 * A ordem dos casos é a ordem do menu. A aba vive na URL, /win?aba=<valor>,
 * para o 303 de cada ação devolver a pessoa à aba de onde ela veio.
 */
enum WinTab: string
{
    /** Tweaks e Debloat: onde se mexe no Windows em si. Abre por padrão. */
    case Sistema = 'sistema';

    /** DNS e captura de rede. */
    case Rede = 'rede';

    /** Install. */
    case Aplicativos = 'aplicativos';

    /** Exporter, GPU, Optimize, GDID e Performance: o que liga, desliga e tem volta. */
    case Servicos = 'servicos';

    /** RDP e Sunshine: chegar à máquina de longe. */
    case AcessoRemoto = 'acesso-remoto';

    /** O rótulo no menu. */
    public function label(): string
    {
        return match ($this) {
            self::Sistema      => 'Sistema',
            self::Rede         => 'Rede',
            self::Aplicativos  => 'Aplicativos',
            self::Servicos     => 'Serviços',
            self::AcessoRemoto => 'Acesso Remoto',
        };
    }

    /** O endereço da /win com esta aba aberta. */
    public function url(): string
    {
        return '/win?aba=' . $this->value;
    }

    /**
     * A aba pedida na URL.
     *
     * Recebe o valor cru do parâmetro aba. Devolve a aba, ou Sistema quando
     * ele falta ou não é uma aba.
     */
    public static function fromQuery(mixed $valor): self
    {
        return is_string($valor) ? (self::tryFrom($valor) ?? self::Sistema) : self::Sistema;
    }
}
