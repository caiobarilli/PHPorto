# Pitfalls

Só o que o git ou o código desta árvore mostram. SHA e `arquivo:linha` da main medida (`ca29ec3`).

## `collect()` sem id lia o `win-done` de outra ação

Sem id, `findDone(null)` andava o glob `win-done-*.json` e pegava o primeiro válido. Na pasta protegida a limpeza podia falhar calada; o primeiro da lista era de outra ação (ex.: `processes`), e a `/hyperv` mostrava "não voltou no formato esperado" com o JSON certo do hyperv na mesma pasta.

Evidência: mensagem de `ac0a197` (PR #11); revert `0adb1ac` (PR #12); restaura `dc359bd` (PR #14). Código atual: `src/Win/JobChannel.php:142` (`collect` sem id), `src/Win/JobChannel.php:360` (`findDone(?string $id = null)`). O conserto anota os ids já presentes no `send()` e, sem id, ignora esses e só aceita a ação pedida (`src/Win/JobChannel.php:370`).

## `sed -i` em `.ps1` (CRLF + BOM)

`sed -i` do Git Bash devolve LF. `*.ps1` tem `-text` no `.gitattributes`: o LF entra no repositório e o diff some a mudança real. O 5.1 ainda precisa de BOM UTF-8.

Evidência: `tests/Pester/Worker.Tests.ps1:172`; `docs/desenvolvimento.md:125`.

## `AuthHttpTest` sobe `php -S`

`tests/Integration/AuthHttpTest.php:18` chama `PhpServer::start`. `tests/Support/PhpServer.php:54` dispara `php -S 127.0.0.1:<porta>`. O portão sobe e derruba esse servidor de teste; não é o `php -S` do Maintainer.

## Cache do PHPStan

`phpstan.neon:15` grava em `.phpstan-cache/`; `.gitignore:44` ignora a pasta. No log (`git log -S phpstan-cache`) só aparece a criação do ignore. Não achei evidência de vazamento de segredo por esse cache.

Segredo no sem-reload (outra coisa): `8d05931` limpa campos de senha e `[data-segredo]` na troca do miolo.

## Manifesto: religar o elevado depois de mudar `.ps1`

O mapa SHA-256 é tirado ao ligar. Arquivo editado com o worker de pé não entra no mapa; a ação é recusada.

Evidência: `docs/windows.md:22`; desenho em `8954661`.

## PR #3 merge em base já deletada

Não está no log. Os merges medidos saltam de `#2` (`9321917`) para `#4` (`ea0dd72`). Sem evidência, não entra como pitfall.

## Aspa curva no worker

PR #1 (`a50824b`): parâmetro como dado; aspa tipográfica deixava de ser dado e virava código. Por isso a proibição de aspas tipográficas e travessão em string de `.ps1`.

## USERDOMAIN não é a identidade

Numa máquina sem domínio, `$env:USERDOMAIN` pode dizer `WORKGROUP` enquanto `whoami` e `WindowsIdentity.GetCurrent().Name` dizem `DESKTOP\usuario` (medido no desktop do Maintainer, também elevado). Montar o nome com `USERDOMAIN` e `USERNAME` fazia o `php phporto autostart on` recusar sempre a conta.

A identidade vem do token do processo, não do ambiente: `App\Autostart\Identity` lê o SID com `whoami /user`, e o script elevado compara com `GetCurrent().User.Value`.

Evidência: `tests/Unit/AutostartIdentityTest.php`; `tests/Pester/Invoke-Autostart.Tests.ps1`.
## php -S não define SERVER_ADDR

O servidor embutido define `REMOTE_ADDR`, `SERVER_NAME` (o endereço do `-S`), `SERVER_PORT` e `HTTP_HOST`, mas não `SERVER_ADDR`. O `State::accepts` exigia `SERVER_ADDR`, e todo acesso pela rede levava 403. Os testes não pegaram porque montavam o `$_SERVER` à mão.

Medido num `php -S` real: com `Host` forjado pelo cliente, o `SERVER_NAME` continua sendo o endereço de escuta. Por isso o endereço de escuta é `SERVER_ADDR` quando existe e, senão, `SERVER_NAME`, só valendo se for IPv4 igual ao `bind_ip`.

Evidência: `tests/Integration/ServerVarsTest.php` (SAPI real); `State::listenerAddress`.
