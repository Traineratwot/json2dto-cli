<?php

namespace Traineratwot\Json2Dto\Generator;

use Traineratwot\Json2Dto\Helpers\NamespaceFolderResolver;
use Traineratwot\Json2Dto\Helpers\NameValidator;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PromotedParameter;
use Spatie\LaravelData\Data;
use stdClass;

class DtoGenerator
{
    /** @var PhpNamespace[] */
    private array $classes = [];

    private int $nestingLevel = 0;
    private const MAX_NESTING_DEPTH = 50;

    public function __construct(
        private readonly string $baseNamespace,
        private readonly bool   $nested,
        private readonly bool   $typed,
        private readonly bool   $optional,
        private readonly bool   $multipart = false,
    ) {}

    /**
     * Генерирует DTO из одного объекта.
     */
    public function generate(stdClass $source, ?string $name): void
    {
        $this->nestingLevel = 0;
        $this->createClass($this->baseNamespace, $source, $name, '');
    }

    /**
     * Генерирует DTO из массива объектов (multipart-режим).
     * Объединяет все поля из всех объектов в один DTO.
     * Поля, присутствующие не во всех объектах, становятся optional.
     *
     * @param stdClass[] $sources
     */
    public function generateMultipart(array $sources, ?string $name): void
    {
        $this->nestingLevel = 0;
        $merged = $this->mergeObjects($sources);
        $this->createClassFromMerged($this->baseNamespace, $merged, $name, '');
    }

    public function getFiles(NamespaceFolderResolver $namespaceResolver): array
    {
        $files = [];

        foreach ($this->classes as $classNamespace) {
            $folder = $namespaceResolver->namespaceToFolder($classNamespace->getName());
            $className = array_values($classNamespace->getClasses())[0]->getName();
            $class = sprintf("<?php\n\n%s", (string) $classNamespace);
            $path = sprintf('%s/%s.php', $folder, $className);

            $files[$path] = $class;
        }

        return $files;
    }

