<?php declare(strict_types=1);

namespace Nadybot\Api;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	DBRow,
	Registry,
};
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;

class ApiSpecGenerator {
	public function loadClasses(): void {
		foreach (\Safe\glob(__DIR__ . "/../Core/DBSchema/*.php") ?: [] as $file) {
			require_once $file;
		}
		foreach (\Safe\glob(__DIR__ . "/../Core/Modules/*/*.php") ?: [] as $file) {
			require_once $file;
		}
		foreach (\Safe\glob(__DIR__ . "/../Core/*.php") ?: [] as $file) {
			require_once $file;
		}
		foreach (\Safe\glob(__DIR__ . "/../Modules/*/*.php") ?: [] as $file) {
			require_once $file;
		}
	}

	/**
	 * Return an array of [instancename => full class name] for all #[Instance]s
	 *
	 * @return array<string,string>
	 * @phpstan-return array<string,class-string>
	 */
	public function getInstances(): array {
		$classes = get_declared_classes();
		$instances = [];
		foreach ($classes as $className) {
			$reflection = new ReflectionClass($className);
			$instanceAttrs = $reflection->getAttributes(NCA\Instance::class);
			if (empty($instanceAttrs)) {
				continue;
			}

			/** @var NCA\Instance */
			$instanceObj = $instanceAttrs[0]->newInstance();
			$name = $instanceObj->name ?? Registry::formatName($className);
			$instances[$name] = $className;
		}
		return $instances;
	}

	/** @return array<string,ReflectionMethod[]> */
	public function getPathMapping(): array {
		$instances = $this->getInstances();
		$paths = [];
		foreach ($instances as $short => $className) {
			$reflection = new ReflectionClass($className);
			$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
			foreach ($methods as $method) {
				$apiAttrs = $method->getAttributes(NCA\Api::class);
				if (empty($apiAttrs)) {
					continue;
				}

				/** @var NCA\Api */
				$apiAttr = $apiAttrs[0]->newInstance();

				/** @var ReflectionParameter[] $params */
				$params = array_slice($method->getParameters(), 2);
				$path = preg_replace_callback(
					"/%[ds]/",
					function (array $matches) use (&$params): string {
						$param = array_shift($params);
						return '{' . $param->getName() . '}';
					},
					$apiAttr->path
				);
				$paths[$path] ??= [];
				$paths[$path] []= $method;
			}
		}
		uksort(
			$paths,
			function (string $a, string $b): int {
				return strcmp("{$a}/", "{$b}/");
			}
		);
		return $paths;
	}

	/** @phpstan-return null|class-string */
	public function getFullClass(string $className): ?string {
		$classes = get_declared_classes();
		foreach ($classes as $class) {
			if (is_subclass_of($class, \Attribute::class)) {
				continue;
			}
			if ($class === $className || preg_match("/^Nadybot\\\\.*?\\\\\Q{$className}\E$/", $class)) {
				return $class;
			}
		}
		return null;
	}

