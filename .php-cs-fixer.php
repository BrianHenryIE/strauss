<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Relative to the `in()` directories.
    ->exclude([
        'Benchmark',
        'Issues/data',
        '_reports',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        // Matches the minimum PHP version in composer.json.
        '@PHP7x4Migration' => true,

        // phpcbf (phpcs.xml: PEAR + PSR2) is the formatter. These rules rewrite code phpcbf has already formatted.

        // Contradict phpcs: the fixer's output fails these sniffs.
        // PSR2.Methods.FunctionCallSignature.SpaceBeforeCloseBracket – `foo($a,)` becomes `foo($a, )`.
        'method_argument_space' => false,
        // PEAR.ControlStructures.MultiLineCondition.Alignment – strips the indent from a blank line inside a
        // multi-line `if (` condition.
        'no_whitespace_in_blank_line' => false,
        // PEAR.Classes.ClassDeclaration.OpenBraceNewLine, PSR2.Classes.ClassDeclaration.OpenBraceNewLine,
        // PEAR.WhiteSpace.ScopeClosingBrace.Line, Squiz.WhiteSpace.ScopeClosingBrace.ContentBefore,
        // Squiz.Functions.MultiLineFunctionDeclaration.BraceOnSameLine – collapses empty bodies to `{}`.
        'single_line_empty_body' => false,

        // No phpcs sniff decides these: phpcs accepts both styles, so the fixer would only restyle phpcbf's output.
        // Generic.WhiteSpace.ScopeIndent does not check array element indentation.
        'array_indentation' => false,
        // `array()` vs `[]`: no Generic.Arrays.DisallowLongArraySyntax / DisallowShortArraySyntax in the ruleset.
        'array_syntax' => false,
        // `$a = $a ?? $b` vs `$a ??= $b`: no sniff. Would also undo PEAR-style `=` alignment.
        'assign_null_coalescing_to_coalesce_equal' => false,
        // Removes aligned `=` padding and spaces `=>`: no Generic.Formatting.MultipleStatementAlignment or
        // Squiz.WhiteSpace.OperatorSpacing in the ruleset.
        'binary_operator_spaces' => false,
        // PEAR.Commenting.FileComment accepts the file docblock directly after `<?php`.
        'blank_line_after_opening_tag' => false,
        // PSR2.Namespaces.NamespaceDeclaration only checks the blank line after `namespace`.
        'blank_lines_before_namespace' => false,
        // PEAR/PSR2.Classes.ClassDeclaration don't check anonymous classes' brace position.
        'braces_position' => false,
        // `(object)$x` vs `(object) $x`: no Generic.Formatting.SpaceAfterCast / NoSpaceAfterCast in the ruleset.
        'cast_spaces' => false,
        // `new class (` vs `new class(`: PSR12.Classes.AnonClassDeclaration isn't part of PSR2.
        'class_definition' => false,
        // `'a'.$b` vs `'a' . $b`: no Squiz.Strings.ConcatenationSpacing in the ruleset.
        'concat_space' => false,
        // `fn(` vs `fn (`: Squiz.Functions.FunctionDeclaration doesn't check arrow functions.
        'function_declaration' => false,
        // No sniff for heredoc/nowdoc indentation.
        'heredoc_indentation' => false,
        // `const` vs `public const`: PSR2.Classes.PropertyDeclaration covers properties only;
        // PSR12.Properties.ConstantVisibility isn't part of PSR2.
        'modifier_keywords' => false,
        // `new Foo` vs `new Foo()`: PSR12.Classes.ClassInstantiation isn't part of PSR2.
        'new_with_parentheses' => false,
        // PEAR/PSR2.Classes.ClassDeclaration allow a blank line after the class's opening brace.
        'no_blank_lines_after_class_opening' => false,
        // PSR2.ControlStructures.SwitchDeclaration (TerminatingComment) accepts the existing fall-throughs without
        // a `// no break` comment.
        'no_break_comment' => false,
        // `array (` vs `array(`: PEAR/PSR2 FunctionCallSignature don't treat `array` as a function call.
        'no_spaces_after_function_name' => false,
        // Moves `.` / `??` to the start of the next line: PEAR.Formatting.MultiLineAssignment only covers `=`.
        'operator_linebreak' => false,
        // `):string` / `) : string` vs `): string`: PSR12.Functions.ReturnTypeDeclaration isn't part of PSR2.
        'return_type_declaration' => false,
        // `return  $x` vs `return $x`: no Squiz.WhiteSpace.LanguageConstructSpacing in the ruleset.
        'single_space_around_construct' => false,
        // `! ( $a )` vs `! ($a)`: no Generic.WhiteSpace / Squiz parenthesis-spacing sniff for grouping parentheses.
        'spaces_inside_parentheses' => false,
        // Generic.WhiteSpace.ScopeIndent ignores T_COMMENT (PSR2's ignoreIndentationTokens), so phpcs allows
        // commented-out code at column 0; the fixer re-indents it.
        'statement_indentation' => false,
        // `?  $a` vs `? $a`: no Squiz.WhiteSpace.OperatorSpacing in the ruleset.
        'ternary_operator_spaces' => false,
        // `isset($a) ? $a : $b` vs `$a ?? $b`: no sniff.
        'ternary_to_null_coalescing' => false,
        // No sniff requires or forbids trailing commas in multi-line arrays/arguments.
        'trailing_comma_in_multiline' => false,
    ])
    ->setFinder($finder)
    ;
