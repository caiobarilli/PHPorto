<?php

declare(strict_types=1);

use App\Exceptions\DuplicateEntryException;
use App\Providers\MySQLProvider;

/**
 * Testes de integração do MySQLProvider contra um MySQL de verdade.
 *
 * Sem infraestrutura, sem credencial versionada: a conexão vem das variáveis
 * de ambiente MYSQL_TEST_*, documentadas no .env.example. Se elas não
 * estiverem definidas — ou se o servidor não responder — todos os testes
 * deste arquivo são PULADOS com mensagem clara, em vez de falhar. A suíte
 * segue verde numa máquina sem MySQL.
 *
 * Ler getenv() aqui é exceção deliberada à regra "só o Config lê env": estas
 * são variáveis do arranjo de teste, não configuração da aplicação. O bootstrap
 * do PHPUnit é o autoload do Composer, não o bootstrap.php, então o phpdotenv
 * não roda nos testes e o .env NÃO é lido — exporte as variáveis no ambiente.
 *
 * Usa uma TABELA ISOLADA ('entries_test'), criada pelo próprio provider e
 * dropada após cada teste — nunca a tabela de MYSQL_TABLE.
 */

const TEST_TABLE = 'entries_test';

function mysqlTestEnv(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

/**
 * Config do MySQL de teste, vinda do ambiente.
 *
 * @return array{host: string, port: string, database: string, user: string, password: string, table: string}
 */
function mysqlTestConfig(): array
{
    return [
        'host'     => mysqlTestEnv('MYSQL_TEST_HOST', '127.0.0.1'),
        'port'     => mysqlTestEnv('MYSQL_TEST_PORT', '3306'),
        'database' => mysqlTestEnv('MYSQL_TEST_DATABASE'),
        'user'     => mysqlTestEnv('MYSQL_TEST_USER'),
        'password' => mysqlTestEnv('MYSQL_TEST_PASSWORD'),
        'table'    => TEST_TABLE,
    ];
}

/** Conexão crua para checagem de disponibilidade e limpeza. */
function rawPdo(): PDO
{
    $c = mysqlTestConfig();

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']),
        $c['user'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
    );
}

beforeEach(function () {
    $c = mysqlTestConfig();

    if ($c['database'] === '' || $c['user'] === '') {
        $this->markTestSkipped(
            'MySQL de teste não configurado — exporte MYSQL_TEST_DATABASE e '
            . 'MYSQL_TEST_USER (ver .env.example).'
        );
    }

    try {
        // Descarte DELIBERADO do PDO: só queremos saber se a conexão abre.
        // Se rawPdo() um dia ganhar #[\NoDiscard], esta linha precisa de (void).
        rawPdo();
    } catch (PDOException $e) {
        $this->markTestSkipped('MySQL de teste inacessível: ' . $e->getMessage());
    }
});

afterEach(function () {
    try {
        rawPdo()->exec('DROP TABLE IF EXISTS `' . TEST_TABLE . '`');
    } catch (PDOException) {
        // Sem conexão: nada a limpar.
    }
});

it('cria a tabela e insere um registro', function () {
    $provider = new MySQLProvider(mysqlTestConfig());

    $provider->insertEntry('primeiro');

    expect($provider->entryExists('primeiro'))->toBeTrue();
});

it('reporta entryExists=false para valor ausente', function () {
    $provider = new MySQLProvider(mysqlTestConfig());

    expect($provider->entryExists('nao-existe'))->toBeFalse();
});

it('lança DuplicateEntryException ao inserir valor repetido', function () {
    $provider = new MySQLProvider(mysqlTestConfig());
    $provider->insertEntry('duplicado');

    $provider->insertEntry('duplicado');
})->throws(DuplicateEntryException::class);

it('findAll devolve os registros com created_at em ISO 8601', function () {
    $provider = new MySQLProvider(mysqlTestConfig());
    $provider->insertEntry('um');
    $provider->insertEntry('dois');

    $rows    = $provider->findAll();
    $entries = array_column($rows, 'entry');

    expect($rows)->toHaveCount(2)
        ->and($entries)->toContain('um')
        ->and($entries)->toContain('dois')
        ->and($rows[0]['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});
