<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * SQL handed to Laravel as text must be a literal: a string (or a choice of
 * strings) PHPStan knows when analysing, never one built from a variable.
 * Values go in as `?` bindings, so nothing a user typed can become SQL.
 *
 * Checked: the query builder's `*Raw()` methods and `raw()` on any object,
 * the connection's methods taking SQL (`select`, `statement`, `unprepared`…)
 * on a connection or the `DB` facade, and `new Expression()`.
 *
 * @implements Rule<CallLike>
 */
class LiteralSqlRule implements Rule
{
    /** Builder methods whose first argument is SQL, whatever the receiver. */
    private const RAW_METHODS = [
        'raw', 'selectraw', 'fromraw', 'whereraw', 'orwhereraw', 'groupbyraw',
        'havingraw', 'orhavingraw', 'orderbyraw',
    ];

    /** Connection methods whose first argument is SQL. */
    private const CONNECTION_METHODS = [
        'select', 'selectone', 'scalar', 'selectfromwriteconnection', 'selectresultsets',
        'cursor', 'insert', 'update', 'delete', 'statement', 'affectingstatement', 'unprepared',
    ];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $this->sqlCall($node, $scope);

        if ($method === null || $node->isFirstClassCallable()) {
            return [];
        }

        $argument = $node->getArgs()[0] ?? null;

        if ($argument === null || $argument->unpack) {
            return [];
        }

        $type = $scope->getType($argument->value);

        if ($type->getConstantStrings() !== [] && $type->isConstantScalarValue()->yes()) {
            return [];
        }

        // An expression was checked where it was made.
        if ((new ObjectType(ExpressionContract::class))->isSuperTypeOf($type)->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'SQL passed to %s must be a literal string; bind values with ? placeholders instead.',
                $method,
            ))->identifier('proxmotion.literalSql')->build(),
        ];
    }

    /**
     * The name of the call when its first argument is SQL, else null.
     */
    private function sqlCall(CallLike $node, Scope $scope): ?string
    {
        if ($node instanceof New_) {
            return $node->class instanceof Name && $scope->resolveName($node->class) === Expression::class
                ? 'new Expression()'
                : null;
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier) {
            $name = $node->name->toLowerString();

            if (in_array($name, self::RAW_METHODS, true)) {
                return $node->name->toString().'()';
            }

            if (in_array($name, self::CONNECTION_METHODS, true)
                && (new ObjectType(ConnectionInterface::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
                return $node->name->toString().'()';
            }

            return null;
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier
            && $scope->resolveName($node->class) === DB::class) {
            $name = $node->name->toLowerString();

            return in_array($name, self::RAW_METHODS, true) || in_array($name, self::CONNECTION_METHODS, true)
                ? 'DB::'.$node->name->toString().'()'
                : null;
        }

        return null;
    }
}
