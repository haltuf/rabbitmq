<?php declare(strict_types=1);

use PhpCsFixer\Fixer\CastNotation\ModernizeTypesCastingFixer;
use PhpCsFixer\Fixer\ClassNotation\ClassAttributesSeparationFixer;
use PhpCsFixer\Fixer\Comment\SingleLineCommentStyleFixer;
use PhpCsFixer\Fixer\FunctionNotation\VoidReturnFixer;
use PhpCsFixer\Fixer\Import\FullyQualifiedStrictTypesFixer;
use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\Fixer\Import\NoLeadingImportSlashFixer;
use PhpCsFixer\Fixer\Import\NoUnneededImportAliasFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Import\SingleImportPerStatementFixer;
use PhpCsFixer\Fixer\LanguageConstruct\DeclareEqualNormalizeFixer;
use PhpCsFixer\Fixer\Operator\StandardizeNotEqualsFixer;
use PhpCsFixer\Fixer\PhpTag\BlankLineAfterOpeningTagFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\Strict\StrictComparisonFixer;
use SlevomatCodingStandard\Sniffs\Classes\EmptyLinesAroundClassBracesSniff;
use SlevomatCodingStandard\Sniffs\Classes\RequireMultiLineMethodSignatureSniff;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
	->withPaths([
		__DIR__ . '/src',
		__DIR__ . '/tests',
	])
	->withPhpCsFixerSets(perCS20: true)
	->withSpacing(indentation: 'tab')
	->withRules([
		DeclareStrictTypesFixer::class,
		NoUnusedImportsFixer::class,
		NoUnneededImportAliasFixer::class,
		NoLeadingImportSlashFixer::class,
		SingleImportPerStatementFixer::class,
		StrictComparisonFixer::class,
		StandardizeNotEqualsFixer::class,
		VoidReturnFixer::class,
		ModernizeTypesCastingFixer::class,
		DeclareEqualNormalizeFixer::class,
	])
	->withConfiguredRule(OrderedImportsFixer::class, [
		'sort_algorithm' => 'alpha',
	])
	->withConfiguredRule(SingleLineCommentStyleFixer::class, [
		'comment_types' => ['hash'],
	])
	->withConfiguredRule(FullyQualifiedStrictTypesFixer::class, [
		'import_symbols' => true,
		'leading_backslash_in_global_namespace' => false,
	])
	->withConfiguredRule(GlobalNamespaceImportFixer::class, [
		'import_classes' => true,
		'import_constants' => true,
		'import_functions' => true,
	])
	->withConfiguredRule(ClassAttributesSeparationFixer::class, [
		'elements' => [
			'property' => 'one',
		],
	])
	->withConfiguredRule(EmptyLinesAroundClassBracesSniff::class, [
		'linesCountAfterOpeningBrace' => 0,
		'linesCountBeforeClosingBrace' => 0,
	])
	->withConfiguredRule(RequireMultiLineMethodSignatureSniff::class, [
		'minLineLength' => 160,
	])
	->withSkip([
		BlankLineAfterOpeningTagFixer::class,
	]);
