<?php

declare(strict_types=1);

$dll    = $argv[1] ?? '';
$libDir = $argv[2] ?? '';
$fonte  = $argv[3] ?? '';
$codigo = is_file($fonte) ? file_get_contents($fonte) : false;

if (!extension_loaded('ffi') || !is_file($dll) || !is_string($codigo)) {
    fwrite(STDERR, "[phporto] sem FFI, sem libtcc ou sem o código.\n");
    exit(125);
}

$tcc = FFI::cdef(
    'typedef struct TCCState TCCState;
    TCCState *tcc_new(void);
    void tcc_delete(TCCState *s);
    void tcc_set_lib_path(TCCState *s, const char *path);
    int tcc_set_output_type(TCCState *s, int output_type);
    int tcc_compile_string(TCCState *s, const char *buf);
    int tcc_run(TCCState *s, int argc, char **argv);',
    $dll,
);

$crt = FFI::cdef('int fflush(void *stream);', PHP_OS_FAMILY === 'Windows' ? 'msvcrt.dll' : null);

$chama = static fn (FFI $lib, string $nome, mixed ...$args): mixed => $lib->{$nome}(...$args);

$estado = $chama($tcc, 'tcc_new');

if ($estado === null) {
    fwrite(STDERR, "[phporto] tcc_new falhou.\n");
    exit(125);
}

$chama($tcc, 'tcc_set_lib_path', $estado, $libDir);
$chama($tcc, 'tcc_set_output_type', $estado, 1);

if ($chama($tcc, 'tcc_compile_string', $estado, $codigo) !== 0) {
    $chama($crt, 'fflush', null);
    fwrite(STDERR, "[phporto] a compilação falhou.\n");
    $chama($tcc, 'tcc_delete', $estado);
    exit(125);
}

$saida = $chama($tcc, 'tcc_run', $estado, 0, null);
$chama($crt, 'fflush', null);
$chama($tcc, 'tcc_delete', $estado);

exit(is_int($saida) ? $saida : 125);
