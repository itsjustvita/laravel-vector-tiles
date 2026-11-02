<?php

use ItsJustVita\VectorTiles\Support\ErrorMessage;

it('keeps only the reason of an error message', function (string $message, string $reason) {
    expect(ErrorMessage::reason(new RuntimeException($message)))->toBe($reason);
})->with([
    'english server messages' => [
        "SQLSTATE[42703]: Undefined column: 7 ERROR:  column \"nope\" does not exist\nLINE 1: select \"nope\" from \"roads\" (Connection: pgsql, SQL: select \"nope\" from \"roads\")",
        'column "nope" does not exist',
    ],
    'translated server messages' => [
        "SQLSTATE[42703]: Undefined column: 7 FEHLER:  Spalte »nope« existiert nicht\nLINE 1: select \"nope\" from \"roads\" (Connection: pgsql, SQL: select \"nope\" from \"roads\")",
        'Spalte »nope« existiert nicht',
    ],
    'connection details on the first line' => [
        'SQLSTATE[22012]: Division by zero: 7 ERROR:  division by zero (Connection: pgsql, Host: 127.0.0.1, SQL: select 1 / 0)',
        'division by zero',
    ],
    'messages without sqlstate' => [
        'Database connection [nope] not configured.',
        'Database connection [nope] not configured.',
    ],
]);
