<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Execution;
use App\Win\ElevationState;

final readonly class WinView
{
    /**
     * @param list<Execution> $rows
     * @param Execution|null $result
     * @param string|null $blocked
     * @param ElevationState $win
     * @param int $timeout
     * @param string $tz
     * @param int $maxOutputBytes
     * @param int $maxParamBytes
     * @param list<string> $debloatPackages
     * @param string|null $debloatProblem
     * @param list<string> $debloatChecked
     * @param list<array{key: string, content: string, description: string, category: string, caution: bool, explorer: bool}> $tweaks
     * @param array<string, list<string>> $tweakPresets
     * @param list<string> $tweaksChecked
     * @param string $tweaksMatch
     * @param string|null $tweaksProblem
     * @param list<string> $tweaksApplied
     * @param list<string> $appliedActions
     * @param list<string> $pendingRebootActions
     * @param list<array{key: string, label: string, text: string}> $dnsProviders
     * @param array<string, string> $dnsChosen
     * @param string|null $dnsProblem
     * @param WinTab $tab
     * @param string|null $sensitiveBlocked
     * @param list<string> $wslDistros
     */
    public function __construct(
        public array $rows,
        public ?Execution $result,
        public ?string $blocked,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
        public ElevationState $win,
        public int $timeout,
        public string $tz,
        public int $maxOutputBytes,
        public int $maxParamBytes,
        public array $debloatPackages,
        public ?string $debloatProblem,
        public array $debloatChecked,
        public array $tweaks,
        public array $tweakPresets,
        public array $tweaksChecked,
        public string $tweaksMatch,
        public ?string $tweaksProblem,
        public array $tweaksApplied,
        public array $appliedActions,
        public array $pendingRebootActions,
        public array $dnsProviders,
        public array $dnsChosen,
        public ?string $dnsProblem,
        public WinTab $tab = WinTab::Sistema,
        public ?string $sensitiveBlocked = null,
        public array $wslDistros = [],
    ) {
    }
}
