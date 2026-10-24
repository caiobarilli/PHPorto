<?php

declare(strict_types=1);

namespace App\Win;

use InvalidArgumentException;

final class SshKeyFolder
{
    public const NAME_PATTERN = '\A[A-Za-z0-9_-]{1,64}\z';

    public const DEFAULT_NAME = 'id_ed25519';

    public const RESERVED = ['con', 'prn', 'aux', 'nul', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];

    public static function name(string $bruto): string
    {
        $nome = trim($bruto);

        if (preg_match('/' . self::NAME_PATTERN . '/', $nome) !== 1) {
            throw new InvalidArgumentException('Nome da chave: só letras, números, - e _, de 1 a 64.');
        }

        if (in_array(strtolower($nome), self::RESERVED, true)) {
            throw new InvalidArgumentException(sprintf('%s é nome reservado do Windows. Escolha outro.', $nome));
        }

        return $nome;
    }

    public static function folder(string|false $perfil): ?string
    {
        if (!is_string($perfil) || $perfil === '' || preg_match('/[\x00-\x1F]/', $perfil) === 1) {
            return null;
        }

        return rtrim($perfil, '\\/') . DIRECTORY_SEPARATOR . '.ssh';
    }

    /**
     * @return list<string>
     */
    public static function existing(string $pasta, string $nome): array
    {
        $achados = [];

        foreach ([$nome, $nome . '.pub'] as $arquivo) {
            if (file_exists($pasta . DIRECTORY_SEPARATOR . $arquivo)) {
                $achados[] = $arquivo;
            }
        }

        return $achados;
    }

    public static function refusal(string $pasta, string $nome): ?string
    {
        $achados = self::existing($pasta, $nome);

        if ($achados === []) {
            return null;
        }

        return sprintf(
            'Já existe %s em %s. Nada foi gerado e nada foi sobrescrito: escolha outro nome.',
            implode(' e ', $achados),
            $pasta
        );
    }
}
