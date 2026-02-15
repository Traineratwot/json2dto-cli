## CLI Tool

Prefer to use the tool locally? You can install `json2dto` via composer and generate files directly from json files.

```bash
composer global require traineratwot/json2dto # Install Globally

composer require traineratwot/json2dto --dev # Install locally in a project
```

### Usage

The tool accepts json input either as a filename (second argument) or via `stdin`.  
You should run the tool in the root of your project (where your `composer.json` is located) as it will resolve namespaces
based on your PSR4 autoloading config. If you aren't using PSR4, your generated folder structure might not match.

#### Examples

```bash
# Generate PHP 7.4 typed DTO
./vendor/bin/json2dto generate "App\DTO" test.json -name "Test" --typed

# Generate PHP 8.0 typed DTO (DTO V3)
./vendor/bin/json2dto generate "App\DTO" test.json -name "Test" --v3

# Generate a flexible DTO (with nested DTOs)
./vendor/bin/json2dto generate "App\DTO" test.json -name "Test" --nested --flexible

# Generate a DTO from stdin
wget http://example.com/cat.json | ./vendor/bin/json2dto generate "App\DTO" -name Cat
```

#### Usage
```
json2dto generate [options] [--] <namespace> [<json>]

Arguments:
  namespace                       Namespace to generate the class(es) in
  json                            File containing the json string

Options:
      --nested                    Generate nested DTOs
      --typed                     Generate PHP >= 7.4 strict typing
      --flexible                  Generate a flexible DTO
      --dry                       Dry run, print generated files
  -h, --help                      Display this help message
```