	/** @param array<string,array<mixed>> $result */
	public function addSchema(array &$result, string $className): void {
		$className = preg_replace("/\[\]$/", "", $className);
		if (!is_string($className) || isset($result[$className])) {
			return;
		}
		$class = $this->getFullClass($className);
		if ($class === null) {
			return;
		}
		$refClass = new ReflectionClass($class);
		$refProps = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		$description = $this->getDescriptionFromComment($refClass->getDocComment() ?: "");
		$newResult = [
			"type" => "object",
			"properties" => [],
		];
		if (strlen($description)) {
			$newResult["description"] = $description;
		}
		foreach ($refProps as $refProp) {
			if ($refProp->getDeclaringClass()->getName() !== $class) {
				continue;
			}
			$nameAndType = $this->getNameAndType($refProp);
			if ($nameAndType === null) {
				continue;
			}
			if (is_string($nameAndType[1]) && substr($nameAndType[1], 0, 1) === "#") {
				$tmp = explode("/", $nameAndType[1]);
				$this->addSchema($result, end($tmp));
				$newResult["properties"][$nameAndType[0]] = [
					'$ref' => $nameAndType[1],
				];
			} elseif (is_array($nameAndType[1])) {
				$newResult["properties"][$nameAndType[0]] = [
					"oneOf" => array_map(
						function (string $type): array {
							return ["type" => $type];
						},
						$nameAndType[1]
					),
				];
			} else {
				$newResult["properties"][$nameAndType[0]] = [
					"type" => $nameAndType[1],
				];
			}
			$refType = $refProp->getType();
			if (!$refType || $refType->allowsNull()) {
				$newResult["properties"][$nameAndType[0]]["nullable"] = true;
			}
			if (isset($nameAndType[2]) && strlen($nameAndType[2])) {
				$newResult["properties"][$nameAndType[0]]["description"] = $nameAndType[2];
			}
			if ($nameAndType[1] === 'array') {
				$docBlock = $refProp->getDocComment();
				if ($docBlock === false) {
					throw new Exception("Untyped array found at {$class}::\$" . $refProp->name);
				}
				if (!preg_match("/@json-var\s+(.+?)\[\]/", $docBlock, $matches)
					&& !preg_match("/@var\s+(.+?)\[\]/", $docBlock, $matches)) {
					throw new Exception("Untyped array found at {$class}::\$" . $refProp->name);
				}
				$parts = explode("\\", $matches[1]??"");
				$newResult["properties"][$nameAndType[0]]["items"] = $this->getSimpleClassRef(end($parts));
				$this->addSchema($result, end($parts));
			}
		}
		if ($refClass->getParentClass() !== false) {
			$parentClass = $refClass->getParentClass()->getName();
			if ($parentClass !== DBRow::class) {
				$parentParts = explode("\\", $parentClass);
				$this->addSchema($result, end($parentParts));
				$newResult = [
					"allOf" => [
						['$ref' => "#/components/schemas/" . end($parentParts)],
						$newResult,
					],
				];
			}
		}
		$result[$className] = $newResult;
	}

	/** @return array<string,mixed> */
	public function getInfoSpec(): array {
		return [
			'title' => 'Nadybot API',
			'description' => 'This API provides access to Nadybot functions in a REST API',
			'license' => [
				'name' => 'GPL3',
				'url' => 'https://www.gnu.org/licenses/gpl-3.0.en.html',
			],
			'version' => BotRunner::getVersion(false),
		];
	}

