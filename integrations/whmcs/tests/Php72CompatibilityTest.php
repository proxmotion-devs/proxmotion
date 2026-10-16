<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * WHMCS 8.0 runs on PHP 7.2, so the module must not use newer syntax or
 * functions. The tests run on a newer PHP, so the module's code is checked
 * here (PHPStan's phpVersion does not report e.g. typed properties).
 */
class Php72CompatibilityTest extends TestCase
{
    /** Functions added after PHP 7.2. */
    const NEWER_FUNCTIONS = [
        'array_key_first', 'array_key_last', 'is_countable', 'hrtime', 'net_get_interfaces',
        'str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'get_resource_id', 'fdiv',
        'array_is_list', 'enum_exists', 'mb_str_pad', 'json_validate', 'array_find', 'array_any', 'array_all',
    ];

    public function test_the_module_uses_only_php_7_2()
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('7.2'));
        $finder = new NodeFinder();
        $problems = [];

        foreach ($this->files() as $file) {
            $ast = $this->withParents($parser->parse((string) file_get_contents($file)));
            $name = substr($file, strlen(dirname(__DIR__)) + 1);

            foreach ($finder->find($ast, function (Node $node) {
                return $this->newerThanPhp72($node) !== null;
            }) as $node) {
                $problems[] = $name . ':' . $node->getStartLine() . ' ' . $this->newerThanPhp72($node);
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_the_check_finds_newer_syntax()
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('7.2'));
        $ast = $this->withParents($parser->parse('<?php class A { private int $x; public function f(?A $a) { $a?->f(null); $b ??= 1; $c = $a ?? throw new E(); throw new E(); return str_contains("a", "b"); } }'));
        $found = array_values(array_filter(array_map([$this, 'newerThanPhp72'], (new NodeFinder())->find($ast, function () {
            return true;
        }))));

        $this->assertSame(['typed property', 'nullsafe operator', '??= operator', 'throw expression', 'str_contains()'], $found);
    }

    /**
     * What makes the node newer than PHP 7.2, or null.
     */
    public function newerThanPhp72(Node $node): ?string
    {
        if ($node instanceof Node\Stmt\Property && $node->type !== null) {
            return 'typed property';
        }

        if ($node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\NullsafePropertyFetch) {
            return 'nullsafe operator';
        }

        if ($node instanceof Node\Expr\AssignOp\Coalesce) {
            return '??= operator';
        }

        if ($node instanceof Node\Expr\ArrowFunction) {
            return 'arrow function';
        }

        if ($node instanceof Node\Expr\Match_) {
            return 'match';
        }

        // php-parser marks parents with ParentConnectingVisitor; a plain `throw ...;` is a statement.
        if ($node instanceof Node\Expr\Throw_ && !($node->getAttribute('parent') instanceof Node\Stmt\Expression)) {
            return 'throw expression';
        }

        if ($node instanceof Node\Stmt\Enum_) {
            return 'enum';
        }

        if ($node instanceof Node\Param && ($node->flags !== 0 || $node->type instanceof Node\UnionType || $node->type instanceof Node\IntersectionType)) {
            return 'promoted property or union/intersection type';
        }

        if ($node instanceof Node\Arg && $node->name !== null) {
            return 'named argument';
        }

        if ($node instanceof Node\Identifier && in_array($node->toLowerString(), ['mixed', 'never'], true)) {
            return $node->toLowerString() . ' type';
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array($node->name->toLowerString(), self::NEWER_FUNCTIONS, true)) {
            return $node->name->toString() . '()';
        }

        return null;
    }

    /**
     * @param array<Node>|null $ast
     * @return array<Node>
     */
    private function withParents(?array $ast): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());

        return $traverser->traverse($ast === null ? [] : $ast);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/modules')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
