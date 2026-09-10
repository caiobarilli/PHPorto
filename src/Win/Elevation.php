<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;
use Throwable;

/**
 * Liga e desliga o PowerShell elevado, e diz se ele está de pé.
 *
 * POR QUE O ESTADO NÃO VAI PARA O flags.json: o flags.json existe para
 * SOBREVIVER a reinício, e aqui se quer o contrário. O marcador guarda o PID
 * do próprio php -S junto com o do worker, e o servidor embutido é um
 * processo só — medido no Windows: nove requisições, cinco em série e quatro
 * simultâneas, todas com o mesmo PID (24584), e PHP_CLI_SERVER_WORKERS não
 * existe aqui. Então o PID é constante enquanto o servidor vive e muda quando
 * ele reinicia, o que invalida o marcador sozinho, sem ninguém precisar
 * limpar nada — a mesma ideia do JSON ilegível virando array vazio.
 *
 * POR QUE DESLIGAR NÃO MATA NINGUÉM: o php -S roda em integridade Média e o
 * worker em Alta. Medido: taskkill /F /T do PHP contra o worker devolve
 * rc=128 e "Acesso negado", e o processo segue vivo. Escrever para baixo
 * passa; matar para cima não. Então desligar é uma ORDEM em arquivo, que o
 * worker lê no próprio laço e obedece — 585 ms medidos entre a ordem e a
 * saída dele.
 *
 * POR QUE HÁ HEARTBEAT: sem ele, "o worker está vivo?" só se responderia
 * gastando ~200 ms de powershell.exe por carregamento de página, ou mentindo
 * até a primeira ação falhar. O worker reescreve um arquivo a cada 500 ms e
 * um filemtime() responde. É o mesmo critério do `wsl -l -q`: a checagem
 * barata que responde a pergunta real.
 */
final class Elevation
{
    /**
     * Quanto o POST espera pela prova de elevação.
     *
     * Medido nesta máquina: 921 ms da chamada à prova, sem nenhuma interação
     * — porque aqui o ConsentPromptBehaviorAdmin é 0 e a elevação é
     * silenciosa. Num Windows de fábrica aparece prompt na área segura e o
     * tempo passa a ser humano: ler e clicar leva de 5 a 20 s. 30 s cobre
     * isso com folga, e é o mesmo número do piso de timeout do WSL, o que dá
     * à tela um vocabulário só.
     */
    public const PROOF_TIMEOUT_S = 30;

    /**
     * Quanto o POST de desligar espera pela confirmação de que o worker saiu.
     *
     * Medido: 585 ms entre a ordem e a saída. 5 s é folga generosa; passado
     * isso a tela diz que a ordem foi deixada e que o worker sai no próximo
     * tique, em vez de pendurar a requisição por um processo que ela não pode
     * matar de qualquer forma.
     */
    public const SHUTDOWN_WAIT_S = 5;

    /**
     * Idade máxima do heartbeat para o worker contar como vivo.
     *
     * O tique do worker é 500 ms. Dez segundos é vinte tiques de tolerância —
     * folgado para um disco ocupado, e curto o bastante para a tela não
     * mentir por muito tempo depois de o worker morrer.
     */
    public const HEARTBEAT_STALE_S = 10;

    /**
     * Margem dada ao limite de execução do PHP, acima do nosso próprio teto.
     *
     * MEDIDO, e foi surpresa: o `php -S` roda em SAPI cli-server com
     * max_execution_time = 30 (o do php.ini), e NÃO com 0 como o CLI. Uma
     * requisição de 35 s morre aos 30 com fatal — e morrer assim é o pior
     * resultado possível aqui, porque a página quebra ANTES de o código
     * conseguir dizer que a permissão não foi concedida e oferecer o "tentar
     * novamente". Verificado: com PROOF_TIMEOUT_S em 30, o POST voltava 200
     * com fatal, aviso vazio e nenhum botão de retentativa.
     *
     * A guarda do PHP não é removida, só levantada ACIMA da nossa: quem corta
     * primeiro passa a ser o nosso timeout, que sabe explicar o que houve. Um
     * laço com defeito ainda morre — só mais tarde, e com fatal, que é o
     * comportamento certo para um bug nosso.
     *
     * Não é set_time_limit(0) de propósito: o php -S é um processo só e atende
     * em série, então uma requisição que nunca termina congela a ferramenta
     * inteira, sem volta a não ser matar o processo.
     */
    public const TIME_LIMIT_MARGIN_S = 15;

