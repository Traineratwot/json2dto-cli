<?php

declare(strict_types=1);

namespace Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Spatie\Snapshots\MatchesSnapshots;
use stdClass;
use Traineratwot\Json2Dto\Generator\DtoGenerator;
use Traineratwot\Json2Dto\Helpers\NamespaceFolderResolver;

class DtoGeneratorTest extends TestCase
{
    use MatchesSnapshots;

    public function testGeneratesNonNestedDocblockDto()
    {
        $generator = new DtoGenerator('App\DTO', false, false, false);

        $generator->generate($this->arrayToStdClass([
            'string' => 'string',
            'int' => 1,
            'float' => 1.1,
            'string_array' => [
                'a',
                'b',
            ],
            'int_array' => [
                1,
                2,
            ],
            'empty_array' => [],
            'nested_array' => [
                'a' => ['b' => 'c'],
            ],
        ]), 'TestDTO');

        $files = $generator->getFiles(new NamespaceFolderResolver());
        $this->assertCount(1, $files);
        $this->assertMatchesJsonSnapshot($files);
    }

    public function testGeneratesNestedDocblockDto()
    {
        $generator = new DtoGenerator('App\DTO', true, false, false);

        $generator->generate($this->arrayToStdClass([
            'string' => 'string',
            'int' => 1,
            'float' => 1.1,
            'string_array' => [
                'a',
                'b',
            ],
            'int_array' => [
                1,
                2,
            ],
            'empty_array' => [],
            'nested_dto' => [
                'nested_int_array' => [
                    1,
                    2,
                ],
                'nested_nested_dto' => ['b' => 'c'],
            ],
        ]), 'TestDTO');

        $files = $generator->getFiles(new NamespaceFolderResolver());

        $this->assertCount(3, $files);

        $this->assertSame([
            'App/DTO/NestedNestedDto.php',
            'App/DTO/NestedDto.php',
            'App/DTO/TestDTO.php',
        ], array_keys($files));

        $this->assertMatchesJsonSnapshot($files);
    }

    public function testGeneratesNestedTypedDto()
    {
        $generator = new DtoGenerator('App\DTO', true, true, false);

        $generator->generate($this->arrayToStdClass([
            'string' => 'string',
            'int' => 1,
            'float' => 1.1,
            'string_array' => [
                'a',
                'b',
            ],
            'int_array' => [
                1,
                2,
            ],
            'empty_array' => [],
            'nested_dto' => [
                'nested_nested_dto' => ['b' => 'c'],
            ],
        ]), 'TestDTO');

        $files = $generator->getFiles(new NamespaceFolderResolver());

        $this->assertCount(3, $files);

        $this->assertSame([
            'App/DTO/NestedNestedDto.php',
            'App/DTO/NestedDto.php',
            'App/DTO/TestDTO.php',
        ], array_keys($files));

        $this->assertMatchesJsonSnapshot($files);
    }

    public function testGeneratesOptional()
    {
        $generator = new DtoGenerator('App\DTO', false, true, true);

        $generator->generate($this->arrayToStdClass([
            'string' => 'string',
            'int' => 1,
            'float' => 1.1,
            'string_array' => [
                'a',
                'b',
            ],
            'int_array' => [
                1,
                2,
            ],
            'empty_array' => [],
            'nested_array' => [
                'a' => ['b' => 'c'],
            ],
        ]), 'TestDTO');

        $files = $generator->getFiles(new NamespaceFolderResolver());
        $this->assertCount(1, $files);
        $this->assertMatchesJsonSnapshot($files);
    }

