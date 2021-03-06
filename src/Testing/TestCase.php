<?php declare(strict_types = 1);

namespace PHPStan\Testing;

use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierFactory;
use PHPStan\Broker\AnonymousClassNameHelper;
use PHPStan\Broker\Broker;
use PHPStan\Broker\BrokerFactory;
use PHPStan\Cache\Cache;
use PHPStan\Cache\MemoryCacheStorage;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\FileHelper;
use PHPStan\Parser\FunctionCallStatementFinder;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\PhpDocStringResolver;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\Annotations\AnnotationsMethodsClassReflectionExtension;
use PHPStan\Reflection\Annotations\AnnotationsPropertiesClassReflectionExtension;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflectionFactory;
use PHPStan\Reflection\Php\PhpClassReflectionExtension;
use PHPStan\Reflection\Php\PhpFunctionReflection;
use PHPStan\Reflection\Php\PhpMethodReflection;
use PHPStan\Reflection\Php\PhpMethodReflectionFactory;
use PHPStan\Reflection\Php\UniversalObjectCratesClassReflectionExtension;
use PHPStan\Reflection\PhpDefect\PhpDefectClassReflectionExtension;
use PHPStan\Reflection\SignatureMap\SignatureMapProvider;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{

	/** @var \Nette\DI\Container|null */
	private static $container;

	public static function getContainer(): \Nette\DI\Container
	{
		if (self::$container === null) {
			$rootDir = __DIR__ . '/../..';
			$containerFactory = new ContainerFactory($rootDir);
			self::$container = $containerFactory->create($rootDir . '/tmp', [
				$containerFactory->getConfigDirectory() . '/config.level7.neon',
			]);
		}

		return self::$container;
	}

	public function getParser(): \PHPStan\Parser\Parser
	{
		/** @var \PHPStan\Parser\Parser $parser */
		$parser = self::getContainer()->getService('directParser');
		return $parser;
	}

	/**
	 * @param \PHPStan\Type\DynamicMethodReturnTypeExtension[] $dynamicMethodReturnTypeExtensions
	 * @param \PHPStan\Type\DynamicStaticMethodReturnTypeExtension[] $dynamicStaticMethodReturnTypeExtensions
	 * @return \PHPStan\Broker\Broker
	 */
	public function createBroker(
		array $dynamicMethodReturnTypeExtensions = [],
		array $dynamicStaticMethodReturnTypeExtensions = []
	): Broker
	{
		$functionCallStatementFinder = new FunctionCallStatementFinder();
		$parser = $this->getParser();
		$cache = new Cache(new MemoryCacheStorage());
		$methodReflectionFactory = new class($parser, $functionCallStatementFinder, $cache) implements PhpMethodReflectionFactory {

			/** @var \PHPStan\Parser\Parser */
			private $parser;

			/** @var \PHPStan\Parser\FunctionCallStatementFinder */
			private $functionCallStatementFinder;

			/** @var \PHPStan\Cache\Cache */
			private $cache;

			/** @var \PHPStan\Broker\Broker */
			public $broker;

			public function __construct(
				Parser $parser,
				FunctionCallStatementFinder $functionCallStatementFinder,
				Cache $cache
			)
			{
				$this->parser = $parser;
				$this->functionCallStatementFinder = $functionCallStatementFinder;
				$this->cache = $cache;
			}

			/**
			 * @param ClassReflection $declaringClass
			 * @param ClassReflection|null $declaringTrait
			 * @param \PHPStan\Reflection\Php\BuiltinMethodReflection $reflection
			 * @param Type[] $phpDocParameterTypes
			 * @param null|Type $phpDocReturnType
			 * @param null|Type $phpDocThrowType
			 * @param bool $isDeprecated
			 * @param bool $isInternal
			 * @param bool $isFinal
			 * @return PhpMethodReflection
			 */
			public function create(
				ClassReflection $declaringClass,
				?ClassReflection $declaringTrait,
				\PHPStan\Reflection\Php\BuiltinMethodReflection $reflection,
				array $phpDocParameterTypes,
				?Type $phpDocReturnType,
				?Type $phpDocThrowType,
				bool $isDeprecated,
				bool $isInternal,
				bool $isFinal
			): PhpMethodReflection
			{
				return new PhpMethodReflection(
					$declaringClass,
					$declaringTrait,
					$reflection,
					$this->broker,
					$this->parser,
					$this->functionCallStatementFinder,
					$this->cache,
					$phpDocParameterTypes,
					$phpDocReturnType,
					$phpDocThrowType,
					$isDeprecated,
					$isInternal,
					$isFinal
				);
			}

		};
		$phpDocStringResolver = self::getContainer()->getByType(PhpDocStringResolver::class);
		$fileTypeMapper = new FileTypeMapper($parser, $phpDocStringResolver, $cache, new AnonymousClassNameHelper(new FileHelper($this->getCurrentWorkingDirectory())));
		$annotationsMethodsClassReflectionExtension = new AnnotationsMethodsClassReflectionExtension($fileTypeMapper);
		$annotationsPropertiesClassReflectionExtension = new AnnotationsPropertiesClassReflectionExtension($fileTypeMapper);
		$signatureMapProvider = self::getContainer()->getByType(SignatureMapProvider::class);
		$phpExtension = new PhpClassReflectionExtension($methodReflectionFactory, $fileTypeMapper, $annotationsMethodsClassReflectionExtension, $annotationsPropertiesClassReflectionExtension, $signatureMapProvider);
		$functionReflectionFactory = new class($this->getParser(), $functionCallStatementFinder, $cache) implements FunctionReflectionFactory {

			/** @var \PHPStan\Parser\Parser */
			private $parser;

			/** @var \PHPStan\Parser\FunctionCallStatementFinder */
			private $functionCallStatementFinder;

			/** @var \PHPStan\Cache\Cache */
			private $cache;

			public function __construct(
				Parser $parser,
				FunctionCallStatementFinder $functionCallStatementFinder,
				Cache $cache
			)
			{
				$this->parser = $parser;
				$this->functionCallStatementFinder = $functionCallStatementFinder;
				$this->cache = $cache;
			}

			/**
			 * @param \ReflectionFunction $function
			 * @param Type[] $phpDocParameterTypes
			 * @param null|Type $phpDocReturnType
			 * @param null|Type $phpDocThrowType
			 * @param bool $isDeprecated
			 * @param bool $isInternal
			 * @param bool $isFinal
			 * @return PhpFunctionReflection
			 */
			public function create(
				\ReflectionFunction $function,
				array $phpDocParameterTypes,
				?Type $phpDocReturnType,
				?Type $phpDocThrowType,
				bool $isDeprecated,
				bool $isInternal,
				bool $isFinal
			): PhpFunctionReflection
			{
				return new PhpFunctionReflection(
					$function,
					$this->parser,
					$this->functionCallStatementFinder,
					$this->cache,
					$phpDocParameterTypes,
					$phpDocReturnType,
					$phpDocThrowType,
					$isDeprecated,
					$isInternal,
					$isFinal
				);
			}

		};

		$tagToService = function (array $tags) {
			return array_map(function (string $serviceName) {
				return self::getContainer()->getService($serviceName);
			}, array_keys($tags));
		};

		$broker = new Broker(
			[
				$phpExtension,
				new PhpDefectClassReflectionExtension(self::getContainer()->getByType(TypeStringResolver::class)),
				new UniversalObjectCratesClassReflectionExtension([\stdClass::class]),
				$annotationsPropertiesClassReflectionExtension,
			],
			[
				$phpExtension,
				$annotationsMethodsClassReflectionExtension,
			],
			array_merge($dynamicMethodReturnTypeExtensions, $this->getDynamicMethodReturnTypeExtensions()),
			array_merge($dynamicStaticMethodReturnTypeExtensions, $this->getDynamicStaticMethodReturnTypeExtensions()),
			array_merge($tagToService(self::getContainer()->findByTag(BrokerFactory::DYNAMIC_FUNCTION_RETURN_TYPE_EXTENSION_TAG)), $this->getDynamicFunctionReturnTypeExtensions()),
			$functionReflectionFactory,
			new FileTypeMapper($this->getParser(), $phpDocStringResolver, $cache, new AnonymousClassNameHelper(new FileHelper($this->getCurrentWorkingDirectory()))),
			$signatureMapProvider,
			self::getContainer()->getByType(Standard::class),
			new AnonymousClassNameHelper(new FileHelper($this->getCurrentWorkingDirectory())),
			self::getContainer()->getByType(Parser::class),
			self::getContainer()->parameters['universalObjectCratesClasses'],
			$this->getCurrentWorkingDirectory()
		);
		$methodReflectionFactory->broker = $broker;

		return $broker;
	}

	/**
	 * @param Broker $broker
	 * @param TypeSpecifier $typeSpecifier
	 * @param string[] $dynamicConstantNames
	 *
	 * @return ScopeFactory
	 */
	public function createScopeFactory(Broker $broker, TypeSpecifier $typeSpecifier, array $dynamicConstantNames = []): ScopeFactory
	{
		return new class($broker, $typeSpecifier, $dynamicConstantNames) implements ScopeFactory {

			/** @var \PHPStan\Broker\Broker */
			private $broker;

			/** @var \PhpParser\PrettyPrinter\Standard */
			private $printer;

			/** @var \PHPStan\Analyser\TypeSpecifier */
			private $typeSpecifier;

			/** @var string[] */
			private $dynamicConstantNames;

			/**
			 * @param Broker $broker
			 * @param TypeSpecifier $typeSpecifier
			 * @param string[] $dynamicConstantNames
			 */
			public function __construct(Broker $broker, TypeSpecifier $typeSpecifier, array $dynamicConstantNames)
			{
				$this->broker = $broker;
				$this->printer = new \PhpParser\PrettyPrinter\Standard();
				$this->typeSpecifier = $typeSpecifier;
				$this->dynamicConstantNames = $dynamicConstantNames;
			}

			/**
			 * @param \PHPStan\Analyser\ScopeContext $context
			 * @param bool $declareStrictTypes
			 * @param \PHPStan\Reflection\FunctionReflection|\PHPStan\Reflection\MethodReflection|null $function
			 * @param string|null $namespace
			 * @param \PHPStan\Analyser\VariableTypeHolder[] $variablesTypes
			 * @param \PHPStan\Analyser\VariableTypeHolder[] $moreSpecificTypes
			 * @param string|null $inClosureBindScopeClass
			 * @param \PHPStan\Type\Type|null $inAnonymousFunctionReturnType
			 * @param \PhpParser\Node\Expr\FuncCall|\PhpParser\Node\Expr\MethodCall|\PhpParser\Node\Expr\StaticCall|null $inFunctionCall
			 * @param bool $negated
			 * @param bool $inFirstLevelStatement
			 * @param string[] $currentlyAssignedExpressions
			 *
			 * @return Scope
			 */
			public function create(
				ScopeContext $context,
				bool $declareStrictTypes = false,
				$function = null,
				?string $namespace = null,
				array $variablesTypes = [],
				array $moreSpecificTypes = [],
				?string $inClosureBindScopeClass = null,
				?Type $inAnonymousFunctionReturnType = null,
				?Expr $inFunctionCall = null,
				bool $negated = false,
				bool $inFirstLevelStatement = true,
				array $currentlyAssignedExpressions = []
			): Scope
			{
				return new Scope(
					$this,
					$this->broker,
					$this->printer,
					$this->typeSpecifier,
					$context,
					$declareStrictTypes,
					$function,
					$namespace,
					$variablesTypes,
					$moreSpecificTypes,
					$inClosureBindScopeClass,
					$inAnonymousFunctionReturnType,
					$inFunctionCall,
					$negated,
					$inFirstLevelStatement,
					$currentlyAssignedExpressions,
					$this->dynamicConstantNames
				);
			}

		};
	}

	public function getCurrentWorkingDirectory(): string
	{
		return $this->getFileHelper()->normalizePath(__DIR__ . '/../..');
	}

	/**
	 * @return \PHPStan\Type\DynamicMethodReturnTypeExtension[]
	 */
	public function getDynamicMethodReturnTypeExtensions(): array
	{
		return [];
	}

	/**
	 * @return \PHPStan\Type\DynamicStaticMethodReturnTypeExtension[]
	 */
	public function getDynamicStaticMethodReturnTypeExtensions(): array
	{
		return [];
	}

	/**
	 * @return \PHPStan\Type\DynamicFunctionReturnTypeExtension[]
	 */
	public function getDynamicFunctionReturnTypeExtensions(): array
	{
		return [];
	}

	/**
	 * @param \PhpParser\PrettyPrinter\Standard $printer
	 * @param \PHPStan\Broker\Broker $broker
	 * @param \PHPStan\Type\MethodTypeSpecifyingExtension[] $methodTypeSpecifyingExtensions
	 * @param \PHPStan\Type\StaticMethodTypeSpecifyingExtension[] $staticMethodTypeSpecifyingExtensions
	 * @return \PHPStan\Analyser\TypeSpecifier
	 */
	public function createTypeSpecifier(
		Standard $printer,
		Broker $broker,
		array $methodTypeSpecifyingExtensions = [],
		array $staticMethodTypeSpecifyingExtensions = []
	): TypeSpecifier
	{
		$tagToService = function (array $tags) {
			return array_map(function (string $serviceName) {
				return self::getContainer()->getService($serviceName);
			}, array_keys($tags));
		};

		return new TypeSpecifier(
			$printer,
			$broker,
			$tagToService(self::getContainer()->findByTag(TypeSpecifierFactory::FUNCTION_TYPE_SPECIFYING_EXTENSION_TAG)),
			$methodTypeSpecifyingExtensions,
			$staticMethodTypeSpecifyingExtensions
		);
	}

	public function getFileHelper(): FileHelper
	{
		return self::getContainer()->getByType(FileHelper::class);
	}

	protected function skipIfNotOnWindows(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			return;
		}

		self::markTestSkipped();
	}

	protected function skipIfNotOnUnix(): void
	{
		if (DIRECTORY_SEPARATOR === '/') {
			return;
		}

		self::markTestSkipped();
	}

}
