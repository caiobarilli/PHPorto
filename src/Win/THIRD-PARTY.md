# Código de terceiros em `src/Win/`

Esta pasta carrega código que **não é do PHPorto**. Está aqui porque a tela
`/win` executa as ações do Windows a partir do próprio repositório, sem
depender de um projeto externo.

Três origens convivem aqui, e a diferença entre elas é o que decide o que pode
ser editado.

---

## 1. WinUtil — ChrisTitusTech

**Licença:** MIT, texto íntegro em [`LICENSE.winutil`](LICENSE.winutil).
**Copyright:** © 2022 CT Tech Group LLC.
**Origem:** <https://github.com/ChrisTitusTech/winutil>, chegando aqui pelo
`winutil-cli` — o fork sem interface gráfica que este projeto absorve e que
deixa de existir ao fim da migração.

A cláusula que obriga é a segunda do MIT: *"The above copyright notice and this
permission notice shall be included in all copies or substantial portions of the
Software."* É o que `LICENSE.winutil` cumpre — o MIT pede o aviso na
distribuição, não um cabeçalho em cada arquivo, e por isso os arquivos abaixo
**não** ganharam cabeçalho nosso.

### Arquivos, e a garantia de que estão intocados

Copiados **byte a byte**. Os hashes existem para isso ser verificável, não
declarado: qualquer edição, inclusive um fim de linha trocado, muda o valor.

```
sha256sum src/Win/lib/*.ps1 src/Win/config/{dns,preset,tweaks}.json
```

| Arquivo | SHA-256 |
|---|---|
| `lib/Install-WinUtilProgramWinget.ps1` | `6f6c8c7dcc89e18140ac23c8c6eae13506152726a2b0eb9c0ef4f6f8e79d7740` |
| `lib/Install-WinUtilWinget.ps1` | `c97374d0d64ccd597c34407a7c0aa7e7b7efcb2c8a4b4a85261046c4ab7da499` |
| `lib/Invoke-WinUtilScript.ps1` | `e9ab2080f858f2e9cabcbda4df75e3df5404eed30b8746b6b2d0f2d8b461c460` |
| `lib/Invoke-WinUtilTweaks.ps1` | `5f84ba82f9cc6ac4942d99632c7b7a500aadd6a512d8d36cb613dce169c0bfb1` |
| `lib/Remove-WinUtilAPPX.ps1` | `d027630815b72b45cd28a46e2984b990f9d31b98e94bb6ea600e24756bee5dcb` |
| `lib/Set-WinUtilDNS.ps1` | `6f1c00d766d2080bb24128d03d0c3f8619bafcf5a6d01043dbd2f3a86e9970cd` |
| `lib/Set-WinUtilRegistry.ps1` | `1c861fb3b5cef35193667134c28fb9357aa27d41f6ec30994be61d2bfc709ea9` |
| `lib/Set-WinUtilService.ps1` | `4cc984238de200e0728f52e772a33a8d6328ebd6d32cbf980531f5075368b2e9` |
| `lib/Test-WinUtilPackageManager.ps1` | `8a84508ddb17d2a6bae8185ce393f6ceb4fbf98e19885ce11ec8c701413789b6` |
| `config/dns.json` | `10be9e7a1e0655ebd47463f79103eb25ca63a3898f4dc414d68a3d77d21b1343` |
| `config/preset.json` | `ba264fc2916c0315bee7cacd60dd1d08b4770153f09bcd86ca7f13cd95e19dc1` |
| `config/tweaks.json` | `e96d41745d31e323cd0d3d0eab188a5ab08cf00148e99674d3d261b10e2d1ae9` |

### Por que só nove funções, e não dezessete

O `functions/private/` do winutil-cli tem dezessete. Oito delas **não têm
chamador nenhum** no caminho das ações — só apareciam em `main.ps1`, que o
ponto de entrada daquele projeto nunca chegava a carregar, porque o filtro de
carga era `Invoke-*.ps1`. Ficaram lá: `Install-WinUtilChoco`,
`Install-WinUtilProgramChoco`, `Invoke-WinUtilFeatureInstall`,
`Invoke-WinUtilInstallPSProfile`, `Invoke-WinUtilUninstallPSProfile`,
`Invoke-WinUtilSSHServer`, `Invoke-WinUtilRemoveEdge` e `Show-CTTLogo`.

Migrar código morto é herdar manutenção de graça, e uma licença de terceiro a
carregar por algo que ninguém executa.

### Por que três JSON, e não cinco

`applications.json` (74 KB) não tem leitor nenhum, e `feature.json` (11 KB) só
é lido por `Invoke-WinUtilFeatureInstall`, que também não tem chamador. São
86 KB que ninguém abre. Do upstream, `$sync.configs` tem três chaves em vez de
cinco; a quarta, `debloat`, é do projeto — ver a seção 2.

### Por que estes arquivos não têm BOM

