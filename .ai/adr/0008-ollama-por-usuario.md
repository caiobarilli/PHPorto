# Ollama no Windows por usuário

## Contexto

O PHParrot precisa de modelos locais para gerar textos por tema e nível CEFR em JSON, em inglês e português, e no futuro traduzir para es, fr, de, it e ru. O Ollama foi removido do desktop do Maintainer, então a instalação parte do zero. A GPU é uma NVIDIA GeForce GTX 1650 com 4 GB GDDR6. O Ollama não tem autenticação: quem alcança a porta lista, baixa e apaga modelos e usa a GPU. O worker roda como Administrador, e o instalador oficial instala no perfil de quem o executa, sem pedir admin.

## Decisão

Ação nova `ollama` com as subações `status`, `list`, `install`, `uninstall`, `start`, `stop`, `pull` e `remove`. Nenhum campo livre: modelos e subações vêm de listas fechadas.

- Canal novo não elevado: o PHP gera scripts fixos a partir de constantes e roda com `PsRunner`, no molde de `HypervGate::checkBody`. Não passa pelo worker elevado nem pelo `OneShot`. O PHP recusa rodar se o próprio processo estiver com nível de integridade High (elevado).
- Versão, SHA-256 do instalador e allowlist de modelos ficam em constantes no PHP, e não no config do worker nem no manifesto. Nada desse canal roda elevado, então adulteração pelo mesmo usuário não dá ganho de privilégio.
- `install`: o PHP baixa o `OllamaSetup.exe` de uma tag fixa das releases oficiais (`ollama/ollama`), confere o SHA-256 no PHP e de novo com `Get-FileHash`, e executa como usuário. Divergência recusa. Trocar a versão exige commit novo. Nada de `latest`.
- `uninstall` roda o desinstalador registrado no perfil do usuário. Apagar os modelos pede confirmação à parte.
- `start` e `stop` mexem só em processos do usuário. `status`, `list`, `pull` e `remove` usam HTTP em `127.0.0.1:11434`. `status` mostra versão, processo e GPU/VRAM por `nvidia-smi --query-gpu=name,memory.total,memory.used --format=csv`. `Win32_VideoController.AdapterRAM` é `uint32` e não passa de 4 GB, então fica só como fallback marcado como aproximado.
- `pull` e `remove` usam o nome do modelo saído da allowlist. `pull` roda destacado e o progresso vem por polling.
- Allowlist da fase 1, com tags fixas e quantização Q4_K_M:
  - `qwen3:4b` (2.5 GB): o principal, com mais de 100 línguas, tradução incluída, segundo https://ollama.com/library/qwen3.
  - `llama3.2:3b` (2.0 GB): o alternativo, com en, de, fr, it, pt, hi, es e th oficiais, sem ru, segundo https://ollama.com/library/llama3.2.
  - Modelos de 8b para cima ficam de fora porque não cabem com folga em 4 GB.
- O Ollama fica no bind padrão, `127.0.0.1:11434`, sem `OLLAMA_HOST`. Sem exposição à LAN.
- O PHParrot só consome. Ele chama `GET /api/tags`, mostra o status e, quando o Ollama falta, um texto de instalação que aponta para o PHPorto ou para https://ollama.com. Roda no Docker Desktop com WSL2, na rede `phparrot-network`.
- Teste de aceitação:
  1. Ollama no Windows escutando só em loopback.
  2. `docker exec phparrot-php php -r "echo file_get_contents('http://host.docker.internal:11434/api/tags');"`.
  3. Se responder, esse é o caminho, sem expor porta.
  4. Se não responder, a alternativa mais restrita é `OLLAMA_HOST` no IP da interface do Docker Desktop, com uma regra de firewall de entrada TCP 11434 só para essa sub-rede. A regra é a subação `firewall`, sensível e com UAC de uso único (ADR 0002). Cabe ao Maintainer decidir.
- WSL `networkingMode=mirrored` só entra como alternativa se o teste falhar e se não afetar o Docker Desktop.

## Consequência

Risco residual: o canal não elevado não tem manifesto nem pasta protegida. Qualquer processo do usuário pode trocar o PHP, as constantes ou o instalador baixado antes da execução, mas só ganha o que já tinha: o mesmo usuário. Se o PHP rodar elevado por engano, a recusa por nível de integridade é a única barreira.

Instalar, atualizar e remover não pedem UAC. Só a regra de firewall pede, e só se o teste falhar. Uma atualização do Ollama passa por commit, porque o hash fica no manifesto. Com bind fora do loopback, a API fica aberta a tudo o que alcança a sub-rede, sem senha. O IP da interface muda e pode quebrar a configuração. LAN, AMD/ROCm, Intel, Ollama como serviço do Windows, modelos fora da allowlist e mudanças no PHParrot ficam fora.

Não verificado:

- Flags silenciosas do instalador.
- Assinante Authenticode esperado.
- Como desligar a atualização automática do app de bandeja.
- Como desligar o raciocínio do `qwen3` em respostas JSON.
- Se `host.docker.internal` no Docker Desktop alcança um serviço preso em `127.0.0.1` do Windows.
- Folga de contexto/KV cache em 4 GB.

## Commit

`db87f17`

## PR

`#57` (`db50a3d`)
