<?php

declare(strict_types=1);

namespace App\Autostart;

final readonly class TaskObservation
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(public string $presence, public array $fields)
    {
    }

    public static function unknown(): self
    {
        return new self(Task::UNKNOWN, []);
    }

    public static function parse(string $output): self
    {
        $presence = Task::UNKNOWN;
        $fields = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/\A([A-Z]{3,10})=(.*)\z/', trim($line), $m) !== 1) {
                continue;
            }

            if ($m[1] === 'TASK') {
                $presence = match ($m[2]) {
                    'presente' => Task::PRESENT,
                    'ausente' => Task::ABSENT,
                    default => Task::UNKNOWN,
                };

                continue;
            }

            $fields[$m[1]] = $m[2];
        }

        return new self($presence, $fields);
    }

    public function present(): bool
    {
        return $this->presence === Task::PRESENT;
    }

    public function absent(): bool
    {
        return $this->presence === Task::ABSENT;
    }

    public function state(): ?string
    {
        return $this->fields['STATE'] ?? null;
    }

    public function logon(): ?string
    {
        $logon = $this->fields['LOGON'] ?? '';

        return $logon === '' ? null : $logon;
    }

    public function matches(string $sid, string $program, string $script): bool
    {
        if (!$this->present()) {
            return false;
        }

        $actual = $this->fields['SID'] ?? '';

        return Identity::validSid($sid)
            && Identity::validSid($actual)
            && hash_equals(strtoupper($sid), strtoupper($actual))
            && strtolower($this->fields['EXEC'] ?? '') === strtolower($program)
            && ($this->fields['ARGS'] ?? '') === '"' . $script . '" serve'
            && strtolower($this->fields['LOGON'] ?? '') === 'password'
            && strtolower($this->fields['RUNLEVEL'] ?? '') === 'limited'
            && str_contains($this->fields['TRIGGERS'] ?? '', 'MSFT_TaskBootTrigger');
    }
}