    /**
     * Nomes do canal.
     *
     * O worker.ps1 carrega os MESMOS nomes, e essa duplicação é inerente: um
     * script PowerShell não compartilha constante com PHP. Ao renomear
     * qualquer um deles, o worker precisa ser atualizado junto — não há
     * checagem automática que acuse.
     */
    public const F_HEARTBEAT = 'win-heartbeat';

    public const F_JOB = 'win-job.json';

    public const F_ORDEM_DESLIGAR = 'win-ordem-desligar';

    public const F_ORDEM_CANCELAR = 'win-ordem-cancelar';

    public const F_MARCADOR = 'win-elevation.json';

    /**
     * Os dois .ps1 que o motor precisa em src/Win.
     *
     * Estão aqui, e não espalhados, porque as duas coisas que o Elevation faz
     * com eles precisam concordar: o configProblem() confere que existem, e o
     * launcherBody() manda o Windows rodar o worker. Se a checagem olhasse um
     * caminho e o lançamento outro, a tela diria que está tudo bem antes de
     * uma elevação que não sobe.
     */
    public const SCRIPT_WORKER = 'worker.ps1';

    public const SCRIPT_BOOTSTRAP = 'bootstrap.ps1';

    /**
     * @param string $winDir pasta src/Win, onde vivem o worker e o bootstrap
     *
     * O $winDir vem por parâmetro, e não de __DIR__, pelo mesmo motivo do
     * $filesDir e do $storageDir: quem monta a aplicação decide onde as coisas
     * estão, e um teste consegue apontar para uma árvore incompleta para ver a
     * tela explicar o problema. Não é configuração — não sai do .env, e não há
     * o que ajustar numa instalação.
     */
    public function __construct(
        private readonly string $filesDir,
        private readonly string $storageDir,
        private readonly string $winDir,
    ) {
    }

    /**
     * O estado agora.
     *
     * NÃO APAGA marcador inválido, e isso é escolha: leitura sem efeito
     * colateral. Marcador de outra execução do servidor já lê como
     * desligado, então deixá-lo no disco não engana ninguém — e o enable()
     * sobrescreve na próxima vez que alguém ligar.
     */
    public function state(): ElevationState
    {
        $blocked = $this->configProblem();

        if ($blocked !== null) {
            return new ElevationState(on: false, blocked: $blocked);
        }

        $marcador = $this->readMarker();

        if ($marcador === null) {
            return new ElevationState(on: false);
        }

        if ($marcador['php_pid'] !== getmypid()) {
            // O servidor reiniciou desde que isto foi gravado. É o mecanismo
            // funcionando, não uma falha.
            return new ElevationState(on: false);
        }

        $idade = $this->heartbeatAge();

        if ($idade === null || $idade > self::HEARTBEAT_STALE_S) {
            return new ElevationState(
                on: false,
                detail: $idade === null
                    ? 'O PowerShell elevado não deixou sinal de vida — ele pode ter sido encerrado por fora.'
                    : sprintf('O PowerShell elevado não responde há %d s.', $idade),
            );
        }

        return new ElevationState(
            on: true,
            psPid: $marcador['ps_pid'],
            provedAt: $marcador['provado_em'],
        );
    }