    /**
     * Объединяет массив stdClass-объектов в единую структуру с мета-информацией.
     *
     * Возвращает массив вида:
     * [
     *   'propertyName' => [
     *     'values'    => [mixed, ...],   // все встречающиеся значения (не null)
     *     'total'     => int,            // сколько объектов во входном массиве
     *     'present'   => int,            // в скольких объектах присутствует этот ключ
     *     'nullable'  => bool,           // встречались ли null-значения
     *   ],
     *   ...
     * ]
     *
     * @param stdClass[] $sources
     * @return array<string, array{values: list<mixed>, total: int, present: int, nullable: bool}>
     */
    private function mergeObjects(array $sources): array
    {
        $total = count($sources);
        $merged = [];

        foreach ($sources as $source) {
            $vars = get_object_vars($source);
            foreach ($vars as $key => $value) {
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'values'   => [],
                        'total'    => $total,
                        'present'  => 0,
                        'nullable' => false,
                    ];
                }
                $merged[$key]['present']++;
                if ($value === null) {
                    $merged[$key]['nullable'] = true;
                } else {
                    $merged[$key]['values'][] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * Создаёт класс DTO из объединённой мета-структуры (multipart).
     *
     * @param array<string, array{values: list<mixed>, total: int, present: int, nullable: bool}> $merged
     */
    private function createClassFromMerged(
        string $namespace,
        array  $merged,
        ?string $name,
        string $pathPrefix,
    ): PhpNamespace {
        $extends = Data::class;

        $classNamespace = new PhpNamespace($namespace);
        $classNamespace->addUse($extends, 'SpatieLaravelDataObj');

        $class = $classNamespace->addClass(str_replace(' ', '', $name ?? 'JsonDataTransferObject'));
        $class->addExtend($extends);

        $constructor = $class->addMethod('__construct');

        foreach ($merged as $key => $meta) {
            $this->addMergedConstructorProperty(
                $classNamespace,
                $class,
                $constructor,
                $key,
                $meta,
                $pathPrefix,
            );
        }

        $this->classes[] = $classNamespace;

        return $classNamespace;
    }

    /**
     * Добавляет свойство в конструктор DTO из объединённой мета-информации.
     *
     * @param array{values: list<mixed>, total: int, present: int, nullable: bool} $meta
     */
    private function addMergedConstructorProperty(
        PhpNamespace $namespace,
        ClassType    $class,
        Method       $constructor,
        string       $key,
        array        $meta,
        string       $pathPrefix,
    ): void {
        $normalizedKey = $this->normalizePropertyName($key);
        if ($normalizedKey === null) {
            return;
        }

        $shouldMap = $this->needsMapping($key, $normalizedKey);

        // Поле optional, если присутствует не во всех объектах, или есть null, или глобальный optional
        $isOptional = $this->optional || $meta['present'] < $meta['total'] || $meta['nullable'];

        // Определяем тип по всем не-null значениям
        $resolvedType = $this->resolveTypeFromMultipleValues($meta['values']);

        // Обработка массивов
        if ($resolvedType === 'array') {
            $result = $this->addMergedCollectionProperty(
                $namespace, $class, $constructor, $key, $normalizedKey,
                $meta['values'], $shouldMap, $isOptional, $pathPrefix,
            );
            if ($result) {
                return;
            }
            $resolvedType = null;
        }

        // Обработка вложенных объектов
        if ($resolvedType === 'object') {
            if ($this->nestingLevel < self::MAX_NESTING_DEPTH) {
                // Собираем все объекты для этого поля и делаем multipart-merge вложенного DTO
                $nestedObjects = array_filter($meta['values'], fn($v) => $v instanceof stdClass);
                if (count($nestedObjects) > 0) {
                    $nestedPathPrefix = $pathPrefix ? $pathPrefix . '.' . $normalizedKey : $normalizedKey;
                    if (count($nestedObjects) > 1) {
                        $dto = $this->addNestedDTOFromMultiple($namespace, $nestedPathPrefix, $nestedObjects);
                    } else {
                        $dto = $this->addNestedDTO($namespace, $nestedPathPrefix, reset($nestedObjects));
                    }
                    $resolvedType = $this->getDtoFqcn($dto);
                } else {
                    $resolvedType = null;
                }
            } else {
                $resolvedType = null;
            }
        }

        $param = $constructor->addPromotedParameter($normalizedKey);
        $param->setVisibility('public');

        if ($shouldMap) {
            $this->addMappingAttributes($namespace, $param, $key);
        }

        $phpType = $this->resolvePhpType($resolvedType);
        $docType = $resolvedType ?? 'mixed';

        if ($this->typed) {
            $param->setType($phpType);

            if ($isOptional) {
                $param->setNullable(true);
                $param->setDefaultValue(null);
            }
        }

        if ($phpType === 'mixed') {
            $this->addValidationAttributesForMixed($namespace, $param, $meta['values'][0] ?? null, $isOptional);
        }

        // Строковые форматы — берём первое строковое значение как образец
        if ($resolvedType === 'string') {
            $sampleString = null;
            foreach ($meta['values'] as $v) {
                if (is_string($v) && $v !== '') {
                    $sampleString = $v;
                    break;
                }
            }
            if ($sampleString !== null) {
                $this->addStringFormatAttributes($namespace, $param, $sampleString);
            }
        }

        if ($isOptional) {
            $constructor->addComment(sprintf('@param %s|null $%s', $docType, $normalizedKey));
        } else {
            $constructor->addComment(sprintf('@param %s $%s', $docType, $normalizedKey));
        }
    }

    /**
     * Обработка массивов в multipart-режиме.
     */
    private function addMergedCollectionProperty(
        PhpNamespace $namespace,
        ClassType    $class,
        Method       $constructor,
        string       $originalKey,
        string       $normalizedKey,
        array        $allValues,
        bool         $shouldMap,
        bool         $isOptional,
        string       $pathPrefix,
    ): bool {
        // Собираем все массивы из значений
        $allArrays = array_filter($allValues, 'is_array');
        if (empty($allArrays)) {
            return false;
        }

        // Собираем все элементы из всех массивов
        $allElements = [];
        foreach ($allArrays as $arr) {
            foreach ($arr as $elem) {
                $allElements[] = $elem;
            }
        }

        $types = collect($allElements)->map($this->getType(...));
        $uniqueTypes = $types->unique()->filter();

        if ($uniqueTypes->isEmpty() || $uniqueTypes->count() > 1 || $uniqueTypes->first() === 'array') {
            return false;
        }

        $type = $uniqueTypes->first();

        // Скалярные массивы
        if ($type !== 'object') {
            $param = $constructor->addPromotedParameter($normalizedKey);
            $param->setVisibility('public');

            if ($shouldMap) {
                $this->addMappingAttributes($namespace, $param, $originalKey);
            }

            if ($this->typed) {
                $param->setType('array');
                $param->setNullable(true);

                if ($isOptional) {
                    $param->setDefaultValue(null);
                }
            }

            $constructor->addComment(sprintf('@param %s[]|null $%s', $type, $normalizedKey));

            return true;
        }

        // Вложенные DTO-коллекции
        if ($this->nestingLevel >= self::MAX_NESTING_DEPTH) {
            return false;
        }

        // Проверяем консистентность структуры всех объектов из всех массивов
        $objectElements = array_filter($allElements, fn($v) => $v instanceof stdClass);
        if (empty($objectElements)) {
            return false;
        }

        $nestedPathPrefix = $pathPrefix ? $pathPrefix . '.' . $normalizedKey : $normalizedKey;

        // Для multipart — объединяем все объекты из всех массивов
        if (count($objectElements) > 1) {
            $dto = $this->addNestedDTOFromMultiple($namespace, $nestedPathPrefix, $objectElements);
        } else {
            if (!$this->membersHaveConsistentStructure($objectElements)) {
                return false;
            }
            $dto = $this->addNestedDTO($namespace, $nestedPathPrefix, reset($objectElements));
        }

        $dtoFqcn = $this->getDtoFqcn($dto);

        $param = $constructor->addPromotedParameter($normalizedKey);
        $param->setVisibility('public');

        if ($shouldMap) {
            $this->addMappingAttributes($namespace, $param, $originalKey);
        }

        if ($this->typed) {
            $param->setType('array');
            $param->setNullable(true);

            if ($isOptional) {
                $param->setDefaultValue(null);
            }
        }

        $namespace->addUse('Spatie\\LaravelData\\Attributes\\DataCollectionOf');
        $param->addAttribute('Spatie\\LaravelData\\Attributes\\DataCollectionOf', [$dtoFqcn]);

        $elementClassName = class_basename($dtoFqcn);
        $constructor->addComment(sprintf('@param %s[]|null $%s', $elementClassName, $normalizedKey));

        return true;
    }

    /**
     * Определяет единый тип из массива значений.
     * Если все значения одного типа — возвращает этот тип.
     * Если типы разные — null (mixed).
     * Если значений нет — null.
     */
    private function resolveTypeFromMultipleValues(array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        $types = collect($values)->map($this->getType(...))->filter()->unique();

        if ($types->count() === 1) {
            return $types->first();
        }

        // int и float — совместимы, приводим к float
        if ($types->count() === 2 && $types->contains('int') && $types->contains('float')) {
            return 'float';
        }

        return null;
    }

    /**
     * Создаёт вложенный DTO из нескольких объектов (multipart-merge).
     *
     * @param stdClass[] $objects
     */
    public function addNestedDTOFromMultiple(PhpNamespace $namespace, string $pathPrefix, array $objects): ClassType
    {
        $this->nestingLevel++;
        $merged = $this->mergeObjects($objects);
        $generatedDtoNamespace = $this->createClassFromMerged($this->baseNamespace, $merged, $this->buildClassName($pathPrefix), $pathPrefix);
        $generatedDto = array_values($generatedDtoNamespace->getClasses())[0];
        $this->nestingLevel--;

        if ($namespace->getName() !== $generatedDtoNamespace->getName()) {
            $namespace->addUse($generatedDtoNamespace->getName() . '\\' . $generatedDto->getName());
        }

        return $generatedDto;
    }

    public function createClass(
        string   $namespace,
        stdClass $source,
        ?string  $name,
        string   $pathPrefix,
    ): PhpNamespace {
        $extends = Data::class;

        $classNamespace = new PhpNamespace($namespace);
        $classNamespace->addUse($extends, 'SpatieLaravelDataObj');

        $className = $name ?? $this->buildClassName($pathPrefix);
        $class = $classNamespace->addClass(str_replace(' ', '', $className));
        $class->addExtend($extends);

        $constructor = $class->addMethod('__construct');

        foreach ($source as $key => $value) {
            $this->addConstructorProperty($classNamespace, $class, $constructor, $key, $value, $pathPrefix);
        }

        $this->classes[] = $classNamespace;

        return $classNamespace;
    }

    /**
     * Строит имя класса из пути в CamelCase.
     * Например: "hotelImageList.image" → "HotelImageListImage"
     */
    private function buildClassName(string $pathPrefix): string
    {
        if (empty($pathPrefix)) {
            return 'JsonDataTransferObject';
        }

        $parts = explode('.', $pathPrefix);
        $className = implode('', array_map(fn($part) => Str::studly($part), $parts));

        return $className ?: 'JsonDataTransferObject';
    }

    /**
     * Нормализует ключ JSON в валидное имя PHP-переменной в camelCase.
     */
    private function normalizePropertyName(string $key): ?string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9_\s\-]/', ' ', $key);
        $camel = Str::camel(trim($cleaned));
        $camel = ltrim($camel, '0123456789');

        if ($camel === '') {
            return null;
        }

        return $camel;
    }

