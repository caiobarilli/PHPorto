<?php

declare(strict_types=1);

namespace App\Http;

use App\Php\IniForm;
use App\Php\PhpBuild;
use App\Php\PhpProbe;
use App\Php\PhpUpdate;
use Closure;

final readonly class PhpView
{
    public function __construct(
        public ?PhpBuild $active,
        public ?string $pathBinary,
        public bool $pathDiverges,
        public ?PhpBuild $pathBuild,
        public ?IniForm $ini = null,
        public ?string $notice = null,
        public string $csrfToken = '',
        public string $csrfField = '',
        public ?PhpUpdate $update = null,
    ) {
    }

    /**
     * @param Closure(string): ?PhpBuild $medir
     */
    public static function make(?PhpBuild $active, ?string $pathBinary, bool $ignorarCaixa, Closure $medir): self
    {
        if ($active === null || $pathBinary === null || PhpProbe::sameFile($pathBinary, $active->binary, $ignorarCaixa)) {
            return new self($active, $pathBinary, false, null);
        }

        return new self($active, $pathBinary, true, $medir($pathBinary));
    }

    public function with(?IniForm $ini, ?string $notice, string $csrfToken, string $csrfField, ?PhpUpdate $update = null): self
    {
        return new self($this->active, $this->pathBinary, $this->pathDiverges, $this->pathBuild, $ini, $notice, $csrfToken, $csrfField, $update);
    }
}
