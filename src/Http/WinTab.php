<?php

declare(strict_types=1);

namespace App\Http;

enum WinTab: string
{
    case Sistema = 'sistema';

    case Rede = 'rede';

    case Aplicativos = 'aplicativos';

    case Servicos = 'servicos';

    case AcessoRemoto = 'acesso-remoto';

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

    public function url(): string
    {
        return '/win?aba=' . $this->value;
    }

    public static function fromQuery(mixed $valor): self
    {
        return is_string($valor) ? (self::tryFrom($valor) ?? self::Sistema) : self::Sistema;
    }
}