    /**
     * Проверяет, нужно ли маппить ключ.
     */
    private function needsMapping(string $originalKey, string $normalizedKey): bool
    {
        return $originalKey !== $normalizedKey;
    }

    private function addConstructorProperty(
        PhpNamespace $namespace,
        ClassType    $class,
        Method       $constructor,
        string       $key,
        mixed        $value,
        string       $pathPrefix,
    ): void {
        $type = $this->getType($value);

        $normalizedKey = $this->normalizePropertyName($key);

        if ($normalizedKey === null) {
            return;
        }

        $shouldMap = $this->needsMapping($key, $normalizedKey);

        if ($type === 'array') {
            $result = $this->addCollectionConstructorProperty($namespace, $class, $constructor, $key, $normalizedKey, $value, $shouldMap, $pathPrefix);

            if ($result) {
                return;
            }

            $type = null;
        }

        if ($type === 'object') {
            $type = null;
            if ($this->nestingLevel < self::MAX_NESTING_DEPTH) {
                $nestedPathPrefix = $pathPrefix ? $pathPrefix . '.' . $normalizedKey : $normalizedKey;
                $dto = $this->addNestedDTO($namespace, $nestedPathPrefix, $value);
                $type = $this->getDtoFqcn($dto);
            }
        }

        $param = $constructor->addPromotedParameter($normalizedKey);
        $param->setVisibility('public');

        if ($shouldMap) {
            $this->addMappingAttributes($namespace, $param, $key);
        }

        $phpType = $this->resolvePhpType($type);
        $docType = $type ?? 'mixed';

        if ($this->typed) {
            $param->setType($phpType);

            if ($this->optional) {
                $param->setNullable(true);
                $param->setDefaultValue(null);
            }
        }

        if ($phpType === 'mixed') {
            $this->addValidationAttributesForMixed($namespace, $param, $value);
        }

        if ($type === 'string') {
            $this->addStringFormatAttributes($namespace, $param, $value);
        }

        if ($this->optional) {
            $constructor->addComment(sprintf('@param %s|null $%s', $docType, $normalizedKey));
        } else {
            $constructor->addComment(sprintf('@param %s $%s', $docType, $normalizedKey));
        }
    }

