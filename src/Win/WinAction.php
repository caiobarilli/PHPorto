<?php

declare(strict_types=1);

namespace App\Win;

use App\Domain\WinStateScope;
use InvalidArgumentException;
use RuntimeException;

enum WinAction: string
{
    case Audit = 'audit';
    case Tweaks = 'tweaks';
    case Debloat = 'debloat';
    case Dns = 'dns';
    case Performance = 'performance';
    case Install = 'install';
    case Memory = 'memory';
    case Network = 'network';
    case Exporter = 'exporter';
    case Processes = 'processes';
    case Optimize = 'optimize';
    case Gpu = 'gpu';
    case Gdid = 'gdid';
    case Rdp = 'rdp';
    case Sunshine = 'sunshine';
    case OpenSsh = 'openssh';
    case SshKeys = 'ssh-keys';

    case Hyperv = 'hyperv';

    case HypervVm = 'hyperv-vm';

    case Wsl = 'wsl';

    public const MAX_PARAM_BYTES = 4096;

    public const WINGET_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_+-]*(?:\.[A-Za-z0-9_+-]+)+\z/';

    public const DNS_EXTRA = ['DHCP'];

    public const TWEAK_PRESETS = ['standard', 'minimal', 'advanced'];

    /**
     * @var array<string, string>
     */
    public const INSTALL_SUGGESTIONS = [
        'Git.Git'                     => 'Git',
        'Microsoft.VisualStudioCode'  => 'Visual Studio Code',
        'Docker.DockerDesktop'        => 'Docker Desktop',
        'Microsoft.WSL'               => 'WSL',
        'Debian.Debian'               => 'Debian',
        '7zip.7zip'                   => '7-Zip',
        'VB-Audio.Voicemeeter.Potato' => 'VoiceMeeter Potato',
    ];

    public const OPTIMIZE_PRESETS = ['ssh', 'kill-rdp'];

    public const EXPORTER_SUBACTIONS = ['install', 'status', 'start', 'stop', 'metrics', 'firewall'];

    public const GPU_SUBACTIONS = ['install', 'status', 'start', 'stop', 'metrics', 'uninstall'];

    public const GDID_SUBACTIONS = ['status', 'disable', 'enable'];

    public const RDP_SUBACTIONS = ['status', 'on', 'off', 'h264-on', 'h264-off'];

    public const SUNSHINE_SUBACTIONS = ['status', 'install', 'start', 'stop', 'firewall-open', 'firewall-close', 'set-creds', 'pair'];

    public const OPENSSH_SUBACTIONS = ['status', 'install', 'enable', 'disable'];

    public const SSHKEYS_SUBACTIONS = ['status', 'generate', 'puttygen', 'list', 'authorize', 'remove', 'wsl-user'];

    public const SSHKEYS_KEY_SUBACTIONS = ['authorize', 'wsl-user'];

    public const SSHKEYS_FIELDS = ['PublicKey', 'UseGenerated', 'Fingerprint', 'User', 'Distro'];

    public const SSH_PUBLIC_KEY_PATTERN = '\A(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521) AAAA[A-Za-z0-9+/]{16,2000}={0,2}(?: [A-Za-z0-9@._+-]{1,100})?\z';

    public const SSH_PUBLIC_KEY_MAX = 2200;

    public const SSH_FINGERPRINT_PATTERN = '\ASHA256:[A-Za-z0-9+/]{43}\z';

    public const WSL_USER_PATTERN = '\A[a-z][a-z0-9-]{0,31}\z';

    public const WSL_USER_RESERVED = ['root', 'daemon', 'bin', 'sys', 'sync', 'nobody', 'sshd'];

    public const WSL_DISTRO_PATTERN = '\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z';

    public const PUTTYGEN_WINGET_ID = 'PuTTY.PuTTY';

    public const SUNSHINE_CRED_SUBACTIONS = ['set-creds', 'pair'];

    public const SUNSHINE_CRED_FIELDS = ['User', 'Password', 'Pin', 'DeviceName', 'SetCreds'];

    public const SUNSHINE_USER_MAX = 64;

    public const SUNSHINE_PASSWORD_MIN = 8;

    public const SUNSHINE_PASSWORD_MAX = 256;

    public const SUNSHINE_DEVICE_MAX = 128;

    public const SUNSHINE_DEVICE_DEFAULT = 'notebook';

    public const SECRET_PARAMS = ['Password', 'Pin'];

    public const HYPERV_VM_SUBACTIONS = ['start', 'stop', 'restart'];

    public const HYPERV_VM_ID_PATTERN = '\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z';

    public const HYPERV_VM_ID_BYTES = 36;

    public const WSL_SUBACTIONS = ['shutdown', 'wake'];

    public const AUDIT_SUBACTIONS = ['run', 'open'];

    public const PERFORMANCE_STATES = ['on', 'off'];

    /**
     * @var array<string, '*'|list<string>>
     */
    public const SENSITIVE = [
        'install'   => '*',
        'rdp'       => ['on'],
        'sunshine'  => ['install', 'firewall-open', 'set-creds', 'pair'],
        'openssh'   => ['install', 'enable'],
        'ssh-keys'  => ['puttygen', 'authorize', 'remove', 'wsl-user'],
        'exporter'  => ['install', 'firewall'],
        'gpu'       => ['install'],
        'hyperv-vm' => '*',
    ];

    public const NETWORK_DURATION_MIN = 1;

    public const NETWORK_DURATION_MAX = 3600;

    /**
     * @param array<string, string> $input
     * @return array<string, string|int|bool>
     * @throws InvalidArgumentException
     */
    public function validate(array $input): array
    {
        return match ($this) {
            self::Memory,
            self::Processes,
            self::Hyperv => [],

            self::Audit       => $this->opcional($input, 'SubAction', self::AUDIT_SUBACTIONS),
            self::Performance => $this->opcional($input, 'State', self::PERFORMANCE_STATES),

            self::Tweaks    => $this->tweaks($input),
            self::Debloat   => $this->debloat($input),
            self::Dns       => $this->dns($input),
            self::Install   => $this->install($input),
            self::Network   => $this->network($input),
            self::Exporter  => ['SubAction' => $this->pick($input, 'SubAction', self::EXPORTER_SUBACTIONS, obrigatorio: true)],
            self::Gpu       => ['SubAction' => $this->pick($input, 'SubAction', self::GPU_SUBACTIONS, obrigatorio: true)],
            self::Gdid      => ['SubAction' => $this->pick($input, 'SubAction', self::GDID_SUBACTIONS, obrigatorio: true)],
            self::Rdp       => ['SubAction' => $this->pick($input, 'SubAction', self::RDP_SUBACTIONS, obrigatorio: true)],
            self::Sunshine  => $this->sunshine($input),
            self::OpenSsh   => ['SubAction' => $this->pick($input, 'SubAction', self::OPENSSH_SUBACTIONS, obrigatorio: true)],
            self::SshKeys   => $this->sshKeys($input),
            self::Optimize  => $this->optimize($input),
            self::HypervVm  => $this->hypervVm($input),
            self::Wsl       => ['SubAction' => $this->pick($input, 'SubAction', self::WSL_SUBACTIONS, obrigatorio: true)],
        };
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    public function isSensitive(array $params): bool
    {
        $regra = self::SENSITIVE[$this->value] ?? null;

        if ($regra === null) {
            return false;
        }

        return $regra === '*' || in_array($params['SubAction'] ?? '', $regra, true);
    }

    /**
     * @param array<string, string|int|bool> $params
     */
    public function stateChange(array $params): ?WinStateChange
    {
        return match ($this) {
            self::Tweaks => new WinStateChange(
                applied: !isset($params['Undo']),
                payload: isset($params['Items'])
                    ? ['Items' => $params['Items']]
                    : (isset($params['Preset']) ? ['Preset' => $params['Preset']] : []),
            ),

            self::Optimize => new WinStateChange(
                applied: !isset($params['Undo']),
                payload: isset($params['Preset']) ? ['Preset' => $params['Preset']] : [],
            ),

            self::Gdid => match ($params['SubAction'] ?? '') {
                'disable' => new WinStateChange(applied: true),
                'enable'  => new WinStateChange(applied: false),
                default   => null,
            },

            self::Performance => new WinStateChange(
                applied: ($params['State'] ?? 'on') !== 'off',
            ),

            self::Rdp => match ($params['SubAction'] ?? '') {
                'on'       => new WinStateChange(applied: true),
                'off'      => new WinStateChange(applied: false),
                'h264-on'  => new WinStateChange(applied: true, scope: WinStateScope::PendingReboot),
                'h264-off' => new WinStateChange(applied: false, scope: WinStateScope::PendingReboot),
                default    => null,
            },

            self::Sunshine => match ($params['SubAction'] ?? '') {
                'start' => new WinStateChange(applied: true),
                'stop'  => new WinStateChange(applied: false),
                default => null,
            },

            self::OpenSsh => match ($params['SubAction'] ?? '') {
                'enable'  => new WinStateChange(applied: true),
                'disable' => new WinStateChange(applied: false),
                default   => null,
            },

            default => null,
        };
    }

    /**
     * @param array<string, string|int|bool>|null $antes
     * @param array<string, list<string>> $presets
     * @return array<string, string|int|bool>|null
     */
    public function mergeState(?array $antes, WinStateChange $mudanca, array $presets): ?array
    {
        if ($this !== self::Tweaks) {
            return $mudanca->applied ? $mudanca->payload : null;
        }

        $guardadas = self::tweakKeysOf($antes, $presets);
        $destaVez  = self::tweakKeysOf($mudanca->payload, $presets);

        $resultado = $mudanca->applied
            ? array_values(array_unique(array_merge($guardadas, $destaVez)))
            : array_values(array_diff($guardadas, $destaVez));

        return $resultado === [] ? null : ['Items' => implode(',', $resultado)];
    }

    /**
     * @param array<string, string|int|bool>|null $payload
     * @param array<string, list<string>> $presets
     * @return list<string>
     */
    public static function tweakKeysOf(?array $payload, array $presets): array
    {
        if ($payload === null) {
            return [];
        }

        if (isset($payload['Items']) && is_string($payload['Items'])) {
            return array_values(array_filter(array_map('trim', explode(',', $payload['Items'])), static fn (string $k): bool => $k !== ''));
        }

        if (isset($payload['Preset']) && is_string($payload['Preset'])) {
            return $presets[strtolower($payload['Preset'])] ?? [];
        }

        return [];
    }

    /**
     * @param list<string> $marcadas
     * @param list<string> $aplicadas
     */
    public static function tweaksRevert(array $marcadas, array $aplicadas): bool
    {
        return $marcadas !== [] && array_diff($marcadas, $aplicadas) === [];
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string|bool>
     */
    private function tweaks(array $input): array
    {
        $preset = $this->pick($input, 'Preset', self::TWEAK_PRESETS, obrigatorio: false);
        $itens  = $this->lista($input, 'Items', 'Tweak', static fn (): array => WinConfig::tweakKeys());

        if ($preset !== '' && $itens !== '') {
            throw new InvalidArgumentException('Escolha um preset ou marque tweaks, não os dois.');
        }

        if ($preset === '' && $itens === '') {
            throw new InvalidArgumentException('Marque ao menos um tweak.');
        }

        $params = $itens !== '' ? ['Items' => $itens] : ['Preset' => $preset];

        if ($this->flag($input, 'Undo')) {
            $params['Undo'] = true;
        }

        return $params;
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function debloat(array $input): array
    {
        $pacotes = $this->lista($input, 'Packages', 'Pacote', static fn (): array => WinConfig::debloat());

        if ($pacotes === '') {
            if (($input['PackagesForm'] ?? '') === '1') {
                throw new InvalidArgumentException('Marque ao menos um pacote.');
            }

            return [];
        }

        return ['Packages' => $pacotes];
    }

    /**
     * @param array<string, string> $input
     * @param callable(): list<string> $permitidos
     * @throws InvalidArgumentException
     */
    private function lista(array $input, string $campo, string $rotulo, callable $permitidos): string
    {
        $bruto = $this->texto($input, $campo, obrigatorio: false);

        if ($bruto === '') {
            return '';
        }

        try {
            $validos = $permitidos();
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Não foi possível ler a lista de ' . $campo . ': ' . $e->getMessage());
        }

        $porMinuscula = [];

        foreach ($validos as $valido) {
            $porMinuscula[strtolower($valido)] = $valido;
        }

        $aceitos = [];

        foreach (explode(',', $bruto) as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            $canonico = $porMinuscula[strtolower($item)] ?? null;

            if ($canonico === null) {
                throw new InvalidArgumentException(sprintf('%s desconhecido: %s.', $rotulo, $item));
            }

            if (in_array($canonico, $aceitos, true)) {
                throw new InvalidArgumentException(sprintf('%s repetido: %s.', $rotulo, $canonico));
            }

            $aceitos[] = $canonico;
        }

        return implode(',', $aceitos);
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function dns(array $input): array
    {
        try {
            $providers = [...WinConfig::dnsProviders(), ...self::DNS_EXTRA];
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Não foi possível ler a lista de DNS: ' . $e->getMessage());
        }

        $provider = $this->pick($input, 'Provider', $providers, obrigatorio: true);
        $params   = ['Provider' => $provider];

        if (strcasecmp($provider, 'Custom') === 0) {
            $params['PrimaryDNS'] = $this->ip($input, 'PrimaryDNS', obrigatorio: true);

            $secundario = $this->ip($input, 'SecondaryDNS', obrigatorio: false);
            if ($secundario !== '') {
                $params['SecondaryDNS'] = $secundario;
            }
        }

        return $params;
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function install(array $input): array
    {
        $apps     = $this->texto($input, 'Apps', obrigatorio: false);
        $marcados = $this->lista($input, 'AppsMarcados', 'App', static fn (): array => array_keys(self::INSTALL_SUGGESTIONS));

        $itens = array_values(array_filter(array_map('trim', explode(',', $apps)), static fn (string $i): bool => $i !== ''));

        if ($marcados === '' && $itens === []) {
            throw new InvalidArgumentException('Informe ao menos um app, separados por vírgula, ou marque um dos principais.');
        }

        foreach ($itens as $id) {
            if (preg_match(self::WINGET_ID_PATTERN, $id) !== 1) {
                throw new InvalidArgumentException('App fora do formato de ID do winget (Editor.Nome): ' . $id . '.');
            }
        }

        $jaTem = array_map('strtolower', $itens);

        foreach ($marcados === '' ? [] : explode(',', $marcados) as $id) {
            if (!in_array(strtolower($id), $jaTem, true)) {
                $itens[] = $id;
                $jaTem[]  = strtolower($id);
            }
        }

        $juntos = implode(',', $itens);

        if (strlen($juntos) > self::MAX_PARAM_BYTES) {
            throw new InvalidArgumentException(sprintf('Apps passou do teto de %d bytes.', self::MAX_PARAM_BYTES));
        }

        return ['Apps' => $juntos];
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string|bool>
     */
    private function sunshine(array $input): array
    {
        $sub    = $this->pick($input, 'SubAction', self::SUNSHINE_SUBACTIONS, obrigatorio: true);
        $params = ['SubAction' => $sub];

        if (!in_array($sub, self::SUNSHINE_CRED_SUBACTIONS, true)) {
            foreach (self::SUNSHINE_CRED_FIELDS as $campo) {
                if (trim($input[$campo] ?? '') !== '') {
                    throw new InvalidArgumentException(sprintf('%s não vale para a subação %s.', $campo, $sub));
                }
            }

            return $params;
        }

        $params['User']     = $this->sunshineUser($input);
        $params['Password'] = $this->sunshinePassword($input);

        if ($sub === 'set-creds') {
            foreach (['Pin', 'DeviceName'] as $campo) {
                if (trim($input[$campo] ?? '') !== '') {
                    throw new InvalidArgumentException(sprintf(
                        'Só gravar credenciais não usa %s: apague o campo, ou use Parear com o PIN.',
                        $campo
                    ));
                }
            }

            return $params;
        }

        $pin = trim($input['Pin'] ?? '');

        if ($pin === '') {
            throw new InvalidArgumentException('Preencha Pin.');
        }

        if (preg_match('/\A[0-9]{4}\z/', $pin) !== 1) {
            throw new InvalidArgumentException('Pin deve ter 4 dígitos, de 0 a 9.');
        }

        $params['Pin']        = $pin;
        $params['DeviceName'] = $this->sunshineDevice($input);

        if ($this->flag($input, 'SetCreds')) {
            $params['SetCreds'] = true;
        }

        return $params;
    }

    public static function isSshPublicKey(string $linha): bool
    {
        if (strlen($linha) > self::SSH_PUBLIC_KEY_MAX || preg_match('~' . self::SSH_PUBLIC_KEY_PATTERN . '~', $linha) !== 1) {
            return false;
        }

        [$tipo, $base64] = explode(' ', $linha, 3);
        $blob            = base64_decode($base64, true);

        if ($blob === false || strlen($blob) < 4) {
            return false;
        }

        $tamanho = unpack('N', substr($blob, 0, 4));

        return is_array($tamanho) && $tamanho[1] === strlen($tipo) && substr($blob, 4, strlen($tipo)) === $tipo;
    }

    public static function sshFingerprint(string $linha): ?string
    {
        if (!self::isSshPublicKey($linha)) {
            return null;
        }

        $blob = (string) base64_decode(explode(' ', $linha, 3)[1], true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string|bool>
     */
    private function sshKeys(array $input): array
    {
        $sub    = $this->pick($input, 'SubAction', self::SSHKEYS_SUBACTIONS, obrigatorio: true);
        $params = ['SubAction' => $sub];

        $usados = [];
        foreach (self::SSHKEYS_FIELDS as $campo) {
            if (trim($input[$campo] ?? '') !== '') {
                $usados[] = $campo;
            }
        }

        $aceitos = match ($sub) {
            'authorize' => ['PublicKey', 'UseGenerated'],
            'wsl-user'  => ['PublicKey', 'UseGenerated', 'User', 'Distro'],
            'remove'    => ['Fingerprint'],
            default     => [],
        };

        foreach (array_diff($usados, $aceitos) as $campo) {
            throw new InvalidArgumentException(sprintf('%s não vale para a subação %s.', $campo, $sub));
        }

        if (in_array($sub, self::SSHKEYS_KEY_SUBACTIONS, true)) {
            $params += $this->sshKey($input);
        }

        if ($sub === 'remove') {
            $digital = trim($input['Fingerprint'] ?? '');

            if (preg_match('~' . self::SSH_FINGERPRINT_PATTERN . '~', $digital) !== 1) {
                throw new InvalidArgumentException('Fingerprint precisa ser SHA256: seguido de 43 caracteres.');
            }

            $params['Fingerprint'] = $digital;
        }

        if ($sub === 'wsl-user') {
            $params['User']   = self::wslUser($input['User'] ?? '');
            $params['Distro'] = self::wslDistro($input['Distro'] ?? '');
        }

        return $params;
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string|bool>
     */
    private function sshKey(array $input): array
    {
        $chave = trim($input['PublicKey'] ?? '');
        $usar  = $this->flag($input, 'UseGenerated');

        if ($usar && $chave !== '') {
            throw new InvalidArgumentException('Cole uma pública ou use a gerada, não os dois.');
        }

        if ($usar) {
            return ['UseGenerated' => true];
        }

        if ($chave === '') {
            throw new InvalidArgumentException('Cole a chave pública, ou use a gerada acima.');
        }

        if (!self::isSshPublicKey($chave)) {
            throw new InvalidArgumentException(
                'Essa não é uma chave pública. Cole uma linha ssh-ed25519, ssh-rsa ou ecdsa, sem a privada e sem opções na frente.'
            );
        }

        return ['PublicKey' => $chave];
    }

    private static function wslUser(string $bruto): string
    {
        $user = trim($bruto);

        if (preg_match('/' . self::WSL_USER_PATTERN . '/', $user) !== 1) {
            throw new InvalidArgumentException('Usuário: só minúsculas, números e hífen, começando por letra, até 32.');
        }

        if (in_array($user, self::WSL_USER_RESERVED, true)) {
            throw new InvalidArgumentException(sprintf('O usuário %s é do sistema. Escolha outro nome.', $user));
        }

        return $user;
    }

    private static function wslDistro(string $bruto): string
    {
        $distro = trim($bruto);

        if (preg_match('/' . self::WSL_DISTRO_PATTERN . '/', $distro) !== 1) {
            throw new InvalidArgumentException('Escolha uma distro da lista.');
        }

        return $distro;
    }

    /**
     * @param array<string, string> $input
     */
    private function sunshineUser(array $input): string
    {
        $user = trim($input['User'] ?? '');

        if ($user === '') {
            throw new InvalidArgumentException('Preencha User.');
        }

        self::semControle('User', $user, self::SUNSHINE_USER_MAX);

        if (str_contains($user, ':')) {
            throw new InvalidArgumentException("User não pode ter ':'.");
        }

        return $user;
    }

    /**
     * @param array<string, string> $input
     */
    private function sunshinePassword(array $input): string
    {
        $senha = $input['Password'] ?? '';

        if ($senha === '') {
            throw new InvalidArgumentException('Preencha Password.');
        }

        self::semControle('Password', $senha, self::SUNSHINE_PASSWORD_MAX);

        if (strlen($senha) < self::SUNSHINE_PASSWORD_MIN) {
            throw new InvalidArgumentException(sprintf(
                'Password precisa de pelo menos %d bytes.',
                self::SUNSHINE_PASSWORD_MIN
            ));
        }

        return $senha;
    }

    /**
     * @param array<string, string> $input
     */
    private function sunshineDevice(array $input): string
    {
        $nome = trim($input['DeviceName'] ?? '');

        if ($nome === '') {
            return self::SUNSHINE_DEVICE_DEFAULT;
        }

        self::semControle('DeviceName', $nome, self::SUNSHINE_DEVICE_MAX);

        return $nome;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function semControle(string $campo, string $valor, int $teto): void
    {
        if (!mb_check_encoding($valor, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf('%s tem bytes que não são texto UTF-8.', $campo));
        }

        if (strlen($valor) > $teto) {
            throw new InvalidArgumentException(sprintf('%s passou do teto de %d bytes.', $campo, $teto));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $valor) === 1) {
            throw new InvalidArgumentException(sprintf('%s não pode ter caractere de controle.', $campo));
        }
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function hypervVm(array $input): array
    {
        $sub = $this->pick($input, 'SubAction', self::HYPERV_VM_SUBACTIONS, obrigatorio: true);
        $id  = trim($input['VmId'] ?? '');

        if ($id === '') {
            throw new InvalidArgumentException('Preencha VmId.');
        }

        if (strlen($id) !== self::HYPERV_VM_ID_BYTES || preg_match('/' . self::HYPERV_VM_ID_PATTERN . '/', $id) !== 1) {
            throw new InvalidArgumentException('VmId não é um identificador de VM válido.');
        }

        return ['SubAction' => $sub, 'VmId' => strtolower($id)];
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string|int>
     */
    private function network(array $input): array
    {
        $params = ['Interface' => $this->texto($input, 'Interface', obrigatorio: true)];

        $bruto = trim($input['Duration'] ?? '');

        if ($bruto === '') {
            return $params;
        }

        if (preg_match('/^\d+$/', $bruto) !== 1) {
            throw new InvalidArgumentException('Duração deve ser um número inteiro de segundos.');
        }

        $duracao = (int) $bruto;

        if ($duracao < self::NETWORK_DURATION_MIN || $duracao > self::NETWORK_DURATION_MAX) {
            throw new InvalidArgumentException(sprintf(
                'Duração fora da faixa (%d-%d segundos).',
                self::NETWORK_DURATION_MIN,
                self::NETWORK_DURATION_MAX
            ));
        }

        $params['Duration'] = $duracao;

        return $params;
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string|bool>
     */
    private function optimize(array $input): array
    {
        $params = [];

        $preset = $this->pick($input, 'Preset', self::OPTIMIZE_PRESETS, obrigatorio: false);
        if ($preset !== '') {
            $params['Preset'] = $preset;
        }

        $kill = $this->texto($input, 'Kill', obrigatorio: false);
        if ($kill !== '') {
            $params['Kill'] = $kill;
        }

        $keepUser = $this->texto($input, 'KeepUser', obrigatorio: false);
        if ($keepUser !== '') {
            $params['KeepUser'] = $keepUser;
        }

        if ($this->flag($input, 'Undo')) {
            $params['Undo'] = true;
        }

        if (!isset($params['Preset']) && !isset($params['Kill']) && !isset($params['Undo'])) {
            throw new InvalidArgumentException(
                'Escolha um preset, uma lista de processos, ou marque restaurar (-Undo).'
            );
        }

        return $params;
    }

    /**
     * @param array<string, string> $input
     * @param list<string> $permitidos
     * @return array<string, string>
     */
    private function opcional(array $input, string $campo, array $permitidos): array
    {
        $valor = $this->pick($input, $campo, $permitidos, obrigatorio: false);

        return $valor === '' ? [] : [$campo => $valor];
    }

    /**
     * @param array<string, string> $input
     * @param list<string> $permitidos
     */
    private function pick(array $input, string $campo, array $permitidos, bool $obrigatorio): string
    {
        $valor = trim($input[$campo] ?? '');

        if ($valor === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException(sprintf(
                    'Escolha um valor para %s (%s).',
                    $campo,
                    implode(', ', $permitidos)
                ));
            }

            return '';
        }

        foreach ($permitidos as $permitido) {
            if (strcasecmp($valor, $permitido) === 0) {
                return $permitido;
            }
        }

        throw new InvalidArgumentException(sprintf('Valor inválido para %s.', $campo));
    }

    /**
     * @param array<string, string> $input
     */
    private function texto(array $input, string $campo, bool $obrigatorio): string
    {
        $valor = trim($input[$campo] ?? '');

        if ($valor === '' && $obrigatorio) {
            throw new InvalidArgumentException(sprintf('Preencha %s.', $campo));
        }

        if (strlen($valor) > self::MAX_PARAM_BYTES) {
            throw new InvalidArgumentException(sprintf(
                '%s passou do teto de %d bytes.',
                $campo,
                self::MAX_PARAM_BYTES
            ));
        }

        return $valor;
    }

    /**
     * @param array<string, string> $input
     */
    private function ip(array $input, string $campo, bool $obrigatorio): string
    {
        $valor = trim($input[$campo] ?? '');

        if ($valor === '') {
            if ($obrigatorio) {
                throw new InvalidArgumentException(sprintf('Preencha %s.', $campo));
            }

            return '';
        }

        if (filter_var($valor, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(sprintf('%s não é um endereço IP válido.', $campo));
        }

        return $valor;
    }

    /**
     * @param array<string, string> $input
     */
    private function flag(array $input, string $campo): bool
    {
        return ($input[$campo] ?? '') === '1';
    }
}
