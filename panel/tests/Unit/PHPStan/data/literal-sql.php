<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan\Data;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

function literalSql(Builder $query, ConnectionInterface $connection, string $input, bool $descending): void
{
    // Literals, choices of literals and bindings are fine.
    $query->selectRaw('count(*) as servers');
    $query->orderByRaw('node_id = ? desc', [$input]);
    $query->orderByRaw($descending ? 'id desc' : 'id asc');
    $query->whereRaw('status = '.'\'active\'');
    $query->whereRaw(DB::raw('1 = 1'));
    DB::select('select * from servers where uuid = ?', [$input]);
    $connection->statement('select 1');
    $query->select($input);
    new Expression('now()');

    // Text built from a variable is not.
    $query->selectRaw("count({$input})");
    $query->whereRaw('uuid = '.$input);
    $query->orderByRaw($input);
    DB::raw($input);
    DB::statement('drop table '.$input);
    DB::unprepared($input);
    $connection->select($input);
    new Expression($input);
}
