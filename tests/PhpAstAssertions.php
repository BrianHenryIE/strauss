<?php

namespace BrianHenryIE\Strauss\Tests;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

trait PhpAstAssertions
{
    /**
     * @return Node[]
     */
    private function parsePhp(string $contents): array
    {
        return (new ParserFactory())->createForNewestSupportedVersion()->parse($contents) ?? [];
    }

    /**
     * @return string[]
     */
    private function getNamespaces(string $contents): array
    {
        $namespaces = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), Namespace_::class);

        return array_map(
            static fn(Namespace_ $namespace): string => $namespace->name instanceof Name ? $namespace->name->toString() : '\\',
            $namespaces
        );
    }

    /**
     * @return string[]
     */
    private function getClassNames(string $contents): array
    {
        $classes = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), Class_::class);

        return array_values(array_map(
            static fn(Class_ $class): string => $class->name instanceof Node\Identifier ? $class->name->toString() : '',
            array_filter($classes, static fn(Class_ $class): bool => $class->name instanceof Node\Identifier)
        ));
    }

    /**
     * @return string[]
     */
    private function getFunctionDeclarationNames(string $contents): array
    {
        $functions = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), Function_::class);

        return array_map(
            static fn(Function_ $function): string => $function->name->toString(),
            $functions
        );
    }

    /**
     * @return string[]
     */
    private function getConstantDeclarationNames(string $contents): array
    {
        $consts = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), Const_::class);
        $names = [];
        foreach ($consts as $const) {
            foreach ($const->consts as $declaredConst) {
                $names[] = $declaredConst->name->toString();
            }
        }

        return $names;
    }

    /**
     * @return string[]
     */
    private function getNewClassNames(string $contents): array
    {
        $newExpressions = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), New_::class);

        return array_values(array_map(
            static fn(New_ $new): string => $new->class instanceof Name ? $new->class->toString() : '',
            array_filter($newExpressions, static fn(New_ $new): bool => $new->class instanceof Name)
        ));
    }

    /**
     * @return string[]
     */
    private function getFunctionCallNames(string $contents): array
    {
        $calls = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), FuncCall::class);

        return array_values(array_map(
            static fn(FuncCall $call): string => $call->name instanceof Name ? $call->name->toString() : '',
            array_filter($calls, static fn(FuncCall $call): bool => $call->name instanceof Name)
        ));
    }

    /**
     * @return string[]
     */
    private function getClassMethodNames(string $contents): array
    {
        $methods = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), ClassMethod::class);

        return array_map(
            static fn(ClassMethod $method): string => $method->name->toString(),
            $methods
        );
    }

    /**
     * @return string[]
     */
    private function getCallableStringValues(string $contents): array
    {
        $calls = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), FuncCall::class);
        $callableStrings = [];
        foreach ($calls as $call) {
            if (!$call->name instanceof Name) {
                continue;
            }
            if (!in_array($call->name->toString(), ['call_user_func', 'call_user_func_array', 'function_exists'], true)) {
                continue;
            }
            $firstArgument = $call->args[0]->value ?? null;
            if ($firstArgument instanceof String_) {
                $callableStrings[] = $firstArgument->value;
            }
        }

        return $callableStrings;
    }

    /**
     * @return string[]
     */
    private function getConstFetchNames(string $contents): array
    {
        $constFetches = (new NodeFinder())->findInstanceOf($this->parsePhp($contents), ConstFetch::class);

        return array_map(
            static fn(ConstFetch $constFetch): string => $constFetch->name->toString(),
            $constFetches
        );
    }
}
