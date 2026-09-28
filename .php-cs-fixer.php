<?php

declare(strict_types=1);

/**
 * php-cs-fixer configuration.
 *
 * TWO TOOLS, TWO JOBS
 *   phpcs   (composer lint)  — reports style violations. This is the CI gate.
 *   php-cs-fixer (composer fix) — rewrites them. This is never a gate; it is a
 *                            convenience for the developer.
 *
 * The division matters because a formatter that silently rewrites code can hide
 * a mistake. `composer fix` is something you choose to run; `composer lint` is
 * something that fails the build. Anything this file can fix automatically is
 * exactly the class of problem that should not need a human to notice it.
 *
 * Rules are declared explicitly rather than inherited from a preset wholesale,
 * so a preset upgrade cannot change the style of the codebase in a commit whose
 * message says nothing about style.
 */


$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/config', __DIR__ . '/public'])
    ->append([__DIR__ . '/public/index.php', __DIR__ . '/public/router.php'])
    ->name('*.php')
    // Generated or vendored. Never formatted, never linted.
    ->notPath('vendor')
    ->notPath('storage')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setIndent("\t")
    ->setLineEnding("\n")
    ->setUsingCache(false)
    ->setFinder($finder)
    ->setRules([
        // ─── PSR-12, the base style ────────────────────────────────────────
        '@PSR12'                              => true,
        '@PSR2'                               => false,
        'array_indentation'                   => true,
        'binary_operator_spaces'              => ['default' => 'align_single_space_minimal'],
        'blank_line_after_opening_tag'        => true,
        'blank_line_before_statement'         => [
            'statements' => ['return', 'throw', 'try', 'if', 'foreach', 'while', 'do', 'switch'],
        ],
        'braces_position'                     => [
            'classes_opening_brace'   => 'same_line',
            'functions_opening_brace' => 'same_line',
            'anonymous_classes_opening_brace' => 'same_line',
            'anonymous_functions_opening_brace' => 'same_line',
            'control_structures_opening_brace' => 'same_line',
            'arrays_opening_brace'    => 'same_line',
        ],
        'cast_spaces'                         => ['space' => 'single'],
        'class_attributes_separation'         => ['elements' => ['method' => 'one']],
        'concat_space'                        => ['spacing' => 'none'],
        'declare_equal_normalize'             => true,
        'function_declaration'                => true,
        'indentation_type'                    => true,
        'linebreak_after_opening_tag'         => true,
        'lowercase_cast'                      => true,
        'lowercase_keywords'                  => true,
        'lowercase_static_reference'           => true,
        'method_argument_space'               => [
            'on_multiline' => 'ignore',
        ],
        'native_function_casing'              => true,
        'native_function_type_declaration_casing' => true,
        'no_alias_functions'                  => ['sets' => ['@all']],
        'no_blank_lines_after_class_opening'  => true,
        'no_blank_lines_after_phpdoc'         => true,
        'no_closing_tag'                      => true,
        'no_empty_phpdoc'                     => true,
        'no_empty_statement'                  => true,
        'no_extra_blank_lines'                => true,
        'no_leading_import_slash'             => true,
        'no_leading_namespace_whitespace'     => true,
        'no_mixed_echo_print'                 => ['use' => 'echo'],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_short_bool_cast'                  => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_spaces_around_offset'             => true,
        'no_superfluous_elseif'               => true,
        'no_trailing_comma_in_singleline'     => true,
        'no_trailing_whitespace'              => true,
        'no_trailing_whitespace_in_comment'   => true,
        'no_unneeded_control_parentheses'     => true,
        'no_unneeded_curly_braces'            => true,
        'no_unset_cast'                       => true,
        'no_unused_imports'                   => true,
        'no_useless_else'                     => true,
        'no_useless_return'                   => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line'         => true,
        'normalize_index_brace'               => true,
        'object_operator_without_whitespace'  => true,
        'ordered_imports'                     => ['sort_algorithm' => 'alpha'],
        'phpdoc_indent'                       => true,
        'phpdoc_inline_tag_normalizer'        => true,
        'phpdoc_no_access'                    => true,
        'phpdoc_no_empty_return'              => true,
        'phpdoc_no_package'                   => true,
        'phpdoc_scalar'                       => true,
        'phpdoc_single_line_var_spacing'      => true,
        'phpdoc_summary'                      => false,
        'phpdoc_tag_type'                     => [
            'tags' => ['inheritdoc', 'param', 'return', 'throws', 'var', 'template'],
        ],
        'phpdoc_to_comment'                   => false,
        'phpdoc_trim'                         => true,
        'phpdoc_types'                        => true,
        'phpdoc_var_without_name'             => true,
        'return_type_declaration'             => ['space_before' => 'none'],
        'single_blank_line_at_eof'            => true,
        'single_class_element_per_statement'  => true,
        'single_import_per_statement'         => true,
        'single_line_after_imports'           => true,
        'single_quote'                        => true,
        'space_after_semicolon'               => ['remove_in_empty_for_expressions' => true],
        'standardize_not_equals'              => true,
        'switch_case_space'                   => true,
        'switch_case_semicolon_to_colon'      => true,
        'ternary_operator_spaces'             => true,
        'trailing_comma_in_multiline'         => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'trim_array_spaces'                   => true,
        'types_spaces'                        => true,
        'whitespace_after_comma_in_array'     => true,
        'single_space_around_construct'       => true,
    ]);
