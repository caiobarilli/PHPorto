<?php

declare(strict_types=1);

namespace App\Win;

final readonly class HypervVm
{
    /**
     * @param list<string> $ip
     */
    public function __construct(
        public string $name,
        public string $state,
        public bool $running,
        public int $memoryBytes,
        public int $uptimeSeconds,
        public array $ip,
        public string $id = '',
    ) {
    }

    /**
     * @return list<string>
     */
    public function usefulIps(): array
    {
        $v4 = [];
        $v6 = [];

        foreach ($this->ip as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $v4[] = $ip;
            } elseif (stripos($ip, 'fe80:') !== 0) {
                $v6[] = $ip;
            }
        }

        return array_merge($v4, $v6);
    }
}
