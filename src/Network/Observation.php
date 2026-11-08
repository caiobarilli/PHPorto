<?php

declare(strict_types=1);

namespace App\Network;

final readonly class Observation
{
    public const RULE_OK = 'ok';

    public const RULE_MISSING = 'ausente';

    public const RULE_DIVERGENT = 'divergente';

    public const RULE_UNKNOWN = 'desconhecida';

    /**
     * @param list<string> $ruleLines
     */
    public function __construct(
        public ?string $profile,
        public ?int $ruleCount,
        public array $ruleLines,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null, []);
    }

    public static function parse(string $output): self
    {
        $profile = null;
        $count = null;
        $lines = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'PROFILE=')) {
                $value = substr($line, 8);
                $profile = preg_match('/\A[A-Za-z]{1,40}\z/', $value) === 1 && $value !== 'erro' ? $value : null;
            } elseif (str_starts_with($line, 'RULES=')) {
                $value = substr($line, 6);
                $count = ctype_digit($value) ? (int) $value : null;
            } elseif (str_starts_with($line, 'RULE=')) {
                $lines[] = substr($line, 5);
            }
        }

        return new self($profile, $count, $lines);
    }

    public function profileIsPrivate(): bool
    {
        return $this->profile === 'Private';
    }

    public function rule(State $state): string
    {
        if ($this->ruleCount === null) {
            return self::RULE_UNKNOWN;
        }

        if ($this->ruleCount === 0) {
            return self::RULE_MISSING;
        }

        if ($this->ruleCount !== 1 || count($this->ruleLines) !== 1) {
            return self::RULE_DIVERGENT;
        }

        $parts = explode('|', $this->ruleLines[0]);

        if (count($parts) !== 8) {
            return self::RULE_DIVERGENT;
        }

        [$enabled, $direction, $action, $profile, $protocol, $port, $local, $remote] = $parts;

        if (strtolower($enabled) !== 'true'
            || $direction !== 'Inbound'
            || $action !== 'Allow'
            || $profile !== 'Private'
            || $protocol !== 'TCP'
            || $port !== (string) $state->port
            || $local !== $state->bindIp
            || !self::sameRemote($remote, $state->allow)) {
            return self::RULE_DIVERGENT;
        }

        return self::RULE_OK;
    }

    /**
     * @param list<string> $allow
     */
    private static function sameRemote(string $remote, array $allow): bool
    {
        $found = self::ranges(explode(',', $remote));
        $wanted = self::ranges($allow);

        return $found !== null && $wanted !== null && $found === $wanted;
    }

    /**
     * @param list<string> $rules
     * @return list<string>|null
     */
    private static function ranges(array $rules): ?array
    {
        $ranges = [];

        foreach ($rules as $rule) {
            $bounds = Ipv4::bounds(trim($rule));

            if ($bounds === null) {
                return null;
            }

            $ranges[] = $bounds[0] . '-' . $bounds[1];
        }

        $ranges = array_values(array_unique($ranges));
        sort($ranges);

        return $ranges;
    }
}