O `.gitattributes` protege o CRLF+BOM dos `.ps1` porque o PowerShell 5.1 lê
arquivo sem BOM como ANSI. Esses nove **são ASCII puro** — medido, zero bytes
acima de `0x7F` —, então a leitura como ANSI não tem o que estragar, e o BOM
seria uma edição sem ganho que quebraria a comparação com a origem.

Isso vale só para eles. Todo `.ps1` escrito aqui — `worker.ps1`,
`bootstrap.ps1` — é CRLF **com** BOM, e o porquê, com a armadilha do travessão
junto, está no docblock do `bootstrap.ps1`.

---

## 2. winutil-cli — autoria do projeto

`audit/audit.ps1` e os treze `actions/Invoke-*.ps1` vêm do winutil-cli mas são
de autoria própria, não do upstream. Não carregam a obrigação do MIT da CT Tech
Group, e por isso não têm hash declarado aqui: não há origem com que comparar.

Migraram em inglês e **dez dos treze sem uma linha de edição**, para que o diff
diga "mudou de endereço" e nada mais.

### As três exceções, e por que existem

Três arquivos perguntavam. Dentro do worker elevado não há ninguém para
digitar: `Read-Host` num processo `-NonInteractive` falha com um erro que não
explica nada a quem clicou, ou pendura a execução até o timeout.

| Arquivo | Linha na origem | O que saiu |
|---|---|---|
| `Invoke-Network.ps1` | 44 | o `Read-Host` da interface de captura |
| `Invoke-Exporter.ps1` | 190 | o submenu de subação inteiro |
| `Invoke-GPU.ps1` | 223 | o submenu de subação inteiro |

Nos três, o diff é só isso. O `Invoke-Network` já tinha, logo abaixo do
`Read-Host`, o `Write-Status ERROR` com `return` para interface vazia — bastou
tirar a pergunta e deixar a recusa que já existia. Os dois submenus viraram as
mesmas duas linhas de recusa que o `Invoke-Gdid` já usava.

**Nenhum ganhou `[Parameter(Mandatory)]`**, e isso é deliberado: parâmetro
obrigatório ausente faz o PowerShell **perguntar** num host interativo — seria
recolocar o prompt que acabou de sair, e num host não interativo a mensagem é
pior que a nossa. Quem torna o parâmetro obrigatório são as duas allowlists: a
`WinAction` do lado PHP e o `$ALLOWLIST` do worker já recusam `network` sem
`Interface` e `exporter`/`gpu` sem `SubAction`.

### Editado depois da migração

| Arquivo | O que mudou |
|---|---|
| `Invoke-Debloat.ps1` | a lista dos 22 pacotes saiu do corpo da função para `config/debloat.json`, que a tela `/win` também lê |

`config/debloat.json` é, portanto, do projeto e não do upstream: fica fora da
tabela de hashes da seção 1, e pode ser editado.

### Uma divergência conhecida da regra de encoding

`Invoke-Gdid.ps1` e `Invoke-Optimize.ps1` carregam bytes acima de `0x7F` e
**não têm BOM** — são os únicos `.ps1` daqui nessa situação.

Medido: em ambos, esses bytes estão **só em comentário** (caixas de `─` e
travessões), e os dois parseiam sem erro. O `Invoke-Optimize` já contornava a
falta de BOM à mão, montando as palavras acentuadas com `[char]0x00E9` em vez
de escrevê-las: os textos comparados com o `query session` são construídos, não
literais.

Ficaram sem BOM porque pôr um é mudar bytes num commit que só muda endereço. É
tripwire, não conserto: o teste que parseia todo `Invoke-*.ps1` falha na hora em
que alguém escrever um travessão dentro de string num desses dois arquivos —
que é exatamente o acidente descrito no `bootstrap.ps1`. Pôr o BOM nos dois é
commit próprio, de uma linha cada.

---

## 3. Binários que não estão aqui

Nada de binário é versionado. Cada um é baixado na primeira execução da ação
que precisa dele, e o `.gitignore` cobre o destino:

| Binário | Quem baixa | De onde |
|---|---|---|
| `WinMemoryCleaner.exe` | ação `memory`, em `src/Win/tools/` | [IgorMundstein/WinMemoryCleaner](https://github.com/IgorMundstein/WinMemoryCleaner) |
| `windows_exporter` | ação `exporter` | [prometheus-community/windows_exporter](https://github.com/prometheus-community/windows_exporter) |
| `nvidia_gpu_exporter` | ação `gpu` | [utkuozdemir/nvidia_gpu_exporter](https://github.com/utkuozdemir/nvidia_gpu_exporter) |
| Wireshark / `tshark` | ação `network`, via winget | [Wireshark Foundation](https://www.wireshark.org/) |

O `winutil-cli` já mantinha o `WinMemoryCleaner.exe` no `.gitignore`. A decisão
"baixa na primeira execução" não é nova; só não foi desfeita.
