<?php

declare(strict_types=1);

namespace App\Php;

use DateTimeZone;
use InvalidArgumentException;

enum IniKey: string
{
    case UploadProgress = 'session.upload_progress.enabled';
    case UploadMaxFilesize = 'upload_max_filesize';
    case PostMaxSize = 'post_max_size';
    case MemoryLimit = 'memory_limit';
    case MaxExecutionTime = 'max_execution_time';
    case Timezone = 'date.timezone';
    case DisplayErrors = 'display_errors';

    public const MIB = 1024 * 1024;

    public const GIB = 1024 * 1024 * 1024;

    public const MAX_VALUE_BYTES = 64;

    private const SIZE_PATTERN = '/\A([1-9][0-9]{0,5})([KMG])\z/';

    private const INT_PATTERN = '/\A(?:0|[1-9][0-9]{0,3})\z/';

    public function default(): string
    {
        return match ($this) {
            self::UploadProgress    => 'On',
            self::UploadMaxFilesize => '2M',
            self::PostMaxSize       => '8M',
            self::MemoryLimit       => '128M',
            self::MaxExecutionTime  => '30',
            self::Timezone          => 'UTC',
            self::DisplayErrors     => 'On',
        };
    }

    public function isSwitch(): bool
    {
        return $this === self::UploadProgress || $this === self::DisplayErrors;
    }

    public function hint(): string
    {
        return match ($this) {
            self::UploadProgress    => 'On ou Off.',
            self::UploadMaxFilesize => 'De 1M a 16G, com K, M ou G.',
            self::PostMaxSize       => 'De 1M a 16G, com K, M ou G. Não pode ser menor que upload_max_filesize.',
            self::MemoryLimit       => 'De 32M a 16G, com K, M ou G, ou -1 para sem limite.',
            self::MaxExecutionTime  => 'Segundos, de 0 a 3600. 0 é sem limite.',
            self::Timezone          => 'Um fuso da lista do PHP, como America/Sao_Paulo.',
            self::DisplayErrors     => 'On ou Off.',
        };
    }

    public function validate(string $valor): string
    {
        if (strlen($valor) > self::MAX_VALUE_BYTES) {
            throw new InvalidArgumentException($this->value . ': valor longo demais.');
        }

        return match ($this) {
            self::UploadProgress, self::DisplayErrors => $this->onOff($valor),
            self::UploadMaxFilesize, self::PostMaxSize => $this->size($valor, self::MIB, 16 * self::GIB),
            self::MemoryLimit       => $valor === '-1' ? '-1' : $this->size($valor, 32 * self::MIB, 16 * self::GIB),
            self::MaxExecutionTime  => $this->seconds($valor),
            self::Timezone          => $this->timezone($valor),
        };
    }

    public function normalize(string $valor): string
    {
        $valor = trim($valor);

        if ($this->isSwitch()) {
            $flag = PhpProbe::flag($valor);

            return $flag === true ? 'On' : 'Off';
        }

        if ($this === self::Timezone) {
            return $valor;
        }

        return strtoupper($valor);
    }

    public function format(string $valor): string
    {
        return $this === self::Timezone ? '"' . $valor . '"' : $valor;
    }

    public static function bytes(string $valor): ?int
    {
        if (preg_match('/\A([0-9]{1,6})([KMG]?)\z/i', trim($valor), $m) !== 1) {
            return null;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K'     => 1024,
            'M'     => self::MIB,
            'G'     => self::GIB,
            default => 1,
        };
    }

    private function onOff(string $valor): string
    {
        if ($valor !== 'On' && $valor !== 'Off') {
            throw new InvalidArgumentException($this->value . ': só On ou Off.');
        }

        return $valor;
    }

    private function size(string $valor, int $minimo, int $maximo): string
    {
        $valor = strtoupper($valor);

        if (preg_match(self::SIZE_PATTERN, $valor) !== 1) {
            throw new InvalidArgumentException($this->value . ': use um número com K, M ou G, como 64M.');
        }

        $bytes = self::bytes($valor);

        if ($bytes === null || $bytes < $minimo || $bytes > $maximo) {
            throw new InvalidArgumentException($this->value . ': ' . $this->hint());
        }

        return $valor;
    }

    private function seconds(string $valor): string
    {
        if (preg_match(self::INT_PATTERN, $valor) !== 1 || (int) $valor > 3600) {
            throw new InvalidArgumentException($this->value . ': ' . $this->hint());
        }

        return $valor;
    }

    private function timezone(string $valor): string
    {
        if (!in_array($valor, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException($this->value . ': fuso fora da lista do PHP.');
        }

        return $valor;
    }
}
