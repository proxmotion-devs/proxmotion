<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\PHPStan\LiteralSqlRule;

/**
 * @extends RuleTestCase<LiteralSqlRule>
 */
class LiteralSqlRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new LiteralSqlRule;
    }

    public function test_it_refuses_sql_built_from_variables(): void
    {
        $message = fn (string $call): string => "SQL passed to {$call} must be a literal string; bind values with ? placeholders instead.";

        $this->analyse([__DIR__.'/data/literal-sql.php'], [
            [$message('selectRaw()'), 26],
            [$message('whereRaw()'), 27],
            [$message('orderByRaw()'), 28],
            [$message('DB::raw()'), 29],
            [$message('DB::statement()'), 30],
            [$message('DB::unprepared()'), 31],
            [$message('select()'), 32],
            [$message('new Expression()'), 33],
        ]);
    }
}
