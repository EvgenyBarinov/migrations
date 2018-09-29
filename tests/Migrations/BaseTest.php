<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Migrations\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Spiral\Core\Container;
use Spiral\Core\NullMemory;
use Spiral\Database\Config\DatabaseConfig;
use Spiral\Database\Database;
use Spiral\Database\DatabaseManager;
use Spiral\Database\Driver\AbstractDriver;
use Spiral\Database\Driver\AbstractHandler;
use Spiral\Database\Schema\AbstractTable;
use Spiral\Database\Schema\Comparator;
use Spiral\Database\Schema\Reflector;
use Spiral\Files\Files;
use Spiral\Migrations\Atomizer\Atomizer;
use Spiral\Migrations\Atomizer\Renderer;
use Spiral\Migrations\Config\MigrationConfig;
use Spiral\Migrations\FileRepository;
use Spiral\Migrations\Migration;
use Spiral\Migrations\Migrator;
use Spiral\Reactor\ClassDeclaration;
use Spiral\Reactor\FileDeclaration;
use Spiral\Tokenizer\Config\TokenizerConfig;
use Spiral\Tokenizer\Tokenizer;
use Spiral\Tokenizer\TokenizerInterface;

abstract class BaseTest extends TestCase
{
    public static $config;
    public const DRIVER = null;
    protected static $driverCache = [];

    public const CONFIG = [
        'directory' => __DIR__ . '/../fixtures/',
        'table'     => 'migrations',
        'safe'      => true
    ];

    /** @var AbstractDriver */
    protected $driver;

    /** @var ContainerInterface */
    protected $container;

    /**  @var Migrator */
    protected $migrator;

    /** @var DatabaseManager */
    protected $dbal;

    /** @var Database */
    protected $db;

    /** @var FileRepository */
    protected $repository;

    public function setUp()
    {
        $this->container = $container = new Container();
        $this->dbal = $this->getDBAL($this->container);

        $config = new MigrationConfig(static::CONFIG);

        $this->migrator = new Migrator(
            $config,
            $this->dbal,
            $this->repository = new FileRepository(
                $config,
                $this->container,
                $this->getTokenizer()
            )
        );
    }

    /**
     * @throws \Throwable
     */
    public function tearDown()
    {
        $files = new Files();
        foreach ($files->getFiles(__DIR__ . '/../fixtures/', '*.php') as $file) {
            $files->delete($file);
        }

        //Clean up
        $reflector = new Reflector();
        foreach ($this->dbal->database()->getTables() as $table) {
            $schema = $table->getSchema();
            $schema->declareDropped();
            $reflector->addTable($schema);
        }

        $reflector->run();
    }

    protected function atomize(string $name, array $tables)
    {
        $atomizer = new Atomizer(new Renderer());

        //Make sure name is unique
        $name = $name . '_' . crc32(microtime(true));

        foreach ($tables as $table) {
            $atomizer->addTable($table);
        }

        //Rendering
        $declaration = new ClassDeclaration($name, Migration::class);

        $declaration->method('up')->setPublic();
        $declaration->method('down')->setPublic();

        $atomizer->declareChanges($declaration->method('up')->getSource());
        $atomizer->revertChanges($declaration->method('down')->getSource());

        $file = new FileDeclaration();
        $file->addElement($declaration);

        $this->repository->registerMigration($name, $name, $file);
    }

    /**
     * @param string $name
     * @param string $prefix
     *
     * @return Database|null When non empty null will be given, for safety, for science.
     */
    protected function db(string $name = 'default', string $prefix = '')
    {
        if (isset(static::$driverCache[static::DRIVER])) {
            $driver = static::$driverCache[static::DRIVER];
        } else {
            static::$driverCache[static::DRIVER] = $driver = $this->getDriver();
        }

        return new Database($name, $prefix, $driver);
    }

    /**
     * @param string $table
     * @return AbstractTable
     */
    protected function schema(string $table): AbstractTable
    {
        return $this->db->table($table)->getSchema();
    }

