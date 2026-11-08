<?php

declare(strict_types=1);

namespace App\Cli;

final class Console
{
    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private $in, private $out, private $err)
    {
    }

    public static function standard(): self
    {
        return new self(STDIN, STDOUT, STDERR);
    }

    public static function quiet(): self
    {
        $stream = fopen('php://memory', 'w+');

        if ($stream === false) {
            throw new \RuntimeException('Sem memória para o console silencioso.');
        }

        return new self($stream, $stream, $stream);
    }

    public function out(string $text): void
    {
        fwrite($this->out, $text);
    }

    public function err(string $text): void
    {
        fwrite($this->err, $text);
    }

    public function readLine(): string
    {
        $line = fgets($this->in);

        return is_string($line) ? trim($line) : '';
    }
}
