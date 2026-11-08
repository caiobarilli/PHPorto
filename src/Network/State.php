<?php

declare(strict_types=1);

namespace App\Network;

final readonly class State
{
    public const MAX_ALLOW = 16;

    /**
     * @param list<string> $allow
     */
    public function __construct(
        public bool $enabled,
        public ?string $bindIp,
        public int $port,
        public array $allow,
        public ?string $updatedAt,
    ) {
    }

    public static function closed(): self
    {
        return new self(false, null, 4001, [], null);
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)
            || !is_bool($data['enabled'] ?? null)
            || !is_string($data['bind_ip'] ?? null)
            || !Ipv4::isPrivate($data['bind_ip'])
            || !is_int($data['port'] ?? null)
            || $data['port'] < 1
            || $data['port'] > 65535
            || !is_array($data['allow'] ?? null)
            || !array_is_list($data['allow'])
            || $data['allow'] === []
            || count($data['allow']) > self::MAX_ALLOW
            || !is_string($data['updated_at'] ?? null)
            || $data['updated_at'] === '') {
            return null;
        }

        $allow = [];

        foreach ($data['allow'] as $rule) {
            if (!is_string($rule) || !Ipv4::isPrivateRule($rule)) {
                return null;
            }

            $allow[] = $rule;
        }

        return new self(
            $data['enabled'],
            $data['bind_ip'],
            $data['port'],
            $allow,
            $data['updated_at'],
        );
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public function accepts(array $server): bool
    {
        $listener = $server['SERVER_ADDR'] ?? null;
        $remote = $server['REMOTE_ADDR'] ?? null;

        return $this->enabled
            && is_string($listener)
            && $this->bindIp !== null
            && hash_equals($this->bindIp, $listener)
            && $this->allowsIp($remote);
    }

    public function allowsIp(mixed $ip): bool
    {
        return is_string($ip)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && Ipv4::allows($ip, $this->allow);
    }

    public function host(): string
    {
        return $this->bindIp . ':' . $this->port;
    }

    public function address(): string
    {
        return 'http://' . $this->host();
    }

    public function encoded(): string
    {
        return (string) json_encode([
            'enabled' => $this->enabled,
            'bind_ip' => $this->bindIp,
            'port' => $this->port,
            'allow' => $this->allow,
            'updated_at' => $this->updatedAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
