<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

/**
 * Leitura dos JSON de src/Win/config, os mesmos que as ações do PowerShell leem.
 */
final class WinConfig
{
    /** Valores de Type que, no tweaks.json, são controles da janela do WinUtil e não tweaks. */
    public const NOT_TWEAK_TYPES = ['Button', 'Combobox'];

    /**
     * Os pacotes APPX que a ação debloat remove, na ordem do arquivo.
     *
     * Recebe, opcionalmente, outra pasta de config (para teste). Devolve a
     * lista de nomes de pacote.
     *
     * @return list<string>
     *
     * @throws RuntimeException se o arquivo faltar ou não for uma lista de nomes
     */
    public static function debloat(?string $dir = null): array
    {
        $dados = self::json($dir, 'debloat');

        if (!is_array($dados) || !array_is_list($dados) || $dados === []) {
            throw new RuntimeException('debloat.json não é uma lista de pacotes.');
        }

        $pacotes = [];

        foreach ($dados as $pacote) {
            if (!is_string($pacote) || trim($pacote) === '') {
                throw new RuntimeException('debloat.json tem um item que não é nome de pacote.');
            }

            $pacotes[] = $pacote;
        }

        return $pacotes;
    }

    /**
     * O rótulo e o texto de cada opção de DNS da tela: as chaves do dns.json e o DHCP.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const DNS_TEXTS = [
        'Google'                             => ['Google', 'O DNS público do Google. Rápido e estável, não bloqueia nada. O Google fica sabendo quais sites você abre.'],
        'Cloudflare'                         => ['Cloudflare', 'O DNS público da Cloudflare, costuma ser o mais rápido. Não bloqueia nada e promete não guardar o que você acessa.'],
        'Cloudflare_Malware'                 => ['Cloudflare contra vírus', 'O mesmo da Cloudflare, mas impede de abrir sites conhecidos por espalhar vírus e golpes.'],
        'Cloudflare_Malware_Adult'           => ['Cloudflare família', 'Cloudflare bloqueando vírus, golpes e também sites adultos. Serve para computador usado por criança.'],
        'Open_DNS'                           => ['OpenDNS', 'O DNS da Cisco. Bloqueia sites de golpe que imitam banco e loja para roubar senha.'],
        'Quad9'                              => ['Quad9', 'Serviço sem fins lucrativos, na Suíça. Bloqueia sites de vírus e golpes e não guarda quem fez o acesso.'],
        'AdGuard_Ads_Trackers'               => ['AdGuard sem propaganda', 'Bloqueia propaganda e rastreadores em todos os programas, não só no navegador. Um ou outro site pode parar de funcionar direito.'],
        'AdGuard_Ads_Trackers_Malware_Adult' => ['AdGuard família', 'Bloqueia propaganda, rastreadores, vírus e sites adultos, e obriga a busca segura no Google e no YouTube.'],
        'Custom'                             => ['DNS próprio', 'Os endereços que você digitar abaixo — o do roteador, ou o de um servidor da sua rede.'],
        'DHCP'                               => ['Automático (DHCP)', 'Volta ao padrão do Windows: usa o DNS que o roteador entregar. Desfaz qualquer escolha acima.'],
    ];

    /**
     * Os provedores de DNS do dns.json, na ordem do arquivo.
     *
     * Recebe, opcionalmente, outra pasta de config. Devolve as chaves.
     *
     * @return list<string>
     *
     * @throws RuntimeException se o arquivo faltar ou não for um mapa de provedores
     */
    public static function dnsProviders(?string $dir = null): array
    {
        $dados = self::json($dir, 'dns');

        if (!is_array($dados) || array_is_list($dados)) {
            throw new RuntimeException('dns.json não é um mapa de provedores.');
        }

        return array_values(array_filter(array_keys($dados), 'is_string'));
    }

    /**
     * Os tweaks que a tela oferece, na ordem do tweaks.json.
     *
     * Recebe, opcionalmente, outra pasta de config. Devolve um item por
     * entrada cujo Type não é Button nem Combobox, com a chave, o Content, a
     * Description, o category sem o prefixo de ordenação "z__", se o category
     * pede cuidado, e se o script do tweak avisa o Explorer.
     *
     * @return list<array{key: string, content: string, description: string, category: string, caution: bool, explorer: bool}>
     *
     * @throws RuntimeException se o arquivo faltar ou não for um mapa de tweaks
     */
    public static function tweaks(?string $dir = null): array
    {
        $dados = self::json($dir, 'tweaks');

        if (!is_array($dados) || array_is_list($dados)) {
            throw new RuntimeException('tweaks.json não é um mapa de tweaks.');
        }

        $itens = [];

        foreach ($dados as $chave => $tweak) {
            if (!is_string($chave) || !is_array($tweak)) {
                continue;
            }

            $tipo = $tweak['Type'] ?? '';

            if (in_array($tipo, self::NOT_TWEAK_TYPES, true)) {
                continue;
            }

            $categoria = is_string($tweak['category'] ?? null) ? $tweak['category'] : '';
            $scripts   = [];

            foreach (['InvokeScript', 'UndoScript'] as $campo) {
                foreach ((array) ($tweak[$campo] ?? []) as $script) {
                    if (is_string($script)) {
                        $scripts[] = $script;
                    }
                }
            }

            $itens[] = [
                'key'         => $chave,
                'content'     => is_string($tweak['Content'] ?? null) ? $tweak['Content'] : $chave,
                'description' => is_string($tweak['Description'] ?? null) ? $tweak['Description'] : '',
                'category'    => (string) preg_replace('/^z__/', '', $categoria),
                'caution'     => stripos($categoria, 'CAUTION') !== false,
                'explorer'    => stripos(implode("\n", $scripts), 'Invoke-WinUtilExplorerUpdate') !== false,
            ];
        }

        return $itens;
    }

