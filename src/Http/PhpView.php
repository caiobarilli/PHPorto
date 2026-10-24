<?php

declare(strict_types=1);

namespace App\Http;

use App\Php\C\CPanel;
use App\Php\IniForm;
use App\Php\PhpBuild;
use App\Php\PhpProbe;
use App\Php\PhpUpdate;
use Closure;

final readonly class PhpView
{
    /**
     * @param array<string, bool> $extensions
     */
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
        public ?CPanel $c = null,
        public array $extensions = [],
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
        return new self($this->active, $this->pathBinary, $this->pathDiverges, $this->pathBuild, $ini, $notice, $csrfToken, $csrfField, $update, $this->c, $this->extensions);
    }

    public function withC(CPanel $c): self
    {
        return new self($this->active, $this->pathBinary, $this->pathDiverges, $this->pathBuild, $this->ini, $this->notice, $this->csrfToken, $this->csrfField, $this->update, $c, $this->extensions);
    }

    /**
     * @param array<string, bool> $extensions
     */
    public function withExtensions(array $extensions): self
    {
        return new self($this->active, $this->pathBinary, $this->pathDiverges, $this->pathBuild, $this->ini, $this->notice, $this->csrfToken, $this->csrfField, $this->update, $this->c, $extensions);
    }
}
