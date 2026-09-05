<?php

declare(strict_types=1);

namespace App\Wsl;

/**
 * Descobre se a distro configurada existe, sem executar nada dentro dela.
 *
 * A DIFERENÇA DE CUSTO É O MOTIVO DESTA CLASSE, e foi medida nesta máquina:
 *
 *   wsl.exe -l -q .................. 42-47 ms com a VM de pé, 35 ms desligada
 *   wsl.exe -d <distro> -- true .... 4543 ms com a VM FRIA, 88 ms depois
 *
 * A listagem NÃO acorda a VM — por isso responde igual, e até mais rápido, com
 * o WSL dormindo: ela lê distros REGISTRADAS, não distros rodando. A execução
 * real acorda, e acordar custa quatro segundos e meio.
 *
 * Então a verificação usa a listagem. Usar execução real cobraria 4,5 s da
 * home a cada primeiro acesso do dia para responder o que a listagem responde
 * em 35 ms.
 *
 * E o significado importa: isto responde "dá para usar", não "está rodando
 * agora". A VM dormir é normal, e ela sobe sozinha no primeiro comando —
 * desabilitar o botão porque ela dormiu seria mentir para o usuário.
 */
final class Distro
{
    /** Timeout curto: a listagem responde em dezenas de milissegundos. */
    private const LIST_TIMEOUT_S = 10;

    public function __construct(
        private readonly string $distro,
    ) {
    }

    public function name(): string
    {
        return $this->distro;
    }

    /**
     * O estado do WSL do ponto de vista desta ferramenta.
     *
     * Distingue "WSL não instalado" de "distro configurada não existe" porque
     * a tela precisa dizer QUAL dos dois é: são problemas com soluções
     * diferentes, e um erro genérico manda a pessoa procurar no lugar errado.
     */
    public function status(): DistroStatus
    {
        $list = $this->registered();

        if ($list === null) {
            return DistroStatus::WslMissing;
        }

        if ($this->distro === '') {
            return DistroStatus::DistroMissing;
        }

        foreach ($list as $name) {
            if (strcasecmp($name, $this->distro) === 0) {
                return DistroStatus::Ok;
            }
        }

        return DistroStatus::DistroMissing;
    }

    public function isAvailable(): bool
    {
        return $this->status() === DistroStatus::Ok;
    }

    /**
     * Distros registradas, ou null quando o wsl.exe não pôde ser executado
     * (não instalado, ou fora do PATH).
     *
     * @return list<string>|null
     */
    public function registered(): ?array
    {
        $descriptors = [
            0 => ['file', DIRECTORY_SEPARATOR !== '/' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        // Sem isto o wsl.exe escreve a listagem em UTF-16LE.
        $env['WSL_UTF8'] = '1';

        $pipes = [];
        // bypass_shell pelo mesmo motivo do Runner: a forma de array aspearia
        // o "-l" e o wsl.exe deixaria de reconhecer o próprio flag.
        $proc = @proc_open('wsl.exe -l -q', $descriptors, $pipes, null, $env, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            return null;
        }

        $stdout  = '';
        $started = microtime(true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $chunk = fread($pipes[1], 8192);
            if (is_string($chunk)) {
                $stdout .= $chunk;
            }

            $status = proc_get_status($proc);
            if ($status['running'] === false) {
                $rest = stream_get_contents($pipes[1]);
                if (is_string($rest)) {
                    $stdout .= $rest;
                }
                break;
            }

            if ((microtime(true) - $started) >= self::LIST_TIMEOUT_S) {
                @proc_terminate($proc, 9);
                break;
            }

            usleep(10000);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($proc);

        if ($exit !== 0 && trim($stdout) === '') {
            return null;
        }

        return self::parse($stdout);
    }

    /**
     * A listagem vem com um nome por linha. Mesmo com WSL_UTF8=1 podem sobrar
     * bytes nulos e CR, então limpa antes de comparar — nome com resíduo nunca
     * casaria com o que está no .env.
     *
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $raw   = Runner::toUtf8($raw);
        $raw   = str_replace("\0", '', $raw);
        $names = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }

        return $names;
    }
}
