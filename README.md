# Json2Dto

Инструмент для генерации DTO-классов на основе JSON-данных с поддержкой вложенных DTO, сборки multipart-структур и валидационных атрибутов от Spatie Laravel Data.

## Установка / Installation

```bash
# Установить глобально
composer global require traineratwot/json2dto

# Установить в проекте (для локального использования)
composer require traineratwot/json2dto --dev
```

## Использование / Usage

Запускайте инструмент из корня проекта (там, где находится `composer.json`), чтобы правильно разрешить пространства имён через PSR-4. Ввод JSON принимается из файла (второй аргумент) или из stdin.

```bash
json2dto generate [options] [--] <namespace> [<json>]
```

### Примеры / Examples

```bash
# Генерация DTO с PHP 7.4 типизацией
./vendor/bin/json2dto generate "App\DTO" test.json --classname "Test" --typed

# Генерация DTO с вложенными DTO и всеми полями nullable
./vendor/bin/json2dto generate "App\DTO" test.json --classname "Test" --nested --optional

# Генерация объединённого DTO для массива объектов (multipart)
./vendor/bin/json2dto generate "App\DTO" tests.json --classname "TestCollection" --multipart

# Использование stdin
cat test.json | ./vendor/bin/json2dto generate "App\DTO" --classname "Test"
```

## Опции / Options

| Опция | Описание / Description |
| --- | --- |
| `--classname`, `-name` | Название корневого DTO-класса (`NewDto` по умолчанию) / Root DTO class name (`NewDto` by default) |
| `--nested` | Генерировать вложенные DTO для объектов и массивов объектов / Generate nested DTOs for nested objects and collections |
| `--typed` | Использовать PHP-типы для свойств конструктора (>=7.4) / Enable PHP typed properties (>=7.4) |
| `--optional` | Сделать все поля nullable с значением по умолчанию `null` / Make every field optional (nullable with default `null`) |
| `--multipart` | Слить массив объектов в один DTO, объединяя все поля / Merge array of objects into a single DTO covering all variants |
| `--dry` | Показать сгенерированные файлы вместо записи на диск / Dry run — print generated files instead of writing them |

## Поведение / Behavior

- В режиме `--multipart` входной JSON должен быть массивом объектов. Генерируется DTO, где каждое поле становится опциональным, если отсутствует в каком-то объекте или встречалось как `null`.
- Ключи, не соответствующие camelCase, автоматически нормализуются, а оригинальные имена мапятся через атрибуты `MapInputName`/`MapOutputName`.
- При генерации без `--nested` вложенные объекты остаются типизированными как `mixed`, но при включении `--typed` добавляются соответствующие типы и атрибуты валидации от Spatie Laravel Data.
- Строки анализируются на наличие email, URL, UUID, ISO-дат и IP, чтобы добавить соответствующие атрибуты валидации.

## Примечания / Notes

- Инструмент работает с PSR-4 пространства имён и создает файлы согласно разрешению namespace → папка. Если в проекте нет `composer.json`, файлы будут помещены в текущую директорию.
- Для работы генерации атрибутов требуется установленный пакет `spatie/laravel-data`.
