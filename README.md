# PHPorto

Esqueleto PHP com provider de banco plugável: SQLite (padrão), MySQL ou MongoDB,
escolhidos por uma variável de ambiente, sem mudar nenhuma linha de código.

> **Em construção.** A estrutura está de pé; o domínio ainda não.

## Requisitos

PHP 8.5 com as extensões `json`, `pdo`, `pdo_sqlite` e `pdo_mysql`, mais o Composer.

## Instalação

```bash
composer install
```

Copie `.env.example` para `.env` e ajuste se precisar. O provider padrão é o
SQLite — o banco é um arquivo local criado on-demand, então não é preciso subir
nenhum serviço externo.

## Subir localmente

```bash
php -S 127.0.0.1:4001 router.php
```

O `router.php` devolve 404 para caminhos iniciados por ponto — sem ele o servidor
embutido serviria `/.env` e `/.git/config`. Escute sempre em `127.0.0.1`.

## Comandos

```bash
composer test       # suíte Pest
composer stan       # PHPStan
composer cs-check   # PHP-CS-Fixer, sem escrever nada
```
