# Código de terceiros

O PHPorto é MIT ([LICENSE](../LICENSE)). A tela `/win` executa ações que
carregam código de terceiros, todo em `src/Win/`. O inventário completo, com os
hashes, está em [src/Win/THIRD-PARTY.md](../src/Win/THIRD-PARTY.md); este
documento é o resumo.

## WinUtil, de Chris Titus Tech

- **Origem:** <https://github.com/ChrisTitusTech/winutil>, chegando aqui pelo
  `winutil-cli`, um fork sem interface gráfica que o PHPorto absorveu.
- **Licença:** MIT, © 2022 CT Tech Group LLC. Texto íntegro em
  [src/Win/LICENSE.winutil](../src/Win/LICENSE.winutil).

O que veio do upstream:

| onde | o quê |
| --- | --- |
| `src/Win/lib/` | doze funções PowerShell: winget (instalação e verificação), aplicação de tweaks e dos scripts deles, registro, serviços, DNS, remoção de APPX, remoção do Edge, atualização do Explorer e tema |
| `src/Win/config/` | `dns.json`, `preset.json` e `tweaks.json` |

Esses arquivos são copiados byte a byte, e o `THIRD-PARTY.md` traz o SHA-256 de
cada um para a cópia ser conferível:

```bash
sha256sum src/Win/lib/*.ps1 src/Win/config/{dns,preset,tweaks}.json
```

Os hashes são dos bytes CRLF do checkout do upstream, e o `.gitattributes`
mantém esses arquivos com `-text` para os hashes valerem em qualquer clone.

Funções e JSON do upstream sem chamador no caminho das ações não foram trazidos.

## Divergências registradas

Três arquivos de `src/Win/lib/` foram editados e não batem com a origem. O
`THIRD-PARTY.md` traz, para cada um, o hash da origem ao lado do nosso e o
motivo:

| arquivo | o que mudou |
| --- | --- |
| `Install-WinUtilProgramWinget.ps1` | instala um pacote por vez, por `--id` com `--exact`, aceita os termos da fonte, desliga a interatividade e devolve o código de saída de cada pacote — na origem o winget podia parar para perguntar dentro do processo elevado, onde ninguém responde |
| `Invoke-WinUtilExplorerUpdate.ps1` | o aviso ao shell roda síncrono, sem o pool de runspaces da janela do WinUtil, que não existe aqui |
| `Invoke-WinutilThemeChange.ps1` | não faz nada, com a mesma assinatura: na origem ela repinta a janela do WinUtil |

O `tweaks.json` do upstream também traz uma lacuna de dados conhecida: o
`WPFTweaksDVR`, citado pelos presets, não existe no arquivo. Ficou como está.

## Código do projeto que veio do winutil-cli

`src/Win/actions/` (as treze ações) e `src/Win/audit/audit.ps1` vieram do
`winutil-cli`, mas são de autoria própria, não do upstream, e não têm hash
declarado. Foram editados depois da migração — para não perguntar nada dentro do
processo elevado, para não relatar sucesso do que não aconteceu, e para aceitar
listas de itens na tela. O `THIRD-PARTY.md` lista cada edição.

Também são do projeto `src/Win/config/debloat.json`, a lista de pacotes do
Remover apps, e `src/Win/config/tweaks.pt-BR.json`, a tradução dos tweaks.

## Binários baixados

Nada de binário é versionado. Cada um é baixado na primeira execução da ação que
o usa:

| binário | ação | origem |
| --- | --- | --- |
| `WinMemoryCleaner.exe` | Memória | [IgorMundstein/WinMemoryCleaner](https://github.com/IgorMundstein/WinMemoryCleaner) |
| `windows_exporter` | Métricas do Windows | [prometheus-community/windows_exporter](https://github.com/prometheus-community/windows_exporter) |
| `nvidia_gpu_exporter` | Métricas da GPU | [utkuozdemir/nvidia_gpu_exporter](https://github.com/utkuozdemir/nvidia_gpu_exporter) |
| Wireshark / `tshark` | Captura de rede, via winget | [Wireshark Foundation](https://www.wireshark.org/) |
