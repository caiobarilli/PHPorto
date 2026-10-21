<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\Execution;

final class SshKeyOutput
{
    private const PREFIXO = 'ssh-keys ';

    /**
     * @param list<Execution> $rows
     * @return array{key: string, fingerprint: string}|null
     */
    public static function generated(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (!str_starts_with($row->command, self::PREFIXO)) {
                continue;
            }

            $achada = self::publicKey($row->output);

            if ($achada !== null) {
                return $achada;
            }
        }

        return null;
    }

    /**
     * @return array{key: string, fingerprint: string}|null
     */
    public static function publicKey(string $saida): ?array
    {
        if (preg_match('/^\[ OK \] Public key: (\S+ \S+(?: \S+)?)\r?$/m', $saida, $m) !== 1) {
            return null;
        }

        $digital = WinAction::sshFingerprint($m[1]);

        return $digital === null ? null : ['key' => $m[1], 'fingerprint' => $digital];
    }

    /**
     * @param list<Execution> $rows
     * @return list<array{kind: string, fingerprint: string, comment: string}>|null
     */
    public static function authorized(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (preg_match('/\Assh-keys -SubAction (?:list|authorize|remove)\b/', $row->command) !== 1) {
                continue;
            }

            $lista = self::authorizedFrom($row->output);

            if ($lista !== null) {
                return $lista;
            }
        }

        return null;
    }

    /**
     * @return list<array{kind: string, fingerprint: string, comment: string}>|null
     */
    public static function authorizedFrom(string $saida): ?array
    {
        if (str_contains($saida, 'No authorized key on this Windows.')) {
            return [];
        }

        $padrao = '/^\[ INFO \] Authorized key: (ed25519|rsa|ecdsa) (SHA256:[A-Za-z0-9+\/]{43})(?: ([A-Za-z0-9@._+-]{1,100}))?\r?$/m';

        if (preg_match_all($padrao, $saida, $achados, PREG_SET_ORDER) === 0) {
            return null;
        }

        $lista = [];
        foreach ($achados as $a) {
            $lista[] = ['kind' => $a[1], 'fingerprint' => $a[2], 'comment' => $a[3] ?? ''];
        }

        return $lista;
    }

    /**
     * @param list<Execution> $rows
     * @return list<array{user: string, distro: string, fingerprint: string}>
     */
    public static function wslUsers(array $rows): array
    {
        $padrao = '/^\[ OK \] WSL user: ([a-z][a-z0-9-]{0,31}) ([A-Za-z0-9][A-Za-z0-9._-]{0,63}) (SHA256:[A-Za-z0-9+\/]{43})\r?$/m';
        $lista  = [];

        foreach ($rows as $row) {
            if (!str_starts_with($row->command, self::PREFIXO) || preg_match($padrao, $row->output, $m) !== 1) {
                continue;
            }

            $lista[] = ['user' => $m[1], 'distro' => $m[2], 'fingerprint' => $m[3]];
        }

        return $lista;
    }
}