    /**
     * Abre o PowerShell elevado e espera a prova.
     *
     * Devolve null quando ligou, ou a frase que vai para a tela quando não.
     *
     * UMA TENTATIVA, e não três: três tentativas somam dentro de uma
     * requisição HTTP, e num Windows com prompt seriam três prompts em fila
     * para quem cancelou o primeiro de propósito. Quem decide tentar de novo
     * é quem viu o prompt — o botão "tentar novamente" na tela é a
     * retentativa, e ela vem com token novo, o que preserva o "um token, uma
     * execução".
     */
    public function enable(): ?string
    {
        $blocked = $this->configProblem();

        if ($blocked !== null) {
            return $blocked;
        }

        $nonce = bin2hex(random_bytes(8));
        $prova = $this->path($this->filesDir, 'win-prova-' . $nonce . '.txt');

        // Sobras de uma tentativa anterior confundiriam a espera abaixo.
        $this->cleanupChannel();

        $launcher = $this->path($this->filesDir, 'win-launcher.ps1');

        try {
            PsScriptBuilder::write($launcher, $this->launcherBody($nonce));
        } catch (Throwable $e) {
            return 'Não foi possível preparar o lançador: ' . $e->getMessage();
        }

        $runner = new PsRunner($this->filesDir);

        try {
            // O lançador só dispara o Start-Process e sai; o timeout curto
            // aqui é para ele, não para a elevação. A espera pela elevação é
            // a sondagem da prova, logo abaixo.
            $r = $runner->run($launcher, self::PROOF_TIMEOUT_S);
        } catch (RuntimeException $e) {
            return 'Não foi possível chamar o powershell.exe: ' . $e->getMessage();
        } finally {
            @unlink($launcher);
        }

        // Antes de esperar: ver TIME_LIMIT_MARGIN_S. Sem isto o limite de 30 s
        // do cli-server mata a requisição justamente no instante em que ela
        // teria a resposta a dar.
        set_time_limit(self::PROOF_TIMEOUT_S + self::TIME_LIMIT_MARGIN_S);

        $limite = microtime(true) + self::PROOF_TIMEOUT_S;
        $bruto  = null;

        while (microtime(true) < $limite) {
            clearstatcache(true, $prova);

            if (is_file($prova)) {
                $lido = @file_get_contents($prova);

                if (is_string($lido) && str_contains($lido, 'PID=')) {
                    $bruto = $lido;
                    break;
                }
            }

            usleep(100000);
        }

        if ($bruto === null) {
            @unlink($prova);

            // A saída do lançador é o que diz se o UAC foi recusado: um
            // Start-Process -Verb RunAs cancelado devolve erro por ali.
            $extra = trim($r->output);

            return 'A permissão de Administrador não foi concedida'
                . sprintf(' (nada respondeu em %d s).', self::PROOF_TIMEOUT_S)
                . ($extra === '' ? '' : ' O PowerShell disse: ' . $extra);
        }

        preg_match('/PID=(\d+)/', $bruto, $m);
        $psPid = (int) ($m[1] ?? 0);

        if ($psPid <= 0) {
            @unlink($prova);

            return 'A prova de elevação voltou sem PID — o worker respondeu algo inesperado.';
        }

        if (!$this->writeMarker($psPid, $nonce)) {
            // O worker está de pé e o PHP não consegue gravar o marcador.
            // Sem marcador ninguém acha esse processo depois, então a ordem
            // de desligar vai agora, enquanto o nome do arquivo é conhecido.
            $this->order(self::F_ORDEM_DESLIGAR);

            return 'Não foi possível gravar ' . $this->markerPath()
                . '. Verifique a permissão de escrita da pasta storage/. O PowerShell elevado foi encerrado.';
        }

        return null;
    }