    /**
     * As chaves dos tweaks que a tela oferece.
     *
     * Recebe, opcionalmente, outra pasta de config. Devolve as chaves na ordem
     * do tweaks.json.
     *
     * @return list<string>
     *
     * @throws RuntimeException se o tweaks.json não puder ser lido
     */
    public static function tweakKeys(?string $dir = null): array
    {
        return array_map(static fn (array $t): string => $t['key'], self::tweaks($dir));
    }

    /**
     * Os presets do preset.json, cada um reduzido às chaves que existem no tweaks.json.
     *
     * Recebe, opcionalmente, outra pasta de config. Devolve o nome do preset
     * em minúsculas, como a WinAction o aceita, apontando para a lista de
     * chaves na ordem do preset.json.
     *
     * @return array<string, list<string>>
     *
     * @throws RuntimeException se um dos dois arquivos não puder ser lido
     */
    public static function presets(?string $dir = null): array
    {
        $dados = self::json($dir, 'preset');

        if (!is_array($dados) || array_is_list($dados)) {
            throw new RuntimeException('preset.json não é um mapa de presets.');
        }

        $existentes = array_flip(self::tweakKeys($dir));
        $presets    = [];

        foreach ($dados as $nome => $lista) {
            if (!is_string($nome) || !is_array($lista)) {
                continue;
            }

            $chaves = [];

            foreach ($lista as $chave) {
                if (is_string($chave) && isset($existentes[$chave])) {
                    $chaves[] = $chave;
                }
            }

            $presets[strtolower($nome)] = $chaves;
        }

        return $presets;
    }

    /**
     * O nome do preset que uma seleção de tweaks reproduz.
     *
     * Recebe as chaves marcadas e os presets já reduzidos por presets().
     * Devolve o nome do preset cujo conjunto é igual ao da seleção, "custom"
     * quando nenhum é, e string vazia para seleção vazia.
     *
     * @param list<string>                $selecao
     * @param array<string, list<string>> $presets
     */
    public static function matchPreset(array $selecao, array $presets): string
    {
        if ($selecao === []) {
            return '';
        }

        $alvo = array_values(array_unique($selecao));
        sort($alvo);

        foreach ($presets as $nome => $chaves) {
            $conjunto = array_values(array_unique($chaves));
            sort($conjunto);

            if ($conjunto === $alvo) {
                return $nome;
            }
        }

        return 'custom';
    }

    /**
     * Escapa os caracteres de controle crus que aparecem dentro de strings JSON.
     *
     * Recebe o texto do arquivo. Devolve o mesmo texto com cada byte abaixo de
     * 0x20 que está dentro de uma string trocado pelo escape \uXXXX; fora das
     * strings, nada muda.
     */
    public static function escapeControlChars(string $texto): string
    {
        $saida    = '';
        $emString = false;
        $escape   = false;
        $n        = strlen($texto);

        for ($i = 0; $i < $n; $i++) {
            $c = $texto[$i];

            if ($emString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $emString = false;
                } elseif (ord($c) < 0x20) {
                    $saida .= sprintf('\\u%04x', ord($c));

                    continue;
                }
            } elseif ($c === '"') {
                $emString = true;
            }

            $saida .= $c;
        }

        return $saida;
    }

    /**
     * Lê e decodifica um JSON de config pelo nome.
     *
     * Recebe a pasta (ou null para src/Win/config) e o nome sem extensão.
     * Devolve o valor decodificado.
     *
     * @throws RuntimeException se o arquivo faltar ou não for JSON válido
     */
    private static function json(?string $dir, string $nome): mixed
    {
        $arquivo = ($dir ?? __DIR__ . DIRECTORY_SEPARATOR . 'config') . DIRECTORY_SEPARATOR . $nome . '.json';

        if (!is_file($arquivo)) {
            throw new RuntimeException("config ausente em src/Win/config: {$nome}.json");
        }

        $texto = (string) file_get_contents($arquivo);

        if (str_starts_with($texto, "\xEF\xBB\xBF")) {
            $texto = substr($texto, 3);
        }

        $dados = json_decode(self::escapeControlChars($texto), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("{$nome}.json não é JSON válido: " . json_last_error_msg());
        }

        return $dados;
    }
}
