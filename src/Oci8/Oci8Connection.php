<?php

namespace Yajra\Oci8;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Driver\OCI8\Driver as DoctrineDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\Grammar;
use PDO;
use Yajra\Oci8\Query\Grammars\OracleGrammar as QueryGrammar;
use Yajra\Oci8\Query\OracleBuilder as QueryBuilder;
use Yajra\Oci8\Query\Processors\OracleProcessor as Processor;
use Yajra\Oci8\Schema\Grammars\OracleGrammar as SchemaGrammar;
use Yajra\Oci8\Schema\OracleBuilder as SchemaBuilder;
use Yajra\Oci8\Schema\Sequence;
use Yajra\Oci8\Schema\Trigger;
use Yajra\Pdo\Oci8\Statement;

class Oci8Connection extends Connection
{
    /**
     * @var string
     */
    protected $schema;

    /**
     * @var \Yajra\Oci8\Schema\Sequence
     */
    protected $sequence;

    /**
     * @var \Yajra\Oci8\Schema\Trigger
     */
    protected $trigger;

    /**
     * @param PDO|\Closure $pdo
     * @param string $database
     * @param string $tablePrefix
     * @param array $config
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $this->sequence = new Sequence($this);
        $this->trigger  = new Trigger($this);
    }

    /**
     * Get current schema.
     *
     * @return string
     */
    public function getSchema()
    {
        return $this->schema;
    }

    /**
     * Set current schema.
     *
     * @param string $schema
     * @return $this
     */
    public function setSchema($schema)
    {
        $this->schema = $schema;
        $sessionVars  = [
            'CURRENT_SCHEMA' => $schema,
        ];

        return $this->setSessionVars($sessionVars);
    }

    /**
     * Update oracle session variables.
     *
     * @param array $sessionVars
     * @return $this
     */
    public function setSessionVars(array $sessionVars)
    {
        $vars = [];
        foreach ($sessionVars as $option => $value) {
            if (strtoupper($option) == 'CURRENT_SCHEMA') {
                $vars[] = "$option  = $value";
            } else {
                $vars[] = "$option  = '$value'";
            }
        }
        if ($vars) {
            $sql = "ALTER SESSION SET " . implode(" ", $vars);
            $this->statement($sql);
        }

        return $this;
    }

    /**
     * Get sequence class.
     *
     * @return \Yajra\Oci8\Schema\Sequence
     */
    public function getSequence()
    {
        return $this->sequence;
    }

    /**
     * Set sequence class.
     *
     * @param \Yajra\Oci8\Schema\Sequence $sequence
     * @return \Yajra\Oci8\Schema\Sequence
     */
    public function setSequence(Sequence $sequence)
    {
        return $this->sequence = $sequence;
    }

    /**
     * Get oracle trigger class.
     *
     * @return \Yajra\Oci8\Schema\Trigger
     */
    public function getTrigger()
    {
        return $this->trigger;
    }

    /**
     * Set oracle trigger class.
     *
     * @param \Yajra\Oci8\Schema\Trigger $trigger
     * @return \Yajra\Oci8\Schema\Trigger
     */
    public function setTrigger(Trigger $trigger)
    {
        return $this->trigger = $trigger;
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Yajra\Oci8\Schema\OracleBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param  string $table
     * @return \Yajra\Oci8\Query\OracleBuilder
     */
    public function table($table)
    {
        $processor = $this->getPostProcessor();

        $query = new QueryBuilder($this, $this->getQueryGrammar(), $processor);

        return $query->from($table);
    }

    /**
     * Set oracle session date format.
     *
     * @param string $format
     * @return $this
     */
    public function setDateFormat($format = 'YYYY-MM-DD HH24:MI:SS')
    {
        $sessionVars = [
            'NLS_DATE_FORMAT'      => $format,
            'NLS_TIMESTAMP_FORMAT' => $format,
        ];

        return $this->setSessionVars($sessionVars);
    }

    /**
     * Get doctrine connection.
     *
     * @return \Doctrine\DBAL\Connection
     */
    public function getDoctrineConnection()
    {
        $driver = $this->getDoctrineDriver();

        $data = ['pdo' => $this->getPdo(), 'user' => $this->getConfig('username')];

        return new DoctrineConnection($data, $driver);
    }

    /**
     * Get doctrine driver.
     *
     * @return \Doctrine\DBAL\Driver\OCI8\Driver
     */
    protected function getDoctrineDriver()
    {
        return new DoctrineDriver;
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Yajra\Oci8\Query\Grammars\OracleGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar);
    }