    public function addCollectionConstructorProperty(
        PhpNamespace $namespace,
        ClassType    $class,
        Method       $constructor,
        string       $originalKey,
        string       $normalizedKey,
        array        $values,
        bool         $shouldMap,
        string       $pathPrefix,
    ): bool {
        $types = collect($values)->map($this->getType(...));

        $uniqueTypes = $types->unique()->filter();

        if ($uniqueTypes->isEmpty() || $uniqueTypes->count() > 1 || $uniqueTypes->first() === 'array') {
            return false;
        }

        $type = $uniqueTypes->first();

        if ($type !== 'object') {
            $param = $constructor->addPromotedParameter($normalizedKey);
            $param->setVisibility('public');

            if ($shouldMap) {
                $this->addMappingAttributes($namespace, $param, $originalKey);
            }

            if ($this->typed) {
                $param->setType('array');
                $param->setNullable(true);

                if ($this->optional) {
                    $param->setDefaultValue(null);
                }
            }

            // Добавляем Spatie атрибут для скалярных массивов
            $this->addScalarCollectionAttribute($namespace, $param, $type);

            $constructor->addComment(sprintf('@param %s[]|null $%s', $type, $normalizedKey));

            return true;
        }

        if ($this->nestingLevel >= self::MAX_NESTING_DEPTH) {
            return false;
        }

        if (!$this->membersHaveConsistentStructure($values)) {
            return false;
        }

        $nestedPathPrefix = $pathPrefix ? $pathPrefix . '.' . $normalizedKey : $normalizedKey;

        // Если несколько объектов в массиве, создаём класс элемента через multipart
        if (count($values) > 1) {
            $dto = $this->addNestedDTOFromMultiple($namespace, $nestedPathPrefix, $values);
        } else {
            $dto = $this->addNestedDTO($namespace, $nestedPathPrefix, reset($values));
        }

        $dtoFqcn = $this->getDtoFqcn($dto);

        $param = $constructor->addPromotedParameter($normalizedKey);
        $param->setVisibility('public');

        if ($shouldMap) {
            $this->addMappingAttributes($namespace, $param, $originalKey);
        }

        if ($this->typed) {
            $param->setType('array');
            $param->setNullable(true);

            if ($this->optional) {
                $param->setDefaultValue(null);
            }
        }

        $namespace->addUse('Spatie\\LaravelData\\Attributes\\DataCollectionOf');
        $param->addAttribute('Spatie\\LaravelData\\Attributes\\DataCollectionOf', [$dtoFqcn]);

        // Улучшенный PHPDoc с указанием типа элемента массива
        $elementClassName = class_basename($dtoFqcn);
        $constructor->addComment(sprintf('@param %s[]|null $%s', $elementClassName, $normalizedKey));

        return true;
    }