    /**
     * @param ContainerInterface $container
     *
     * @return DatabaseManager
     */
    protected function getDBAL(ContainerInterface $container): DatabaseManager
    {
        $dbal = new DatabaseManager(
            new DatabaseConfig([
                'default'     => 'default',
                'aliases'     => [],
                'databases'   => [],
                'connections' => []
            ]),
            $container
        );

        $dbal->addDatabase(
            $this->db = new Database(
                "default",
                "tests_",
                $this->getDriver()
            )
        );

        $dbal->addDatabase(new Database(
            "slave",
            "slave_",
            $this->getDriver()
        ));

        return $dbal;
    }

    protected function getTokenizer(): TokenizerInterface
    {
        return new Tokenizer(
            new TokenizerConfig([
                'directories' => [
                    __DIR__ . '/fixtures/'
                ],
                'exclude'     => []
            ]),
            new NullMemory()
        );
    }

    /**
     * @return AbstractDriver
     */
    public function getDriver(): AbstractDriver
    {
        $config = self::$config[static::DRIVER];
        if (!isset($this->driver)) {
            $class = $config['driver'];

            $this->driver = new $class([
                'connection' => $config['conn'],
                'username'   => $config['user'],
                'password'   => $config['pass'],
                'options'    => []
            ]);
        }

        if (self::$config['debug']) {
            $this->driver->setProfiling(true);
            $this->driver->setLogger(new TestLogger());
        }

        return $this->driver;
    }

    /**
     * @param Database|null $database
     */
    protected function dropDatabase(Database $database = null)
    {
        if (empty($database)) {
            return;
        }

        foreach ($database->getTables() as $table) {
            $schema = $table->getSchema();

            foreach ($schema->getForeignKeys() as $foreign) {
                $schema->dropForeignKey($foreign->getColumn());
            }

            $schema->save(AbstractHandler::DROP_FOREIGN_KEYS);
        }

        foreach ($database->getTables() as $table) {
            $schema = $table->getSchema();
            $schema->declareDropped();
            $schema->save();
        }
    }

    protected function assertSameAsInDB(AbstractTable $current)
    {
        $comparator = new Comparator(
            $current->getState(),
            $this->schema($current->getName())->getState()
        );

        if ($comparator->hasChanges()) {
            $this->fail($this->makeMessage($current->getName(), $comparator));
        }
    }

    /**
     * @param AbstractTable $table
     * @return AbstractTable
     */
    protected function fetchSchema(AbstractTable $table): AbstractTable
    {
        return $this->schema($table->getName());
    }

    /**
     * @param string     $table
     * @param Comparator $comparator
     * @return string
     */
    protected function makeMessage(string $table, Comparator $comparator)
    {
        if ($comparator->isPrimaryChanged()) {
            return "Table '{$table}' not synced, primary indexes are different.";
        }

        if ($comparator->droppedColumns()) {
            return "Table '{$table}' not synced, columns are missing.";
        }

        if ($comparator->addedColumns()) {
            return "Table '{$table}' not synced, new columns found.";
        }

        if ($comparator->alteredColumns()) {

            $names = [];
            foreach ($comparator->alteredColumns() as $pair) {
                $names[] = $pair[0]->getName();
                print_r($pair);
            }

            return "Table '{$table}' not synced, column(s) '" . join("', '",
                    $names) . "' have been changed.";
        }

        if ($comparator->droppedForeignKeys()) {
            return "Table '{$table}' not synced, FKs are missing.";
        }

        if ($comparator->addedForeignKeys()) {
            return "Table '{$table}' not synced, new FKs found.";
        }


        return "Table '{$table}' not synced, no idea why, add more messages :P";
    }
}

class TestLogger implements LoggerInterface
{
    use LoggerTrait;

    public function log($level, $message, array $context = [])
    {
        if ($level == LogLevel::ERROR) {
            echo " \n! \033[31m" . $message . "\033[0m";
        } elseif ($level == LogLevel::ALERT) {
            echo " \n! \033[35m" . $message . "\033[0m";
        } elseif (strpos($message, 'SHOW') === 0) {
            echo " \n> \033[34m" . $message . "\033[0m";
        } else {
            if (strpos($message, 'SELECT') === 0) {
                echo " \n> \033[32m" . $message . "\033[0m";
            } else {
                echo " \n> \033[33m" . $message . "\033[0m";
            }
        }
    }
}