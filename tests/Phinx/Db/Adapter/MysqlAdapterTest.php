<?php

namespace Test\Phinx\Db\Adapter;

use Symfony\Component\Console\Output\NullOutput;
use Phinx\Db\Adapter\MysqlAdapter;

class MysqlAdapterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Phinx\Db\Adapter\MysqlAdapter
     */
    private $adapter;

    public function setUp()
    {
        if (!TESTS_PHINX_DB_ADAPTER_MYSQL_ENABLED) {
            $this->markTestSkipped('Mysql tests disabled. See TESTS_PHINX_DB_ADAPTER_MYSQL_ENABLED constant.');
        }

        $options = array(
            'host' => TESTS_PHINX_DB_ADAPTER_MYSQL_HOST,
            'name' => TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE,
            'user' => TESTS_PHINX_DB_ADAPTER_MYSQL_USERNAME,
            'pass' => TESTS_PHINX_DB_ADAPTER_MYSQL_PASSWORD,
            'port' => TESTS_PHINX_DB_ADAPTER_MYSQL_PORT
        );
        $this->adapter = new MysqlAdapter($options, new NullOutput());

        // ensure the database is empty for each test
        $this->adapter->dropDatabase($options['name']);
        $this->adapter->createDatabase($options['name']);

        // leave the adapter in a disconnected state for each test
        $this->adapter->disconnect();
    }

    public function tearDown()
    {
        unset($this->adapter);
    }

    public function testConnection()
    {
        $this->assertTrue($this->adapter->getConnection() instanceof \PDO);
    }

    public function testConnectionWithoutPort()
    {
        $options = $this->adapter->getOptions();
        unset($options['port']);
        $this->adapter->setOptions($options);
        $this->assertTrue($this->adapter->getConnection() instanceof \PDO);
    }

    public function testConnectionWithInvalidCredentials()
    {
        $options = array(
            'host' => TESTS_PHINX_DB_ADAPTER_MYSQL_HOST,
            'name' => TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE,
            'port' => TESTS_PHINX_DB_ADAPTER_MYSQL_PORT,
            'user' => 'invaliduser',
            'pass' => 'invalidpass'
        );

        try {
            $adapter = new MysqlAdapter($options, new NullOutput());
            $adapter->connect();
            $this->fail('Expected the adapter to throw an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(
                'InvalidArgumentException',
                $e,
                'Expected exception of type InvalidArgumentException, got ' . get_class($e)
            );
            $this->assertRegExp('/There was a problem connecting to the database/', $e->getMessage());
        }
    }

    public function testConnectionWithSocketConnection()
    {
        if (!TESTS_PHINX_DB_ADAPTER_MYSQL_UNIX_SOCKET) {
            $this->markTestSkipped('MySQL socket connection skipped. See TESTS_PHINX_DB_ADAPTER_MYSQL_UNIX_SOCKET constant.');
        }

        $options = array(
            'name'        => TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE,
            'user'        => TESTS_PHINX_DB_ADAPTER_MYSQL_USERNAME,
            'pass'        => TESTS_PHINX_DB_ADAPTER_MYSQL_PASSWORD,
            'unix_socket' => TESTS_PHINX_DB_ADAPTER_MYSQL_UNIX_SOCKET,
        );

        $adapter = new MysqlAdapter($options, new NullOutput());
        $adapter->connect();

        $this->assertInstanceOf('\PDO', $this->adapter->getConnection());
    }

    public function testCreatingTheSchemaTableOnConnect()
    {
        $this->adapter->connect();
        $this->assertTrue($this->adapter->hasTable($this->adapter->getSchemaTableName()));
        $this->adapter->dropTable($this->adapter->getSchemaTableName());
        $this->assertFalse($this->adapter->hasTable($this->adapter->getSchemaTableName()));
        $this->adapter->disconnect();
        $this->adapter->connect();
        $this->assertTrue($this->adapter->hasTable($this->adapter->getSchemaTableName()));
    }

    public function testQuoteTableName()
    {
        $this->assertEquals('`test_table`', $this->adapter->quoteTableName('test_table'));
    }

    public function testQuoteColumnName()
    {
        $this->assertEquals('`test_column`', $this->adapter->quoteColumnName('test_column'));
    }

    public function testCreateTable()
    {
        $table = new \Phinx\Db\Table('ntable', array(), $this->adapter);
        $table->addColumn('realname', 'string')
              ->addColumn('email', 'integer')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'email'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));
    }

    public function testCreateTableWithComment()
    {
        $table = new \Phinx\Db\Table('ntable', array('comment'=>$tableComment = 'Table comment'), $this->adapter);
        $table->addColumn('realname', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));

        $rows = $this->adapter->fetchAll(sprintf(
            "SELECT table_comment FROM INFORMATION_SCHEMA.TABLES WHERE table_schema='%s' AND table_name='ntable'",
            TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE));
        $comment = $rows[0];

        $this->assertEquals($tableComment, $comment['table_comment'], 'Dont set table comment correctly');
    }

    public function testCreateTableWithForeignKeys()
    {

        $tag_table = new \Phinx\Db\Table('ntable_tag', array(), $this->adapter);
        $tag_table->addColumn('realname', 'string')
                  ->save();

        $table = new \Phinx\Db\Table('ntable', array(), $this->adapter);
        $table->addColumn('realname', 'string')
              ->addColumn('tag_id', 'integer')
              ->addForeignKey('tag_id', 'ntable_tag', 'id', array('delete'=> 'NO_ACTION', 'update'=> 'NO_ACTION'))
              ->save();

        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));

        $rows = $this->adapter->fetchAll(sprintf(
            "SELECT table_name, column_name, referenced_table_name, referenced_column_name
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE table_schema='%s' AND REFERENCED_TABLE_NAME='ntable_tag'",
            TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE));
        $foreignKey = $rows[0];

        $this->assertEquals($foreignKey['table_name'], 'ntable');
        $this->assertEquals($foreignKey['column_name'], 'tag_id');
        $this->assertEquals($foreignKey['referenced_table_name'], 'ntable_tag');
        $this->assertEquals($foreignKey['referenced_column_name'], 'id');
    }

    public function testCreateTableCustomIdColumn()
    {
        $table = new \Phinx\Db\Table('ntable', array('id' => 'custom_id'), $this->adapter);
        $table->addColumn('realname', 'string')
              ->addColumn('email', 'integer')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'custom_id'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'realname'));
        $this->assertTrue($this->adapter->hasColumn('ntable', 'email'));
        $this->assertFalse($this->adapter->hasColumn('ntable', 'address'));
    }

    public function testCreateTableWithNoOptions()
    {
        $this->markTestIncomplete();
        //$this->adapter->createTable('ntable', )
    }

    public function testCreateTableWithNoPrimaryKey()
    {
        $options = array(
            'id' => false
        );
        $table = new \Phinx\Db\Table('atable', $options, $this->adapter);
        $table->addColumn('user_id', 'integer')
              ->save();
        $this->assertFalse($this->adapter->hasColumn('atable', 'id'));
    }

    public function testCreateTableWithMultiplePrimaryKeys()
    {
        $options = array(
            'id'            => false,
            'primary_key'   => array('user_id', 'tag_id')
        );
        $table = new \Phinx\Db\Table('table1', $options, $this->adapter);
        $table->addColumn('user_id', 'integer')
              ->addColumn('tag_id', 'integer')
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', array('user_id', 'tag_id')));
        $this->assertTrue($this->adapter->hasIndex('table1', array('tag_id', 'USER_ID')));
        $this->assertFalse($this->adapter->hasIndex('table1', array('tag_id', 'user_email')));
    }

    public function testCreateTableWithMultipleIndexes()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('email', 'string')
              ->addColumn('name', 'string')
              ->addIndex('email')
              ->addIndex('name')
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', array('email')));
        $this->assertTrue($this->adapter->hasIndex('table1', array('name')));
        $this->assertFalse($this->adapter->hasIndex('table1', array('email', 'user_email')));
        $this->assertFalse($this->adapter->hasIndex('table1', array('email', 'user_name')));
    }

    public function testCreateTableWithUniqueIndexes()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email', array('unique' => true))
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', array('email')));
        $this->assertFalse($this->adapter->hasIndex('table1', array('email', 'user_email')));
    }

    public function testCreateTableWithNamedIndex()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email', array('name' => 'myemailindex'))
              ->save();
        $this->assertTrue($this->adapter->hasIndex('table1', array('email')));
        $this->assertFalse($this->adapter->hasIndex('table1', array('email', 'user_email')));
    }

    public function testCreateTableWithMultiplePKsAndUniqueIndexes()
    {
        $this->markTestIncomplete();
    }

    public function testCreateTableWithMyISAMEngine()
    {
        $table = new \Phinx\Db\Table('ntable', array('engine' => 'MyISAM'), $this->adapter);
        $table->addColumn('realname', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasTable('ntable'));
        $row = $this->adapter->fetchRow(sprintf("SHOW TABLE STATUS WHERE Name = '%s'", 'ntable'));
        $this->assertEquals('MyISAM', $row['Engine']);
    }

    public function testCreateTableWithLatin1Collate()
    {
        $table = new \Phinx\Db\Table('latin1_table', array('collation' => 'latin1_general_ci'), $this->adapter);
        $table->addColumn('name', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasTable('latin1_table'));
        $row = $this->adapter->fetchRow(sprintf("SHOW TABLE STATUS WHERE Name = '%s'", 'latin1_table'));
        $this->assertEquals('latin1_general_ci', $row['Collation']);
    }

    public function testRenameTable()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $this->assertTrue($this->adapter->hasTable('table1'));
        $this->assertFalse($this->adapter->hasTable('table2'));
        $this->adapter->renameTable('table1', 'table2');
        $this->assertFalse($this->adapter->hasTable('table1'));
        $this->assertTrue($this->adapter->hasTable('table2'));
    }

    public function testAddColumn()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('email'));
        $table->addColumn('email', 'string')
              ->save();
        $this->assertTrue($table->hasColumn('email'));
        $table->addColumn('realname', 'string', array('after' => 'id'))
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('realname', $rows[1]['Field']);
    }

    public function testAddColumnWithDefaultValue()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $table->addColumn('default_zero', 'string', array('default' => 'test'))
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals("test", $rows[1]['Default']);
    }

    public function testAddColumnWithDefaultZero()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $table->addColumn('default_zero', 'integer', array('default' => 0))
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertNotNull($rows[1]['Default']);
        $this->assertEquals("0", $rows[1]['Default']);
    }

    public function testAddColumnWithDefaultEmptyString()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $table->addColumn('default_empty', 'string', array('default' => ''))
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('', $rows[1]['Default']);
    }

    public function testAddColumnWithDefaultBoolean()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $table->addColumn('default_true', 'boolean', array('default' => true))
              ->addColumn('default_false', 'boolean', array('default' => false))
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('1', $rows[1]['Default']);
        $this->assertEquals('0', $rows[2]['Default']);
    }

    public function testAddIntegerColumnWithDefaultSigned()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('user_id'));
        $table->addColumn('user_id', 'integer')
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('int(11)', $rows[1]['Type']);
    }

    public function testAddIntegerColumnWithSignedEqualsFalse()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('user_id'));
        $table->addColumn('user_id', 'integer', array('signed' => false))
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('int(11) unsigned', $rows[1]['Type']);
    }

    public function testAddStringColumnWithSignedEqualsFalse()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('user_id'));
        $table->addColumn('user_id', 'string', array('signed' => false))
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('varchar(255)', $rows[1]['Type']);
    }

    public function testRenameColumn()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));
        $this->assertFalse($this->adapter->hasColumn('t', 'column2'));
        $this->adapter->renameColumn('t', 'column1', 'column2');
        $this->assertFalse($this->adapter->hasColumn('t', 'column1'));
        $this->assertTrue($this->adapter->hasColumn('t', 'column2'));
    }

    public function testRenamingANonExistentColumn()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();

        try {
            $this->adapter->renameColumn('t', 'column2', 'column1');
            $this->fail('Expected the adapter to throw an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(
                'InvalidArgumentException',
                $e,
                'Expected exception of type InvalidArgumentException, got ' . get_class($e)
            );
            $this->assertEquals('The specified column doesn\'t exist: column2', $e->getMessage());
        }
    }

    public function testChangeColumn()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));
        $newColumn1 = new \Phinx\Db\Table\Column();
        $newColumn1->setType('string');
        $table->changeColumn('column1', $newColumn1);
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));
        $newColumn2 = new \Phinx\Db\Table\Column();
        $newColumn2->setName('column2')
                   ->setType('string');
        $table->changeColumn('column1', $newColumn2);
        $this->assertFalse($this->adapter->hasColumn('t', 'column1'));
        $this->assertTrue($this->adapter->hasColumn('t', 'column2'));
    }

    public function testChangeColumnDefaultValue()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string', array('default' => 'test'))
              ->save();
        $newColumn1 = new \Phinx\Db\Table\Column();
        $newColumn1->setDefault('test1')
                   ->setType('string');
        $table->changeColumn('column1', $newColumn1);
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM t');
        $this->assertNotNull($rows[1]['Default']);
        $this->assertEquals("test1", $rows[1]['Default']);
    }


    public function testChangeColumnDefaultToZero()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'integer')
              ->save();
        $newColumn1 = new \Phinx\Db\Table\Column();
        $newColumn1->setDefault(0)
                   ->setType('integer');
        $table->changeColumn('column1', $newColumn1);
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM t');
        $this->assertNotNull($rows[1]['Default']);
        $this->assertEquals("0", $rows[1]['Default']);
    }

    public function testChangeColumnDefaultToNull()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string', array('default' => 'test'))
              ->save();
        $newColumn1 = new \Phinx\Db\Table\Column();
        $newColumn1->setDefault(null)
                   ->setType('string');
        $table->changeColumn('column1', $newColumn1);
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM t');
        $this->assertNull($rows[1]['Default']);
    }

    public function testLongTextColumn()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'text', array('limit' => MysqlAdapter::TEXT_LONG))
              ->save();
        $columns = $table->getColumns('t');
        $this->assertEquals('longtext', $columns[1]->getType());
    }

    public function testMediumTextColumn()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'text', array('limit' => MysqlAdapter::TEXT_MEDIUM))
              ->save();
        $columns = $table->getColumns('t');
        $this->assertEquals('mediumtext', $columns[1]->getType());
    }

    public function testTinyTextColumn()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'text', array('limit' => MysqlAdapter::TEXT_SMALL))
              ->save();
        $columns = $table->getColumns('t');
        $this->assertEquals('tinytext', $columns[1]->getType());
    }

    public function testDropColumn()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();
        $this->assertTrue($this->adapter->hasColumn('t', 'column1'));
        $this->adapter->dropColumn('t', 'column1');
        $this->assertFalse($this->adapter->hasColumn('t', 'column1'));
    }

    public function testGetColumns()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string')
              ->addColumn('column2', 'integer')
              ->addColumn('column3', 'biginteger')
              ->addColumn('column4', 'text')
              ->addColumn('column5', 'float')
              ->addColumn('column6', 'decimal')
              ->addColumn('column7', 'datetime')
              ->addColumn('column8', 'time')
              ->addColumn('column9', 'timestamp')
              ->addColumn('column10', 'date')
              ->addColumn('column11', 'binary')
              ->addColumn('column12', 'boolean')
              ->addColumn('column13', 'string', array('limit' => 10))
              ->addColumn('column15', 'integer', array('limit' => 10))
              ->addColumn('column16', 'geometry')
              ->addColumn('column17', 'point')
              ->addColumn('column18', 'linestring')
              ->addColumn('column19', 'polygon');
        $pendingColumns = $table->getPendingColumns();
        $table->save();
        $columns = $this->adapter->getColumns('t');
        $this->assertCount(count($pendingColumns) + 1, $columns);
        for ($i = 0; $i++; $i < count($pendingColumns)) {
            $this->assertEquals($pendingColumns[$i], $columns[$i+1]);
        }
    }

    public function testDescribeTable()
    {
        $table = new \Phinx\Db\Table('t', array(), $this->adapter);
        $table->addColumn('column1', 'string');
        $table->save();

        $described = $this->adapter->describeTable('t');

        $this->assertTrue(in_array($described['TABLE_TYPE'], array('VIEW','BASE TABLE')));
        $this->assertEquals($described['TABLE_NAME'], 't');
        $this->assertEquals($described['TABLE_SCHEMA'], TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE);
        $this->assertEquals($described['TABLE_ROWS'], 0);
    }

    public function testGetColumnsReservedTableName()
    {
        $table = new \Phinx\Db\Table('group', array(), $this->adapter);
        $table->addColumn('column1', 'string')
              ->addColumn('column2', 'integer')
              ->addColumn('column3', 'biginteger')
              ->addColumn('column4', 'text')
              ->addColumn('column5', 'float')
              ->addColumn('column6', 'decimal')
              ->addColumn('column7', 'datetime')
              ->addColumn('column8', 'time')
              ->addColumn('column9', 'timestamp')
              ->addColumn('column10', 'date')
              ->addColumn('column11', 'binary')
              ->addColumn('column12', 'boolean')
              ->addColumn('column13', 'string', array('limit' => 10))
              ->addColumn('column15', 'integer', array('limit' => 10))
              ->addColumn('column16', 'geometry')
              ->addColumn('column17', 'point')
              ->addColumn('column18', 'linestring')
              ->addColumn('column19', 'polygon');
        $pendingColumns = $table->getPendingColumns();
        $table->save();
        $columns = $this->adapter->getColumns('group');
        $this->assertCount(count($pendingColumns) + 1, $columns);
        for ($i = 0; $i++; $i < count($pendingColumns)) {
            $this->assertEquals($pendingColumns[$i], $columns[$i+1]);
        }
    }


    public function testAddIndex()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('email', 'string')
              ->save();
        $this->assertFalse($table->hasIndex('email'));
        $table->addIndex('email')
              ->save();
        $this->assertTrue($table->hasIndex('email'));
    }

    public function testDropIndex()
    {
        // single column index
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email')
              ->save();
        $this->assertTrue($table->hasIndex('email'));
        $this->adapter->dropIndex($table->getName(), 'email');
        $this->assertFalse($table->hasIndex('email'));

        // multiple column index
        $table2 = new \Phinx\Db\Table('table2', array(), $this->adapter);
        $table2->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(array('fname', 'lname'))
               ->save();
        $this->assertTrue($table2->hasIndex(array('fname', 'lname')));
        $this->adapter->dropIndex($table2->getName(), array('fname', 'lname'));
        $this->assertFalse($table2->hasIndex(array('fname', 'lname')));

        // index with name specified, but dropping it by column name
        $table3 = new \Phinx\Db\Table('table3', array(), $this->adapter);
        $table3->addColumn('email', 'string')
              ->addIndex('email', array('name' => 'someindexname'))
              ->save();
        $this->assertTrue($table3->hasIndex('email'));
        $this->adapter->dropIndex($table3->getName(), 'email');
        $this->assertFalse($table3->hasIndex('email'));

        // multiple column index with name specified
        $table4 = new \Phinx\Db\Table('table4', array(), $this->adapter);
        $table4->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(array('fname', 'lname'), array('name' => 'multiname'))
               ->save();
        $this->assertTrue($table4->hasIndex(array('fname', 'lname')));
        $this->adapter->dropIndex($table4->getName(), array('fname', 'lname'));
        $this->assertFalse($table4->hasIndex(array('fname', 'lname')));
    }

    public function testDropIndexByName()
    {
        // single column index
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('email', 'string')
              ->addIndex('email', array('name' => 'myemailindex'))
              ->save();
        $this->assertTrue($table->hasIndex('email'));
        $this->adapter->dropIndexByName($table->getName(), 'myemailindex');
        $this->assertFalse($table->hasIndex('email'));

        // multiple column index
        $table2 = new \Phinx\Db\Table('table2', array(), $this->adapter);
        $table2->addColumn('fname', 'string')
               ->addColumn('lname', 'string')
               ->addIndex(array('fname', 'lname'), array('name' => 'twocolumnindex'))
               ->save();
        $this->assertTrue($table2->hasIndex(array('fname', 'lname')));
        $this->adapter->dropIndexByName($table2->getName(), 'twocolumnindex');
        $this->assertFalse($table2->hasIndex(array('fname', 'lname')));
    }

    public function testAddForeignKey()
    {
        $refTable = new \Phinx\Db\Table('ref_table', array(), $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', array(), $this->adapter);
        $table->addColumn('ref_table_id', 'integer')->save();

        $fk = new \Phinx\Db\Table\ForeignKey();
        $fk->setReferencedTable($refTable)
           ->setColumns(array('ref_table_id'))
           ->setReferencedColumns(array('id'));

        $this->adapter->addForeignKey($table, $fk);
        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), array('ref_table_id')));
    }

    public function testDropForeignKey()
    {
        $refTable = new \Phinx\Db\Table('ref_table', array(), $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', array(), $this->adapter);
        $table->addColumn('ref_table_id', 'integer')->save();

        $fk = new \Phinx\Db\Table\ForeignKey();
        $fk->setReferencedTable($refTable)
           ->setColumns(array('ref_table_id'))
           ->setReferencedColumns(array('id'));

        $this->adapter->addForeignKey($table, $fk);
        $this->adapter->dropForeignKey($table->getName(), array('ref_table_id'));
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), array('ref_table_id')));
    }

    public function testDropForeignKeyAsString()
    {
        $refTable = new \Phinx\Db\Table('ref_table', array(), $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', array(), $this->adapter);
        $table->addColumn('ref_table_id', 'integer')->save();

        $fk = new \Phinx\Db\Table\ForeignKey();
        $fk->setReferencedTable($refTable)
           ->setColumns(array('ref_table_id'))
           ->setReferencedColumns(array('id'));

        $this->adapter->addForeignKey($table, $fk);
        $this->adapter->dropForeignKey($table->getName(), 'ref_table_id');
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), array('ref_table_id')));
    }

    public function testHasForeignKey()
    {
        $refTable = new \Phinx\Db\Table('ref_table', array(), $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', array(), $this->adapter);
        $table->addColumn('ref_table_id', 'integer')->save();

        $fk = new \Phinx\Db\Table\ForeignKey();
        $fk->setReferencedTable($refTable)
           ->setColumns(array('ref_table_id'))
           ->setReferencedColumns(array('id'));

        $this->adapter->addForeignKey($table, $fk);
        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), array('ref_table_id')));
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), array('ref_table_id2')));
    }

    public function testHasForeignKeyAsString()
    {
        $refTable = new \Phinx\Db\Table('ref_table', array(), $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', array(), $this->adapter);
        $table->addColumn('ref_table_id', 'integer')->save();

        $fk = new \Phinx\Db\Table\ForeignKey();
        $fk->setReferencedTable($refTable)
           ->setColumns(array('ref_table_id'))
           ->setReferencedColumns(array('id'));

        $this->adapter->addForeignKey($table, $fk);
        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), 'ref_table_id'));
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), 'ref_table_id2'));
    }

    public function testHasForeignKeyWithConstraint()
    {
        $refTable = new \Phinx\Db\Table('ref_table', array(), $this->adapter);
        $refTable->addColumn('field1', 'string')->save();

        $table = new \Phinx\Db\Table('table', array(), $this->adapter);
        $table->addColumn('ref_table_id', 'integer')->save();

        $fk = new \Phinx\Db\Table\ForeignKey();
        $fk->setReferencedTable($refTable)
           ->setColumns(array('ref_table_id'))
           ->setConstraint("my_constraint")
           ->setReferencedColumns(array('id'));

        $this->adapter->addForeignKey($table, $fk);
        $this->assertTrue($this->adapter->hasForeignKey($table->getName(), array('ref_table_id'), 'my_constraint'));
        $this->assertFalse($this->adapter->hasForeignKey($table->getName(), array('ref_table_id'), 'my_constraint2'));
    }

    public function testHasDatabase()
    {
        $this->assertFalse($this->adapter->hasDatabase('fake_database_name'));
        $this->assertTrue($this->adapter->hasDatabase(TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE));
    }

    public function testDropDatabase()
    {
        $this->assertFalse($this->adapter->hasDatabase('phinx_temp_database'));
        $this->adapter->createDatabase('phinx_temp_database');
        $this->assertTrue($this->adapter->hasDatabase('phinx_temp_database'));
        $this->adapter->dropDatabase('phinx_temp_database');
    }

    public function testAddColumnWithComment()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('column1', 'string', array('comment' => $comment = 'Comments from "column1"'))
              ->save();

        $rows = $this->adapter->fetchAll(sprintf(
            "SELECT column_name, column_comment FROM information_schema.columns WHERE table_schema='%s' AND table_name='table1'",
            TESTS_PHINX_DB_ADAPTER_MYSQL_DATABASE));
        $columnWithComment = $rows[1];

        $this->assertEquals($comment, $columnWithComment['column_comment'], 'Dont set column comment correctly');
    }

    public function testAddGeoSpatialColumns()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->save();
        $this->assertFalse($table->hasColumn('geo_geom'));
        $table->addColumn('geo_geom', 'geometry')
              ->save();
        $rows = $this->adapter->fetchAll('SHOW COLUMNS FROM table1');
        $this->assertEquals('geometry', $rows[1]['Type']);
    }

    public function testHasColumn()
    {
        $table = new \Phinx\Db\Table('table1', array(), $this->adapter);
        $table->addColumn('column1', 'string')
              ->save();

        $this->assertFalse($table->hasColumn('column2'));
        $this->assertTrue($table->hasColumn('column1'));
    }

    public function testHasColumnReservedName()
    {
        $tableQuoted = new \Phinx\Db\Table('group', array(), $this->adapter);
        $tableQuoted->addColumn('value', 'string')
                    ->save();

        $this->assertFalse($tableQuoted->hasColumn('column2'));
        $this->assertTrue($tableQuoted->hasColumn('value'));

    }

    public function testPhinxTypeExistsWithoutLimit()
    {
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_STRING, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('varchar'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_CHAR, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('char'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('int'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_BIG_INTEGER, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('bigint'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_BINARY, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('blob'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_FLOAT, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('float'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_DECIMAL, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('decimal'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_DATETIME, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('datetime'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_TIMESTAMP, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('timestamp'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_DATE, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('date'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_TIME, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('time'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('text'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_POINT, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('point'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_GEOMETRY, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('geometry'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_LINESTRING, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('linestring'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_POLYGON, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('polygon'));
    }

    public function testPhinxTypeExistsWithLimit()
    {
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_STRING, 'limit' => 32, 'precision' => null),
                            $this->adapter->getPhinxType('varchar(32)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_CHAR, 'limit' => 32, 'precision' => null),
                            $this->adapter->getPhinxType('char(32)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_INTEGER, 'limit' => 12, 'precision' => null),
                            $this->adapter->getPhinxType('int(12)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_BIG_INTEGER, 'limit' => 21, 'precision' => null),
                            $this->adapter->getPhinxType('bigint(21)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_BINARY, 'limit' => 1024, 'precision' => null),
                            $this->adapter->getPhinxType('blob(1024)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_FLOAT, 'limit' => 8, 'precision' => 2),
                            $this->adapter->getPhinxType('float(8,2)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_DECIMAL, 'limit' => 8, 'precision' => 2),
                            $this->adapter->getPhinxType('decimal(8,2)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_TEXT, 'limit' => 1024, 'precision' => null),
                            $this->adapter->getPhinxType('text(1024)'));
    }

    public function testPhinxTypeExistsWithLimitNull()
    {
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_STRING, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('varchar(255)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_CHAR, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('char(255)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('int(11)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_BIG_INTEGER, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('bigint(20)'));
        $this->assertEquals(array('name' => MysqlAdapter::PHINX_TYPE_BOOLEAN, 'limit' => null, 'precision' => null),
                            $this->adapter->getPhinxType('tinyint(1)'));
    }

    public function testPhinxTypeNotValidType()
    {
        $this->setExpectedException('\RuntimeException', 'The type: "fake" is not supported.');
        $this->adapter->getPhinxType('fake');
    }

    public function testPhinxTypeNotValidTypeRegex()
    {
        $this->setExpectedException('\RuntimeException', 'Column type ?int? is not supported');
        $this->adapter->getPhinxType('?int?');
    }
}
