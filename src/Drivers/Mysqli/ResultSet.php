<?php

namespace Foolz\SphinxQL\Drivers\Mysqli;

use Foolz\SphinxQL\Drivers\ResultSetException;
use Foolz\SphinxQL\Drivers\ResultSetInterface;

class ResultSet implements ResultSetInterface, \ArrayAccess
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var \mysqli_result
     */
    protected $result;

    /**
     * @var array
     */
    protected $fields;

    /**
     * @var int
     */
    protected $num_rows = 0;

    /**
     * @var null|array
     */
    protected $stored = null;

    /**
     * @var int
     */
    protected $affected_rows = 0; // leave to 0 so SELECT etc. will be coherent

    /**
     * @var null|array
     */
    protected $current_row = null;

    /**
     * @var null|array
     */
    protected $fetched = null;

    /**
     * @param Connection $connection
     * @param null|\mysqli_result $result
     */
    public function __construct(Connection $connection, $result = null)
    {
        $this->connection = $connection;

        if ($result instanceof \mysqli_result) {
            $this->result = $result;
            $this->num_rows = $this->result->num_rows;

        } else {
            $this->affected_rows = $this->getMysqliConnection()->affected_rows;
        }
    }

    /**
     * Store all the data in this object and free the mysqli object
     *
     * @return static $this
     */
    public function store()
    {
        if ($this->stored !== null) {
            return $this;
        }

        if ($this->result instanceof \mysqli_result) {
            $this->fields = $this->result->fetch_fields();
            $result = $this->result->fetch_all(MYSQLI_NUM);
            $this->stored = $result;
        } else {
            $this->stored = $this->affected_rows;
        }

        return $this;
    }

    /**
     * Returns the array as in version 0.9.x
     *
     * @return array|int|mixed
     * @deprecated Commodity method for simple transition to version 1.0.0
     */
    public function getStored()
    {
        if (!($this->result instanceof \mysqli_result)) {
            return $this->getAffectedRows();
        }

        return $this->fetchAllAssoc();
    }

    /**
     * Checks that a row actually exists
     *
     * @param int $num The number of the row to check on
     * @return bool True if the row exists
     */
    public function hasRow($num)
    {
        return $num >= 0 && $num < $this->num_rows;
    }

    /**
     * Moves the cursor to the selected row
     *
     * @param int $num The number of the row to move the cursor to
     * @return static
     * @throws ResultSetException If the row does not exist
     */
    public function toRow($num)
    {
        if (!$this->hasRow($num)) {
            throw new ResultSetException('The row does not exist.');
        }

        $this->current_row = $num;
        $this->result->data_seek($num);
        $this->fetched = $this->result->fetch_row();

        return $this;
    }

    /**
     * Checks that a next row exists
     *
     * @return bool True if there's another row with a higher index
     */
    public function hasNextRow()
    {
        return $this->current_row < $this->num_rows;
    }

    /**
     * Moves the cursor to the next row
     *
     * @return static $this
     * @throws ResultSetException If the next row does not exist
     */
    public function toNextRow()
    {
        if (!$this->hasNextRow()) {
            throw new ResultSetException('The next row does not exist.');
        }

        if ($this->current_row === null) {
            $this->current_row = 0;
        } else {
            $this->current_row++;
        }

        $this->fetched = $this->result->fetch_row();

        return $this;
    }

    /**
     * Fetches all the rows as an array of associative arrays
     *
     * @return array|mixed
     */
    public function fetchAllAssoc() {
        if ($this->stored !== null) {
            $result = array();
            foreach ($this->stored as $row_key => $row_value) {
                foreach ($row_value as $col_key => $col_value) {
                    $result[$row_key][$this->fields[$col_key]->name] = $col_value;
                }
            }

            return $result;
        }

        return $this->result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Fetches all the rows as an array of indexed arrays
     *
     * @return array|mixed|null
     */
    public function fetchAllNum() {
        if ($this->stored !== null) {
            return $this->stored;
        }

        return $this->result->fetch_all(MYSQLI_NUM);
    }

    /**
     * Fetches a row as an associative array
     *
     * @return array
     */
    public function fetchAssoc() {
        if ($this->stored) {
            $row = $this->stored[$this->current_row];
        } else {
            $row = $this->fetched;
        }

        $result = array();
        foreach ($row as $col_key => $col_value) {
            $result[$this->fields[$col_key]->name] = $col_value;
        }

        return $result;
    }

    /**
     * Fetches a row as an indexed array
     *
     * @return array|null
     */
    public function fetchNum() {
        if ($this->stored) {
            return $this->stored[$this->current_row];
        } else {
            return $this->fetched;
        }
    }

    /**
     * Get the result object returned by PHP's MySQLi
     *
     * @return \mysqli_result
     */
    public function getResultObject()
    {
        return $this->result;
    }

    /**
     * Get the MySQLi connection wrapper object
     *
     * @return Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get the PHP MySQLi object
     *
     * @return \mysqli
     * @throws \Foolz\SphinxQL\Drivers\ConnectionException
     */
    public function getMysqliConnection()
    {
        return $this->connection->getConnection();
    }

    /**
     * Returns the number of rows affected by the query
     * This will be 0 for SELECT and any query not editing rows
     *
     * @return int
     */
    public function getAffectedRows()
    {
        return $this->affected_rows;
    }

    public function getCount()
    {
        return $this->num_rows;
    }

    /**
     * Frees the memory from the result
     * Call it after you're done with a result set
     */
    public function freeResult()
    {
        $this->result->free_result();
        return $this;
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     */
    public function offsetExists($offset)
    {
        return $offset >= 0 && ($this->num_rows - 1) < $offset;
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     */
    public function offsetGet($offset)
    {
        if ($this->stored) {
            return $this->stored[$offset];
        }

        $this->result->data_seek($offset);
        return $this->result->fetch_assoc();
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        throw new \BadMethodCallException('Not implemented');
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     */
    public function offsetUnset($offset)
    {
        throw new \BadMethodCallException('Not implemented');
    }
}