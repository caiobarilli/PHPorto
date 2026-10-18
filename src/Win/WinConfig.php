<?php

declare(strict_types=1);

namespace App\Win;

use RuntimeException;

final class WinConfig
{
    public const NOT_TWEAK_TYPES = ['Button', 'Combobox'];

    /**
     * @return list<string>
     * @throws RuntimeException
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
     * @return list<string>
     * @throws RuntimeException
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
     * @return list<array{key: string, content: string, description: string, category: string, caution: bool, explorer: bool}>
     * @throws RuntimeException
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

    public const TWEAKS_TRANSLATION = 'tweaks.pt-BR';

    /**
     * @param list<array{key: string, content: string, description: string, category: string, caution: bool, explorer: bool}> $tweaks
     * @return list<array{key: string, content: string, description: string, category: string, caution: bool, explorer: bool}>
     */
    public static function translateTweaks(array $tweaks, ?string $dir = null): array
    {
        try {
            $dados = self::json($dir, self::TWEAKS_TRANSLATION);
        } catch (RuntimeException) {
            return $tweaks;
        }

        $categorias = is_array($dados) && is_array($dados['categorias'] ?? null) ? $dados['categorias'] : [];
        $textos     = is_array($dados) && is_array($dados['tweaks'] ?? null) ? $dados['tweaks'] : [];

        foreach ($tweaks as $i => $tweak) {
            $texto = $textos[$tweak['key']] ?? null;

            if (is_array($texto) && is_string($texto['content'] ?? null) && $texto['content'] !== '') {
                $tweaks[$i]['content'] = $texto['content'];
            }

            if (is_array($texto) && is_string($texto['description'] ?? null) && $texto['description'] !== '') {
                $tweaks[$i]['description'] = $texto['description'];
            }

            $categoria = $categorias[$tweak['category']] ?? null;

            if (is_string($categoria) && $categoria !== '') {
                $tweaks[$i]['category'] = $categoria;
            }
        }

        return $tweaks;
    }

    /**
     * @return list<string>
     * @throws RuntimeException
     */
    public static function tweakKeys(?string $dir = null): array
    {
        return array_map(static fn (array $t): string => $t['key'], self::tweaks($dir));
    }

    /**
     * @return array<string, list<string>>
     * @throws RuntimeException
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
     * @param list<string> $selecao
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
     * @throws RuntimeException
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