    public function testGeneratesMultipartNestedTypedDto()
    {
        $generator = new DtoGenerator(
            baseNamespace: 'App\DTO',
            nested: true,
            typed: true,
            optional: false,
            multipart: true,
        );

        $sources = $this->arrayToStdClassArray([
            // Вариант 1: базовые поля
            [
                'id' => 1,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'address' => [
                    'city' => 'Moscow',
                    'street' => 'Tverskaya',
                ],
            ],
            // Вариант 2: часть полей совпадает, часть — новые, часть — отсутствует
            [
                'id' => 2,
                'name' => 'Bob',
                'phone' => '+71234567890',
                'is_active' => true,
                'address' => [
                    'city' => 'SPb',
                    'zip' => '190000',
                ],
            ],
            // Вариант 3: другой набор полей, null-значение
            [
                'id' => 3,
                'name' => null,
                'score' => 95.5,
                'tags' => ['vip', 'new'],
                'address' => [
                    'city' => 'Kazan',
                    'country' => 'Russia',
                ],
            ],
        ]);

        $generator->generateMultipart($sources, 'UserDTO');

        $files = $generator->getFiles(new NamespaceFolderResolver());

        // Должен быть UserDTO + вложенный Address DTO = 2 файла
        $this->assertCount(2, $files);

        $fileKeys = array_keys($files);

        $this->assertSame([
            'App/DTO/Address.php',
            'App/DTO/UserDTO.php',
        ], $fileKeys);

        // Проверяем, что результирующий DTO содержит ВСЕ поля из всех вариантов
        $userDtoContent = $files['App/DTO/UserDTO.php'];

        // Поля из всех вариантов
        $this->assertStringContainsString('$id', $userDtoContent);         // во всех 3
        $this->assertStringContainsString('$name', $userDtoContent);       // во всех 3, но в 3-м null
        $this->assertStringContainsString('$email', $userDtoContent);      // только в 1-м
        $this->assertStringContainsString('$phone', $userDtoContent);      // только во 2-м
        $this->assertStringContainsString('$isActive', $userDtoContent);   // только во 2-м
        $this->assertStringContainsString('$score', $userDtoContent);      // только в 3-м
        $this->assertStringContainsString('$tags', $userDtoContent);       // только в 3-м
        $this->assertStringContainsString('$address', $userDtoContent);    // во всех 3

        // Поля, которые есть не во всех объектах, должны быть nullable
        $this->assertStringContainsString('?string $email', $userDtoContent);
        $this->assertStringContainsString('?string $phone', $userDtoContent);
        $this->assertStringContainsString('?bool $isActive', $userDtoContent);
        $this->assertStringContainsString('?float $score', $userDtoContent);
        $this->assertStringContainsString('?array $tags', $userDtoContent);

        // name присутствует во всех, но в одном null — тоже nullable
        $this->assertStringContainsString('?string $name', $userDtoContent);

        // id присутствует во всех и никогда не null — не nullable
        $this->assertStringContainsString('int $id', $userDtoContent);
        $this->assertStringNotContainsString('?int $id', $userDtoContent);

        // Вложенный Address DTO должен содержать все поля из всех вариантов address
        $addressDtoContent = $files['App/DTO/Address.php'];
        $this->assertStringContainsString('$city', $addressDtoContent);      // во всех 3
        $this->assertStringContainsString('$street', $addressDtoContent);    // только в 1-м
        $this->assertStringContainsString('$zip', $addressDtoContent);       // только во 2-м
        $this->assertStringContainsString('$country', $addressDtoContent);   // только в 3-м

        // city во всех — не nullable
        $this->assertStringContainsString('string $city', $addressDtoContent);
        $this->assertStringNotContainsString('?string $city', $addressDtoContent);

        // street, zip, country — не во всех, nullable
        $this->assertStringContainsString('?string $street', $addressDtoContent);
        $this->assertStringContainsString('?string $zip', $addressDtoContent);
        $this->assertStringContainsString('?string $country', $addressDtoContent);

        $this->assertMatchesJsonSnapshot($files);
    }

    private function arrayToStdClass(array $data): ?stdClass
    {
        return json_decode(json_encode($data));
    }

    /**
     * @param array[] $dataArray
     * @return stdClass[]
     */
    private function arrayToStdClassArray(array $dataArray): array
    {
        return array_map(
            fn(array $item): stdClass => json_decode(json_encode($item)),
            $dataArray,
        );
    }
}