    /**
     * Manda o worker sair e devolve a frase para a tela.
     *
     * O marcador é apagado ANTES da espera: a tela precisa mostrar desligado
     * mesmo que o worker demore a obedecer, porque o estado que a pessoa
     * pediu é esse.
     */
    public function disable(): string
    {
        $marcador = $this->readMarker();

        // is_file() antes: desligar sem nada ligado é caminho normal, e o
        // unlink emitiria warning nele.
        if (is_file($this->markerPath())) {
            @unlink($this->markerPath());
        }

        if (!$this->order(self::F_ORDEM_DESLIGAR)) {
            return 'Não foi possível escrever a ordem de desligar em ' . $this->filesDir
                . '. O PowerShell elevado pode continuar de pé até o servidor reiniciar.';
        }

        $limite = microtime(true) + self::SHUTDOWN_WAIT_S;

        while (microtime(true) < $limite) {
            $idade = $this->heartbeatAge();

            if ($idade === null || $idade > self::HEARTBEAT_STALE_S) {
                break;
            }

            usleep(150000);
        }

        $idade = $this->heartbeatAge();
        $pid   = $marcador['ps_pid'] ?? null;

        if ($idade === null) {
            return 'PowerShell desligado' . ($pid === null ? '' : ' (PID ' . $pid . ')') . '.';
        }

        // Não é falha: o PHP não pode matar processo de integridade Alta, então
        // o encerramento depende do próximo tique do worker.
        return 'Ordem de desligar deixada. O PowerShell elevado sai no próximo tique — '
            . 'o PHP não pode encerrá-lo direto, porque roda em integridade menor.';
    }

    /** Caminho do arquivo de trabalho, para quem for enfileirar um job. */
    public function jobPath(): string
    {
        return $this->path($this->filesDir, self::F_JOB);
    }

    /** Nonce em vigor, ou null. Quem monta um job precisa carimbá-lo. */
    public function nonce(): ?string
    {
        return $this->readMarker()['nonce'] ?? null;
    }

    public function markerPath(): string
    {
        return $this->path($this->storageDir, self::F_MARCADOR);
    }

    /** Escreve uma ordem para o worker. O conteúdo não importa; a presença sim. */
    public function order(string $nome): bool
    {
        return @file_put_contents($this->path($this->filesDir, $nome), "1\n") !== false;
    }

    /**
     * O que impede até de tentar ligar, ou null.
     *
     * ANTES ISTO OLHAVA O .env. Havia uma chave, PHPORTO_WINUTIL_PATH, que
     * apontava para um projeto externo, e a maioria dos problemas daqui era
     * alguém não tê-la preenchido. As ações moram neste repositório desde a
     * migração, então não há mais nada a configurar — e o que sobrou de
     * verificável é o checkout.
     *
     * Não é checagem inútil por isso: `git clone` parcial, sparse-checkout, um
     * .gitignore mal escrito ou um deploy que copiou só o que o autoloader
     * conhece deixam src/Win sem os .ps1. Sem esta mensagem, o sintoma seria
     * uma elevação que sobe e sai em silêncio, ou um job que nunca conclui.
     */
    private function configProblem(): ?string
    {
        foreach ([self::SCRIPT_WORKER, self::SCRIPT_BOOTSTRAP] as $nome) {
            $caminho = $this->scriptPath($nome);

            if (!is_file($caminho)) {
                return sprintf(
                    'Instalação incompleta: %s não foi encontrado em %s. '
                    . 'Sem ele não há o que executar — o motor do Windows mora no próprio '
                    . 'repositório, e este arquivo faz parte dele.',
                    $nome,
                    $this->winDir
                );
            }
        }

        return null;
    }