	/**
	 * @param array<string,ReflectionMethod[]> $mapping
	 *
	 * @return array<string,mixed>
	 */
	public function getSpec(array $mapping): array {
		$result = [
			"openapi" => "3.0.0",
			"info" => $this->getInfoSpec(),
			"servers" => [
				["url" => "/api"],
			],
			"components" => [
				"schemas" => [],
				"securitySchemes" => [
					"basicAuth" => [
						"type" => "http",
						"scheme" => "basic",
					],
				],
			],

		];
		$newResult = [];
		foreach ($mapping as $path => $refMethods) {
			foreach ($refMethods as $refMethod) {
				$doc = $this->getMethodDoc($refMethod);
				$doc->path = $path;
				$newResult[$path] ??= [];
				$newResult[$path]["parameters"] = $this->getParamDocs($path, $refMethod);
				foreach ($doc->methods as $method) {
					$newResult[$path][$method] = [
						"security" => [["basicAuth" => []]],
						"description" => $doc->description,
						"responses" => [],
					];
					if (isset($doc->requestBody)) {
						$newResult[$path][$method]["requestBody"] = $this->getRequestBodyDefinition($doc->requestBody);
						if (isset($doc->requestBody->class)) {
							$this->addSchema($result["components"]["schemas"], $doc->requestBody->class);
						}
					}
					if (!empty($doc->tags)) {
						$newResult[$path][$method]["tags"] = $doc->tags;
					}
					foreach ($doc->responses as $code => $response) {
						if (isset($response->class)) {
							$this->addSchema($result["components"]["schemas"], $response->class);
						}
						$newResult[$path][$method]["responses"][$code] = [
							"description" => $response->desc,
						];
						if (isset($response->class)) {
							$refClass = $this->getClassRef($response->class);
							$newResult[$path][$method]["responses"][$code]["content"] = [
								"application/json" => [
									"schema" => $refClass,
								],
							];
						}
					}
				}
			}
			$result["paths"] = $newResult;
		}
		return $result;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 * @psalm-return list<array{"name": string, "required": bool, "in": string, "schema": array{"type": string}, "description"?: string}>
	 * @phpstan-return list<array{"name": string, "required": bool, "in": string, "schema": array{"type": string}, "description"?: string}>
	 */
	public function getParamDocs(string $path, ReflectionMethod $method): array {
		$result = [];
		if (preg_match_all('/\{(.+?)\}/', $path, $matches)) {
			foreach ($matches[1] as $param) {
				foreach ($method->getParameters() as $refParam) {
					if ($refParam->getName() !== $param) {
						continue;
					}
				}
				if (!isset($refParam)) {
					continue;
				}

				/** @var ReflectionNamedType */
				$refType = $refParam->getType();
				$paramResult = [
					"name" => $param,
					"required" => true,
					"in" => "path",
					"schema" => ["type" => $refType->getName()],
				];
				if ($refType->getName() === "int") {
					$paramResult["schema"]["type"] = "integer";
				}
				if ($refType->getName() === "bool") {
					$paramResult["schema"]["type"] = "boolean";
				}
				if (preg_match("/@param.*?\\$\Q{$param}\E\s+(.+)$/m", $method->getDocComment() ?: "", $matches)) {
					$matches[1] = preg_replace("/\*\//", "", $matches[1]);
					if (is_string($matches[1])) {
						$paramResult["description"] = trim($matches[1]);
					}
				}
				$result []= $paramResult;
			}
		}
		$qParamAttrs = $method->getAttributes(NCA\QueryParam::class);
		foreach ($qParamAttrs as $qParamAttr) {
			/** @var NCA\QueryParam */
			$qParam = $qParamAttr->newInstance();
			$result []= [
				"name" => $qParam->name,
				"required" => $qParam->required,
				"in" => $qParam->in,
				"schema" => ["type" => $qParam->type],
				"description" => $qParam->desc,
			];
		}
		return $result;
	}

	public function getDescriptionFromComment(string $comment): string {
		$comment = trim(preg_replace("|^/\*\*(.*)\*/$|s", '$1', $comment));
		$comment = preg_replace("|^\s*\*\s*|m", '', $comment);
		$comment = trim(preg_replace("|@.*$|s", '', $comment));
		$comment = str_replace("\n", " ", $comment);
		$comment = preg_replace("/\s*\*$/", '', $comment);
		return $comment;
	}

	public function getMethodDoc(ReflectionMethod $method): PathDoc {
		$doc = new PathDoc();
		$comment = $method->getDocComment();
		$doc->description = $comment ? $this->getDescriptionFromComment($comment) : "No documentation provided";

		$apiResultAttrs = $method->getAttributes(NCA\ApiResult::class);
		if (empty($apiResultAttrs)) {
			throw new Exception("Method " . $method->getDeclaringClass()->getName() . '::' . $method->getName() . "() has no #[ApiResult] defined");
		}
		if (($fileName = $method->getFileName()) === false) {
			throw new Exception("Cannot determine file for" . $method->getDeclaringClass()->getName());
		}
		$dir = dirname($fileName);
		if (preg_match("{(?:/|^)([A-Z_]+)(?:/|$)}", $dir, $matches)) {
			$doc->tags = [strtolower(preg_replace("/_MODULE/", "", $matches[1]))];
		}
		foreach ($method->getAttributes() as $attr) {
			$attr = $attr->newInstance();
			if ($attr instanceof NCA\ApiResult) {
				/** @var NCA\ApiResult $attr */
				$doc->responses[$attr->code] = $attr;
			} elseif ($attr instanceof NCA\ApiTag) {
				/** @var NCA\ApiTag $attr */
				$doc->tags []= $attr->tag;
			} elseif ($attr instanceof NCA\RequestBody) {
				/** @var NCA\RequestBody $attr */
				$doc->requestBody = $attr;
			} elseif ($attr instanceof NCA\VERB) {
				/** @var NCA\VERB $attr */
				$doc->methods []= strtolower(class_basename($attr));
			}
		}
		return $doc;
	}

	/**
	 * @return array<string,mixed>
	 * @phpstan-return array{"description"?: string, "required"?: bool, "content": array{"application/json": array{"schema": string|array<mixed>}}}
	 * @psalm-return array{"description"?: string, "required"?: bool, "content": array{"application/json": array{"schema": string|array<mixed>}}}
	 */
	public function getRequestBodyDefinition(NCA\RequestBody $requestBody): array {
		$result = [];
		if (isset($requestBody->desc)) {
			$result["description"] = $requestBody->desc;
		}
		if (isset($requestBody->required)) {
			$result["required"] = $requestBody->required;
		}
		$classes = explode("|", $requestBody->class);
		foreach ($classes as &$class) {
			$class = $this->getClassRef($class);
		}
		if (count($classes) > 1) {
			$classes = ["oneOf" => $classes];
		} else {
			$classes = $classes[0];
		}
		$result["content"] = [
			"application/json" => [
				"schema" => $classes,
			],
		];
		return $result;
	}

	/**
	 * @return mixed[]
	 * @psalm-return array{0: string, 1: string|list<string>}
	 */
	protected function getRegularNameAndType(ReflectionProperty $refProp): array {
		$propName = $refProp->getName();
		if (!$refProp->hasType()) {
			$comment = $refProp->getDocComment();
			if ($comment === false || !preg_match("/@var ([^\s]+)/s", $comment, $matches)) {
				return [$propName, "mixed"];
			}
			$types = explode("|", $matches[1]??"");
			foreach ($types as &$type) {
				if ($type === "int") {
					$type = "integer";
				} elseif ($type === "bool") {
					$type = "boolean";
				}
			}
			return [$propName, $types];
		}
		$refTypes = [];
		$refType = $refProp->getType();
		if ($refType instanceof ReflectionUnionType) {
			$refTypes = $refType->getTypes();
		} elseif ($refType instanceof ReflectionNamedType) {
			$refTypes = [$refType];
		} else {
			throw new Exception("Unknown ReflectionClass");
		}
		$types = [];
		foreach ($refTypes as $refType) {
			if ($refType->isBuiltin()) {
				if ($refType->getName() === "int") {
					$types []= "integer";
				} elseif ($refType->getName() === "bool") {
					$types []= "boolean";
				} else {
					$types []= $refType->getName();
				}
			} elseif ($refType->getName() === "DateTime") {
				$types []= "integer";
			} else {
				$name = explode("\\", $refType->getName());
				$types []= "#/components/schemas/" . end($name);
			}
		}
		if (count($types) === 1) {
			return [$propName, $types[0]];
		}
		return [$propName, $types];
	}

	/**
	 * @return null|array<mixed>
	 * @psalm-return null|array{0: string, 1: string|string[], 2?: string}
	 * @phpstan-return null|array{0: string, 1: string|string[], 2?: string}
	 */
	protected function getNameAndType(ReflectionProperty $refProperty): ?array {
		$docComment = $refProperty->getDocComment();
		if (count($refProperty->getAttributes(NCA\JSON\Ignore::class))) {
			return null;
		}
		$description = $this->getDescriptionFromComment($docComment ?: "");
		$nameAttr = $refProperty->getAttributes(NCA\JSON\Name::class);
		if (count($nameAttr) > 0) {
			/** @var NCA\JSON\Name */
			$nameObj = $nameAttr[0]->newInstance();
			return [$nameObj->name, $this->getRegularNameAndType($refProperty)[1], $description];
		}
		return [...$this->getRegularNameAndType($refProperty), $description];
	}

	/**
	 * @return array<string,string|array<string,string>>
	 * @phpstan-return array{"type"?: string, "$ref"?: string}|array{"type": "array", "items":array{"type"?: string, "$ref"?: string}}
	 * @psalm-return array{"type"?: string, "$ref"?: string}|array{"type": "array", "items":array{"type"?: string, "$ref"?: string}}
	 */
	protected function getClassRef(string $class): array {
		if (substr($class, -2) === '[]') {
			return ["type" => "array", "items" => $this->getSimpleClassRef(substr($class, 0, -2))];
		}
		return $this->getSimpleClassRef($class);
	}

	/**
	 * @return array<string,string>
	 * @phpstan-return array{"type"?: string, "$ref"?: string}
	 * @psalm-return array{"type"?: string, "$ref"?: string}
	 */
	protected function getSimpleClassRef(string $class): array {
		if (in_array($class, ["string", "bool", "int", "float"])) {
			return ["type" => str_replace(["int", "bool"], ["integer", "boolean"], $class)];
		}
		if ($class === "DateTime") {
			return ["type" => "integer"];
		}
		return ['$ref' => "#/components/schemas/{$class}"];
	}
}
