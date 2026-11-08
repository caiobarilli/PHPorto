<?php

declare(strict_types=1);

namespace App\Network;

use Closure;

final class Machine
{
    /** @var Closure(): array<array-key, mixed> */
    private Closure $interfaces;

    /**
     * @param (Closure(): array<array-key, mixed>)|null $interfaces
     */
    public function __construct(?Closure $interfaces = null)
    {
        $this->interfaces = $interfaces ?? static function (): array {
            $found = net_get_interfaces();

            return is_array($found) ? $found : [];
        };
    }

    /**
     * @return list<string>
     */
    public function ipv4(): array
    {
        $ips = [];

        foreach (($this->interfaces)() as $interface) {
            $unicast = is_array($interface) ? ($interface['unicast'] ?? null) : null;

            if (!is_array($unicast)) {
                continue;
            }

            foreach ($unicast as $entry) {
                $address = is_array($entry) ? ($entry['address'] ?? null) : null;

                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $ips[] = $address;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    public function privateIpv4(): array
    {
        return array_values(array_filter($this->ipv4(), static fn (string $ip): bool => Ipv4::isPrivate($ip)));
    }

    public function owns(string $ip): bool
    {
        return in_array($ip, $this->ipv4(), true);
    }
}
