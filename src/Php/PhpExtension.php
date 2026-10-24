<?php

declare(strict_types=1);

namespace App\Php;

enum PhpExtension: string
{
    public const ACTION = 'ext-ativar';
    public const FIELD  = 'extensao';

    case Zip       = 'zip';
    case Ffi       = 'ffi';
    case Openssl   = 'openssl';
    case Mbstring  = 'mbstring';
    case PdoSqlite = 'pdo_sqlite';
    case PdoMysql  = 'pdo_mysql';

    public function usage(): string
    {
        return match ($this) {
            self::Zip       => 'Instalar o Tiny C Compiler na seção C (ZipArchive abre o zip oficial).',
            self::Ffi       => 'Seção C: compila e roda C na memória pelo libtcc.',
            self::Openssl   => 'Downloads por https: Tiny C Compiler e lista de versões do PHP.',
            self::Mbstring  => 'Texto UTF-8 em todo o PHPorto: terminais, uploads, saídas do WSL e do PowerShell.',
            self::PdoSqlite => 'Banco SQLite (DB_DRIVER=sqlite), o padrão do PHPorto.',
            self::PdoMysql  => 'Banco MySQL, só com DB_DRIVER=mysql.',
        };
    }

    /**
     * @return array<string, bool>
     */
    public static function loadedMap(): array
    {
        $mapa = [];

        foreach (self::cases() as $ext) {
            $mapa[$ext->value] = extension_loaded($ext->value);
        }

        return $mapa;
    }
}
