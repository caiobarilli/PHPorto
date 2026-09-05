<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bootstrap dos testes
|--------------------------------------------------------------------------
|
| Os testes usam o autoload do Composer (classes de produção em App\* e as
| do namespace Tests\* via autoload-dev). Não estendemos nenhuma TestCase
| customizada — os testes de unidade são puros e os de integração gerenciam
| a própria conexão.
|
*/

uses()->in('Unit', 'Integration');