    /** Caminho de um dos .ps1 do motor. */
    private function scriptPath(string $nome): string
    {
        return rtrim($this->winDir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }

    /**
     * O marcador, ou null quando ausente, ilegível ou incompleto.
     *
     * JSON ilegível vira null, e null significa desligado — a falha cai para
     * o lado seguro por construção, não por tratamento de erro. É a mesma
     * regra do Flags, e aqui o lado seguro é não afirmar que existe um
     * processo elevado de pé.
     *
     * @return array{php_pid: int, ps_pid: int, nonce: string, provado_em: string}|null
     */
    private function readMarker(): ?array
    {
        $caminho = $this->markerPath();

        // is_file() antes de ler: marcador ausente é o estado NORMAL (nada
        // ligado), e o file_get_contents emitiria warning num caminho que não
        // é excepcional — o "@" não o silencia sob o error handler do PHPUnit.
        if (!is_file($caminho)) {
            return null;
        }

        $raw = @file_get_contents($caminho);

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $phpPid = $data['php_pid'] ?? null;
        $psPid  = $data['ps_pid'] ?? null;
        $nonce  = $data['nonce'] ?? null;
        $em     = $data['provado_em'] ?? null;

        if (!is_int($phpPid) || !is_int($psPid) || !is_string($nonce) || !is_string($em)) {
            return null;
        }

        if ($phpPid <= 0 || $psPid <= 0 || $nonce === '') {
            return null;
        }

        return ['php_pid' => $phpPid, 'ps_pid' => $psPid, 'nonce' => $nonce, 'provado_em' => $em];
    }

    private function writeMarker(int $psPid, string $nonce): bool
    {
        $json = json_encode([
            'php_pid'    => getmypid(),
            'ps_pid'     => $psPid,
            'nonce'      => $nonce,
            'provado_em' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return false;
        }

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0o775, true) && !is_dir($this->storageDir)) {
            return false;
        }

        return @file_put_contents($this->markerPath(), $json . "\n", LOCK_EX) !== false;
    }

    /** Idade do heartbeat em segundos, ou null quando não há arquivo. */
    private function heartbeatAge(): ?int
    {
        $caminho = $this->path($this->filesDir, self::F_HEARTBEAT);

        // Sem clearstatcache o filemtime devolve o valor da primeira leitura
        // da requisição, e a espera do disable() nunca veria o arquivo sumir.
        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            return null;
        }

        $mt = filemtime($caminho);

        return is_int($mt) ? max(0, time() - $mt) : null;
    }

    /** Apaga sobras do canal, para uma tentativa não ler resposta da anterior. */
    private function cleanupChannel(): void
    {
        foreach ([self::F_ORDEM_DESLIGAR, self::F_ORDEM_CANCELAR, self::F_JOB, self::F_HEARTBEAT] as $nome) {
            @unlink($this->path($this->filesDir, $nome));
        }

        foreach (glob($this->filesDir . DIRECTORY_SEPARATOR . 'win-prova-*.txt') ?: [] as $antiga) {
            @unlink($antiga);
        }
    }

    /**
     * O corpo do lançador.
     *
     * NÃO DÁ PARA REDIRECIONAR a saída de um processo elevado: medido,
     * Start-Process -Verb RunAs junto de -RedirectStandardOutput devolve
     * "o conjunto de parâmetros não pode ser resolvido". É por isso que a
     * prova de que o worker subiu chega por ARQUIVO, e não por pipe.
     *
     * Todo argumento entra como literal de apóstrofo simples: dentro dele o
     * PowerShell não interpola nada, então nem um caminho com $ ou crase
     * vira código.
     */
    private function launcherBody(string $nonce): string
    {
        $args = [
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $this->scriptPath(self::SCRIPT_WORKER),
            '-ParentPid',
            (string) getmypid(),
            '-Dir',
            $this->filesDir,
            '-Nonce',
            $nonce,
        ];

        return sprintf(
            'Start-Process -FilePath %s -Verb RunAs -WindowStyle Hidden -ArgumentList %s',
            PsScriptBuilder::literal('powershell.exe'),
            implode(',', array_map([PsScriptBuilder::class, 'literal'], $args))
        );
    }

    private function path(string $dir, string $nome): string
    {
        return rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $nome;
    }
}
