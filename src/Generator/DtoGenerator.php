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

    public function __construct(
        private readonly string $baseNamespace,
        private readonly bool $nested,
        private readonly bool $typed,
        private readonly bool $flexible,
    ) {}

    public function generate(stdClass $source, ?string $name): void
    {
        $this->createClass($this->baseNamespace, $source, $name);
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

    public function createClass(
        string $namespace,
        stdClass $source,
        ?string $name,
    ): PhpNamespace {
        $extends = Data::class;

        $classNamespace = new PhpNamespace($namespace);
        $classNamespace->addUse($extends);

        $class = $classNamespace->addClass(str_replace(' ', '', $name ?? 'JsonDataTransferObject'));
        $class->addExtend($extends);

        $constructor = $class->addMethod('__construct');

        foreach ($source as $key => $value) {
            $this->addConstructorProperty($classNamespace, $class, $constructor, $key, $value);
        }

        $this->classes[] = $classNamespace;

        return $classNamespace;
    }

    /**
     * Нормализует ключ JSON в валидное имя PHP-переменной в camelCase.
     *
     * Убирает все символы, невалидные для PHP-переменных,
     * затем приводит результат к camelCase.
     *
     * Примеры:
     *   "@Name"       -> "name"
     *   "@HowToReach" -> "howToReach"
     *   "some-key"    -> "someKey"
     *   "some_key"    -> "someKey"
     *   "WorkPeriod"  -> "workPeriod"
     *   "123bad"      -> "bad" (цифры в начале убираются)
     */
    private function normalizePropertyName(string $key): ?string
    {
        // Убираем все символы, невалидные для PHP-переменной (оставляем буквы, цифры, _, пробелы и дефисы для разбиения)
        $cleaned = preg_replace('/[^a-zA-Z0-9_\s\-]/', ' ', $key);

        // Приводим к camelCase через Str::camel (он обрабатывает пробелы, дефисы, подчёркивания)
        $camel = Str::camel(trim($cleaned));

        // Убираем цифры в начале (невалидно для PHP)
        $camel = ltrim($camel, '0123456789');

        if ($camel === '') {
            return null;
        }

        return $camel;
    }

    /**
     * Проверяет, нужно ли маппить ключ (оригинальный ключ отличается от нормализованного).
     */
    private function needsMapping(string $originalKey, string $normalizedKey): bool
    {
        return $originalKey !== $normalizedKey;
    }

    private function addConstructorProperty(
        PhpNamespace $namespace,
        ClassType $class,
        Method $constructor,
        string $key,
        mixed $value,
    ): void {
        $type = $this->getType($value);

        // Нормализуем имя свойства
        $normalizedKey = $this->normalizePropertyName($key);

        if ($normalizedKey === null) {
            // Невозможно создать валидное имя переменной
            return;
        }

        $shouldMap = $this->needsMapping($key, $normalizedKey);

        if ($type === 'array') {
            $result = $this->addCollectionConstructorProperty($namespace, $class, $constructor, $key, $normalizedKey, $value, $shouldMap);

            if ($result) {
                return;
            }

            // Не удалось определить тип элементов — mixed
            $type = null;
        }

        if ($type === 'object') {
            $type = null;
            if ($this->nested && NameValidator::validateClassName($normalizedKey)) {
                $dto = $this->addNestedDTO($namespace, $normalizedKey, $value);
                $type = $this->getDtoFqcn($dto);
            }
        }

        $param = $constructor->addPromotedParameter($normalizedKey);
        $param->setVisibility('public');

        // Добавляем MapInputName/MapOutputName если оригинальный ключ отличается
        if ($shouldMap) {
            $this->addMappingAttributes($namespace, $param, $key);
        }

        $phpType = $this->resolvePhpType($type);
        $docType = $type ?? 'mixed';

        if ($this->typed) {
            $param->setType($phpType);
        }

        // Для примитивов PHP сам валидирует через type hints — атрибуты не нужны.
        // Атрибуты добавляем только там, где PHP type hint недостаточен.
        if ($phpType === 'mixed') {
            $this->addValidationAttributesForMixed($namespace, $param, $value);
        }

        // Добавляем строковые валидации (email, url, uuid и т.д.) как атрибуты,
        // потому что PHP type hint string не покрывает формат.
        if ($type === 'string') {
            $this->addStringFormatAttributes($namespace, $param, $value);
        }

        // PHPDoc всегда пишем для IDE
        $constructor->addComment(sprintf('@param %s $%s', $docType, $normalizedKey));
    }

    public function addCollectionConstructorProperty(
        PhpNamespace $namespace,
        ClassType $class,
        Method $constructor,
        string $originalKey,
        string $normalizedKey,
        array $values,
        bool $shouldMap,
    ): bool {
        $types = collect($values)->map($this->getType(...));

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
            }

            // PHPDoc для IDE — указываем тип элементов
            $constructor->addComment(sprintf('@param %s[]|null $%s', $type, $normalizedKey));

            return true;
        }

        // Вложенные DTO
        if (!$this->nested) {
            return false;
        }

        if (!$this->membersHaveConsistentStructure($values)) {
            return false;
        }

        if (!NameValidator::validateClassName($normalizedKey)) {
            return false;
        }

        $dto = $this->addNestedDTO($namespace, $normalizedKey, reset($values));
        $dtoFqcn = $this->getDtoFqcn($dto);

        $param = $constructor->addPromotedParameter($normalizedKey);
        $param->setVisibility('public');

        if ($shouldMap) {
            $this->addMappingAttributes($namespace, $param, $originalKey);
        }

        if ($this->typed) {
            $param->setType('array');
            $param->setNullable(true);
        }

        // DataCollectionOf нужен, потому что PHP не может типизировать "массив конкретных DTO"
        $namespace->addUse('Spatie\\LaravelData\\Attributes\\DataCollectionOf');
        $param->addAttribute('Spatie\\LaravelData\\Attributes\\DataCollectionOf', [$dtoFqcn]);

        $constructor->addComment(sprintf('@param %s[]|null $%s', $dtoFqcn, $normalizedKey));

        return true;
    }

    public function addNestedDTO(PhpNamespace $namespace, string $name, stdClass $object): ClassType
    {
        $generatedDtoNamespace = $this->createClass($this->baseNamespace, $object, Str::studly($name));
        $generatedDto = array_values($generatedDtoNamespace->getClasses())[0];

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
     * Примитивы (string, int, float, bool) и конкретные классы — напрямую.
     * Всё остальное — mixed.
     */
    private function resolvePhpType(?string $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        // Примитивы PHP поддерживает нативно
        if (in_array($type, ['string', 'int', 'float', 'bool', 'array'], true)) {
            return $type;
        }

        // FQCN вложенного DTO
        if (str_starts_with($type, '\\')) {
            return $type;
        }

        return 'mixed';
    }

    /**
     * Добавляет атрибуты MapInputName и MapOutputName для маппинга
     * оригинального ключа JSON на нормализованное имя PHP-свойства.
     */
    private function addMappingAttributes(
        PhpNamespace $namespace,
        PromotedParameter $param,
        string $originalKey,
    ): void {
        $mapInputClass = 'Spatie\\LaravelData\\Attributes\\MapInputName';
        $mapOutputClass = 'Spatie\\LaravelData\\Attributes\\MapOutputName';

        $namespace->addUse($mapInputClass);
        $namespace->addUse($mapOutputClass);

        $param->addAttribute($mapInputClass, [$originalKey]);
        $param->addAttribute($mapOutputClass, [$originalKey]);
    }

    /**
     * Добавляет атрибуты валидации только для mixed-типов,
     * где PHP type hint не может помочь.
     */
    private function addValidationAttributesForMixed(
        PhpNamespace $namespace,
        PromotedParameter $param,
        mixed $value,
    ): void {
        $validationNs = 'Spatie\\LaravelData\\Attributes\\Validation';

        if ($value === null) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Nullable');
        } else {
            $this->addAttribute($namespace, $param, $validationNs . '\\Required');
        }
    }

    /**
     * Для строк определяем формат по значению и добавляем соответствующий атрибут.
     * PHP type hint `string` не покрывает формат (email, url, uuid и т.д.).
     */
    private function addStringFormatAttributes(
        PhpNamespace $namespace,
        PromotedParameter $param,
        mixed $value,
    ): void {
        if (!is_string($value) || $value === '') {
            return;
        }

        $validationNs = 'Spatie\\LaravelData\\Attributes\\Validation';

        // Email
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Email');
            return;
        }

        // URL
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Url');
            return;
        }

        // UUID
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            $this->addAttribute($namespace, $param, $validationNs . '\\Uuid');
            return;
        }

        // ISO 8601 datetime
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) && strtotime($value) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\DateFormat', ['Y-m-d\\TH:i:sP']);
            return;
        }

        // ISO date (без времени)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\DateFormat', ['Y-m-d']);
            return;
        }

        // IP address
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            $this->addAttribute($namespace, $param, $validationNs . '\\IP');
            return;
        }
    }

    private function addAttribute(
        PhpNamespace $namespace,
        PromotedParameter $param,
        string $attributeClass,
        array $arguments = [],
    ): void {
        $namespace->addUse($attributeClass);
        $param->addAttribute($attributeClass, $arguments);
    }

    private function getDtoFqcn(ClassType $dto): string
    {
        return sprintf('\\%s\\%s', $dto->getNamespace()->getName(), $dto->getName());
    }
}
