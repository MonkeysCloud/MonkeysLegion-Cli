<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Service;

use MonkeysLegion\Cli\Config\RelationKind;
use MonkeysLegion\Cli\Service\ClassManipulator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Service\ClassManipulator
 */
final class ClassManipulatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ml_cli_test_' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function createTestFile(string $name = 'TestEntity'): string
    {
        $file = $this->tmpDir . "/{$name}.php";
        $code = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Entity;

            use MonkeysLegion\\Entity\\Attributes\\Entity;
            use MonkeysLegion\\Entity\\Attributes\\Field;
            use MonkeysLegion\\Entity\\Attributes\\Id;

            #[Entity]
            class {$name}
            {
                #[Id]
                #[Field(type: 'unsignedBigInt', autoIncrement: true)]
                public int \$id;

                public function __construct()
                {
                }
            }
            PHP;
        file_put_contents($file, $code);

        return $file;
    }

    // ── Construction ──────────────────────────────────────────

    public function testConstructWithValidFile(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $this->assertInstanceOf(ClassManipulator::class, $m);
    }

    public function testConstructWithMissingFile(): void
    {
        $this->expectOutputString("Failed to parse file: /nonexistent/file.php\n");
        $m = new ClassManipulator('/nonexistent/file.php');
        $this->assertInstanceOf(ClassManipulator::class, $m);
    }

    // ── addScalarField ────────────────────────────────────────

    public function testAddScalarFieldString(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('name', 'string', 'string');
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString("Field(type: 'string')", $content);
        $this->assertStringContainsString('public string $name', $content);
    }

    public function testAddScalarFieldNullable(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('email', 'string', 'string', nullable: true);
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('nullable: true', $content);
        $this->assertStringContainsString('?string $email', $content);
        $this->assertStringContainsString('= null', $content);
    }

    public function testAddScalarFieldInteger(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('age', 'integer', 'int');
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString("Field(type: 'integer')", $content);
        $this->assertStringContainsString('public int $age', $content);
    }

    public function testAddScalarFieldBoolean(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('active', 'boolean', 'bool');
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString("Field(type: 'boolean')", $content);
        $this->assertStringContainsString('public bool $active', $content);
    }

    public function testAddScalarFieldUuid(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('externalId', 'uuid', 'string');
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString("Field(type: 'uuid')", $content);
        $this->assertStringContainsString('#[Uuid]', $content);
    }

    public function testNoGetterSetterForScalarField(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('name', 'string', 'string');
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        // v2: no getter/setter for scalar fields
        $this->assertStringNotContainsString('function getName', $content);
        $this->assertStringNotContainsString('function setName', $content);
    }

    // ── addRelation ───────────────────────────────────────────

    public function testAddManyToOneRelation(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addRelation('category', RelationKind::MANY_TO_ONE, 'Category', true);
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('ManyToOne', $content);
        $this->assertStringContainsString('Category::class', $content);
        $this->assertStringContainsString('$category', $content);
    }

    public function testAddOneToManyRelationCreatesCollectionMethods(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addRelation('posts', RelationKind::ONE_TO_MANY, 'Post', false);
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('OneToMany', $content);
        $this->assertStringContainsString('array $posts', $content);
        $this->assertStringContainsString('function addPost', $content);
        $this->assertStringContainsString('function removePost', $content);
        $this->assertStringContainsString('function getPosts', $content);
    }

    public function testAddManyToManyWithJoinTable(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $joinTable = new \MonkeysLegion\Entity\Attributes\JoinTable(
            name: 'user_role',
            joinColumn: 'user_id',
            inverseColumn: 'role_id',
        );

        $m->addRelation(
            'roles',
            RelationKind::MANY_TO_MANY,
            'Role',
            true,
            joinTable: $joinTable,
        );
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('ManyToMany', $content);
        $this->assertStringContainsString('JoinTable', $content);
        $this->assertStringContainsString('user_role', $content);
    }

    public function testAddRelationWithMappedBy(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addRelation('author', RelationKind::MANY_TO_ONE, 'User', true, 'posts');
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString("inversedBy: 'posts'", $content);
    }

    public function testAddRelationNullable(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addRelation('manager', RelationKind::MANY_TO_ONE, 'User', true, nullable: true);
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('?User $manager', $content);
        $this->assertStringContainsString('= null', $content);
    }

    // ── Multiple fields ───────────────────────────────────────

    public function testMultipleFieldsAndRelations(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('name', 'string', 'string');
        $m->addScalarField('email', 'string', 'string', true);
        $m->addRelation('posts', RelationKind::ONE_TO_MANY, 'Post', false);
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('$name', $content);
        $this->assertStringContainsString('$email', $content);
        $this->assertStringContainsString('$posts', $content);
    }

    // ── Save output ───────────────────────────────────────────

    public function testSaveProducesValidPhp(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('title', 'string', 'string');
        $m->addScalarField('body', 'text', 'string', true);
        $m->save();

        $output = shell_exec("php -l {$file} 2>&1");
        $this->assertStringContainsString('No syntax errors', $output ?: '');
    }

    public function testSavedFileStartsWithPhpTag(): void
    {
        $file = $this->createTestFile();
        $m    = new ClassManipulator($file);

        $m->addScalarField('x', 'string', 'string');
        $m->save();

        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringStartsWith('<?php', $content);
    }

    // ── toEnum ────────────────────────────────────────────────

    public function testToEnumFromRelationKind(): void
    {
        $this->assertSame(
            RelationKind::ONE_TO_MANY,
            ClassManipulator::toEnum(RelationKind::ONE_TO_MANY),
        );
    }

    public function testToEnumFromString(): void
    {
        $this->assertSame(RelationKind::ONE_TO_ONE, ClassManipulator::toEnum('OneToOne'));
        $this->assertSame(RelationKind::MANY_TO_MANY, ClassManipulator::toEnum('ManyToMany'));
    }

    public function testToEnumFromNormalized(): void
    {
        $this->assertSame(RelationKind::ONE_TO_MANY, ClassManipulator::toEnum('oneToMany'));
        $this->assertSame(RelationKind::MANY_TO_ONE, ClassManipulator::toEnum('manyToOne'));
    }

    public function testToEnumFromWeirdInput(): void
    {
        $this->assertSame(RelationKind::ONE_TO_ONE, ClassManipulator::toEnum('one_to_one'));
    }
}