    public function addNestedDTO(PhpNamespace $namespace, string $pathPrefix, stdClass $object): ClassType
    {
        $this->nestingLevel++;
        $generatedDtoNamespace = $this->createClass($this->baseNamespace, $object, $this->buildClassName($pathPrefix), $pathPrefix);
        $generatedDto = array_values($generatedDtoNamespace->getClasses())[0];
        $this->nestingLevel--;

        if ($namespace->getName() !== $generatedDtoNamespace->getName()) {
            $namespace->addUse($generatedDtoNamespace->getName() . '\\' . $generatedDto->getName());
        }

        return $generatedDto;
    }

    public function membersHaveConsistentStructure(array $values): bool
    {
        return collect($values)
                ->map(fn(stdClass $object): array => array_keys(get_object_vars($object)))
                ->unique()
                ->count() === 1;
    }

    public function getType(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => 'string',
            $value instanceof stdClass => 'object',
            is_array($value) => 'array',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            default => null,
        };
    }

    /**
     * Преобразует внутренний тип в PHP type hint.
     */
    private function resolvePhpType(?string $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if (in_array($type, ['string', 'int', 'float', 'bool', 'array'], true)) {
            return $type;
        }

        if (str_starts_with($type, '\\')) {
            return $type;
        }

        return 'mixed';
    }

    /**
     * Добавляет атрибуты MapInputName и MapOutputName.
     */
    private function addMappingAttributes(
        PhpNamespace     $namespace,
        PromotedParameter $param,
        string           $originalKey,
    ): void {
        $mapInputClass = 'Spatie\\LaravelData\\Attributes\\MapInputName';
        $mapOutputClass = 'Spatie\\LaravelData\\Attributes\\MapOutputName';

        $namespace->addUse($mapInputClass);
        $namespace->addUse($mapOutputClass);

        $param->addAttribute($mapInputClass, [$originalKey]);
        $param->addAttribute($mapOutputClass, [$originalKey]);
    }

    /**
     * Добавляет атрибут для скалярных коллекций.
     * Например: #[ArrayOf(StringType::class)] для массивов строк
     */
    private function addScalarCollectionAttribute(
        PhpNamespace     $namespace,
        PromotedParameter $param,
        string           $scalarType,
    ): void {
        // Маппинг типов на Spatie классы
        $typeMap = [
            'string' => 'Spatie\\LaravelData\\Attributes\\Validation\\StringType',
            'int' => 'Spatie\\LaravelData\\Attributes\\Validation\\IntegerType',
            'float' => 'Spatie\\LaravelData\\Attributes\\Validation\\NumericType',
            'bool' => 'Spatie\\LaravelData\\Attributes\\Validation\\BooleanType',
        ];

        if (!isset($typeMap[$scalarType])) {
            return;
        }

        $typeClass = $typeMap[$scalarType];
        $namespace->addUse('Spatie\\LaravelData\\Attributes\\Validation\\ArrayType');
        $namespace->addUse($typeClass);

        // Добавляем атрибут #[ArrayType(StringType::class)]
        $param->addAttribute('Spatie\\LaravelData\\Attributes\\Validation\\ArrayType', [
            class_basename($typeClass) . '::class'
        ]);
    }

    /**
     * Добавляет атрибуты валидации для mixed-типов.
     * Перегрузка с поддержкой явного isOptional для multipart.
     */
    private function addValidationAttributesForMixed(
        PhpNamespace     $namespace,
        PromotedParameter $param,
        mixed            $value,
        ?bool            $isOptional = null,
    ): void {
        $validationNs = 'Spatie\\LaravelData\\Attributes\\Validation';
        $effectiveOptional = $isOptional ?? $this->optional;

        if ($value === null || $effectiveOptional) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Nullable');
        } else {
            $this->addAttribute($namespace, $param, $validationNs . '\\Required');
        }
    }

    /**
     * Для строк определяем формат по значению.
     */
    private function addStringFormatAttributes(
        PhpNamespace     $namespace,
        PromotedParameter $param,
        mixed            $value,
    ): void {
        if (!is_string($value) || $value === '') {
            return;
        }

        $validationNs = 'Spatie\\LaravelData\\Attributes\\Validation';

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Email');
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Url');
            return;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Uuid');
            return;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) && strtotime($value) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\DateFormat', ['Y-m-d\\TH:i:sP']);
            return;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\DateFormat', ['Y-m-d']);
            return;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\IP');
            return;
        }
    }

    private function addAttribute(
        PhpNamespace     $namespace,
        PromotedParameter $param,
        string           $attributeClass,
        array            $arguments = [],
    ): void {
        $namespace->addUse($attributeClass);
        $param->addAttribute($attributeClass, $arguments);
    }

    private function getDtoFqcn(ClassType $dto): string
    {
        return sprintf('\\%s\\%s', $dto->getNamespace()->getName(), $dto->getName());
    }
}