    /**
     * Set the table prefix and return the grammar.
     *
     * @param \Illuminate\Database\Grammar|\Yajra\Oci8\Query\Grammars\OracleGrammar|\Yajra\Oci8\Schema\Grammars\OracleGrammar $grammar
     * @return \Illuminate\Database\Grammar
     */
    public function withTablePrefix(Grammar $grammar)
    {
        return $this->withSchemaPrefix(parent::withTablePrefix($grammar));
    }

    /**
     * Set the schema prefix and return the grammar.
     *
     * @param \Illuminate\Database\Grammar|\Yajra\Oci8\Query\Grammars\OracleGrammar|\Yajra\Oci8\Schema\Grammars\OracleGrammar $grammar
     * @return \Illuminate\Database\Grammar
     */
    public function withSchemaPrefix(Grammar $grammar)
    {
        $grammar->setSchemaPrefix($this->getConfigSchemaPrefix());

        return $grammar;
    }
    
    /**
     * Execute a PL/SQL Function and return its value.
     * Usage: DB::executeFunction('function_name(:binding_1,:binding_n)', [':binding_1' => 'hi', ':binding_n' => 'bye'], PDO::PARAM_LOB)
     *
     * @author Tylerian - jairo.eog@outlook.com
     *
     * @param $sql (mixed)
     * @param $bindings (kvp array)
     * @param $returnType (PDO::PARAM_*)
     * @return $returnType
     */
     public function executeFunction($sql, $bindings = [], $outs = [], $returnType = PDO::PARAM_STR)
    {
        $query = $this->getPdo()->prepare('begin :result := ' . $sql . '; end;');

        foreach ($bindings as $key => &$value)
        {
            $key = ':'.$key;
            $query->bindParam($key, $value);
        }
        foreach ($outs as $bindingName => &$bindingValue) {
            $query->bindParam(':' . $bindingName, $bindingValue, PDO::PARAM_STR, 32767);
        }

        if ($returnType === PDO::PARAM_STMT) {
            $cursor = null;
            $query->bindParam(':result', $cursor, $returnType);
            $result = $query->execute();
            $statement = new Statement($cursor, $this->getPdo(), $this->getPdo()->getOptions());
            $statement->execute();
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);

            $statement->closeCursor();
        }elseif(count($outs) > 0){
            $query->bindParam(':result', $result, PDO::PARAM_STR, 32767);
            $query->execute();
            $result = array($outs,$result);
        }else{
            $query->bindParam(':result', $result, PDO::PARAM_STR, 32767);
            $query->execute();
        }

        return $result;



    }

    /**
     * Execute a PL/SQL Procedure and return its result.
     * Usage: DB::executeProcedure($procedureName, $bindings, $outs).
     * $bindings and $out looks like:
     *         $bindings = [
     *                  'p_userid'  => $id
     *         ];
     *
     * @param string $procedureName
     * @param array $bindings = procedure parameters
     * @param array $outs - variables that get setup from the procedure and returned
     * @param mixed $returnType
     * @return array
     */
    public function executeProcedure($procedureName, $bindings = [], $outs = [], $returnType = PDO::PARAM_STR)
    {
        $key_array = [];

        foreach($bindings as $name => $value) {
            $key_array[] = $name . ' => :' . $name;
        }

        foreach($outs as $name => $value) {
            $key_array[] = $name . ' => :' . $name;
        }

        $keys = implode(', ', $key_array);

        $command = sprintf('begin %s(%s); end;', $procedureName, $keys);

        $stmt = $this->getPdo()->prepare($command);

        foreach ($bindings as $bindingName => &$bindingValue) {
           if(is_array($bindingValue)){
                $stmt->bindParam(':' . $bindingName, $bindingValue, PDO::PARAM_INT, count($bindingValue));
            }else {
                $stmt->bindParam(':' . $bindingName, $bindingValue);
            }
        }

        foreach ($outs as $bindingName => &$bindingValue) {
            $stmt->bindParam(':' . $bindingName, $bindingValue, PDO::PARAM_STR, 32767);
        }

        if(count($outs) > 0){
            $stmt->execute();
            $result = $outs;
        }else{
            $result = $stmt->execute();
        }

        return $result;
    }

    /**
     * Get config schema prefix.
     *
     * @return string
     */
    protected function getConfigSchemaPrefix()
    {
        return isset($this->config['prefix_schema']) ? $this->config['prefix_schema'] : '';
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Yajra\Oci8\Schema\Grammars\OracleGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Yajra\Oci8\Query\Processors\OracleProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }
}
