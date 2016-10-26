<?php
/**
* DBUtil
*/
class DB
{
	/**
	 * Username
	 *
	 * @var	string
	 */
	public $username;

	/**
	 * Password
	 *
	 * @var	string
	 */
	public $password;

	/**
	 * Hostname
	 *
	 * @var	string
	 */
	public $hostname;

	/**
	 * Database name
	 *
	 * @var	string
	 */
	public $database;

	/**
	 * Character set
	 *
	 * @var	string
	 */
	protected $charset = "utf8";

	/**
	 * Database port
	 *
	 * @var	int
	 */
	protected $port;

	/**
	 * Bind marker
	 *
	 * Character used to identify values in a prepared statement.
	 *
	 * @var	string
	 */
	protected $bind_marker = "?";

	/**
	 * Identifier escape character
	 *
	 * @var	string
	 */
	protected $_escape_char = '"';

	/**
	 * ESCAPE statement string
	 *
	 * @var	string
	 */
	protected $_like_escape_str = " ESCAPE '%s' ";

	/**
	 * Result ID
	 *
	 * @var	object|resource
	 */
	public $result_id;

	/**
	 * ESCAPE character
	 *
	 * @var	string
	 */
	protected $_like_escape_chr = '!';

	protected $conn_id;

	function __construct($options = array())
	{
		if (!empty($options) && is_array($options))
		{
			foreach ($options as $option => $value)
			{
				$this->$option = $value;
			}
		}
		else
		{
			$this->display_error("Unable to connect to the database, empty configure");
		}

		$port = +$this->port;
		$this->port = empty($port) ? NULL : $port;

		$this->conn_id = $this->connect();

		$this->_db_set_charset($this->charset);

		return $this;
	}

	private function connect()
	{
		$mysqli = mysqli_init();

		if (!$mysqli) $this->display_error('mysqli_init failed');

		$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

		if (!$mysqli->real_connect($this->hostname, 
			$this->username, 
			$this->password, 
			$this->database,
			$this->port)) 
		{
			$this->display_error('Connect Error (' . mysqli_connect_errno() . ') ' . mysqli_connect_error());
		}

		return $mysqli;
	}

	/**
	 * Execute the query
	 *
	 * @param	string	$sql
	 * @param	array	$binds = FALSE		An array of binding data
	 * @return	mixed
	 */
	public function query($sql, $binds = array())
	{
		if(empty($sql)) $this->display_error('Invalid query: ' . $sql);
		
		// Compile binds if needed
		if ($binds !== FALSE)
		{
			$sql = $this->compile_binds($sql, $binds);
		}

		$this->result_id = $this->_simple_query($sql);

		return $this;
	}

	/**
	 * Returns a single result row - array version
	 *
	 * @param	int	$n
	 * @return	array
	 */
	public function row_array()
	{
		if(empty($this->result_id))
		{
			return array();
		}

		$row = $this->result_id->fetch_array(MYSQLI_ASSOC);

		$this->free_result();

		return $row;
	}

	/**
	 * Query result. "array" version.
	 *
	 * @return	array
	 */
	public function result_array()
	{
		if(empty($this->result_id))
		{
			return array();
		}

		$rows = array();

		while($row = $this->result_id->fetch_array(MYSQLI_ASSOC))
		{
			$rows[] = $row;
		}

		$this->free_result();

		return $rows;
	}

	/**
	 * Fetch Field Names
	 *
	 * Generates an array of column names.
	 *
	 * @return	array
	 */
	public function list_fields()
	{
		if(empty($this->result_id))
		{
			return array();
		}

		$fields = array();
		while ($finfo = $this->result_id->fetch_field()) 
		{
			$fields[] = $finfo;
		}

		$this->free_result();

		return $fields;
	}

	/**
	 * Close DB Connection
	 *
	 * @return	void
	 */
	public function close()
	{
		if ($this->conn_id)
		{
			$this->conn_id->close();
			$this->conn_id = FALSE;
		}
	}

	/**
	 * Free the result
	 *
	 * @return	void
	 */
	public function free_result()
	{
		if($this->result_id)
		{
			$this->result_id->free();
			$this->result_id = FALSE;
		}
	}

	/**
	 * Set client character set
	 *
	 * @param	string	$charset
	 * @return	bool
	 */
	protected function _db_set_charset($charset)
	{
		return $this->conn_id->set_charset($charset);
	}

	/**
	 * Simple Query
	 * This is a simplified version of the query() function. Internally
	 * we only use it when running transaction commands since they do
	 * not require all the features of the main query() function.
	 *
	 * @param	string	the sql query
	 * @return	mixed
	 */
	private function _simple_query($sql)
	{
		return $this->_execute($sql);
	}

	/**
	 * Execute the query
	 *
	 * @param	string	$sql	an SQL query
	 * @return	mixed
	 */
	private function _execute($sql)
	{
		return $this->conn_id->query($this->_prep_query($sql));
	}

	/**
	 * Compile Bindings
	 *
	 * @param	string	the sql statement
	 * @param	array	an array of bind data
	 * @return	string
	 */
	public function compile_binds($sql, $binds)
	{
		if (empty($binds) OR empty($this->bind_marker) OR strpos($sql, $this->bind_marker) === FALSE)
		{
			return $sql;
		}
		elseif ( ! is_array($binds))
		{
			$binds = array($binds);
			$bind_count = 1;
		}
		else
		{
			// Make sure we're using numeric keys
			$binds = array_values($binds);
			$bind_count = count($binds);
		}

		// We'll need the marker length later
		$ml = strlen($this->bind_marker);

		// Make sure not to replace a chunk inside a string that happens to match the bind marker
		if ($c = preg_match_all("/'[^']*'/i", $sql, $matches))
		{
			$c = preg_match_all('/'.preg_quote($this->bind_marker, '/').'/i',
				str_replace($matches[0],
					str_replace($this->bind_marker, str_repeat(' ', $ml), $matches[0]),
					$sql, $c),
				$matches, PREG_OFFSET_CAPTURE);

			// Bind values' count must match the count of markers in the query
			if ($bind_count !== $c)
			{
				return $sql;
			}
		}
		elseif (($c = preg_match_all('/'.preg_quote($this->bind_marker, '/').'/i', $sql, $matches, PREG_OFFSET_CAPTURE)) !== $bind_count)
		{
			return $sql;
		}

		do
		{
			$c--;
			$escaped_value = $this->escape($binds[$c]);
			if (is_array($escaped_value))
			{
				$escaped_value = '('.implode(',', $escaped_value).')';
			}
			$sql = substr_replace($sql, $escaped_value, $matches[0][$c][1], $ml);
		}
		while ($c !== 0);

		return $sql;
	}

	/**
	 * "Smart" Escape String
	 *
	 * Escapes data based on type
	 * Sets boolean and null types
	 *
	 * @param	string
	 * @return	mixed
	 */
	public function escape($str)
	{
		if (is_array($str))
		{
			$str = array_map(array(&$this, 'escape'), $str);
			return $str;
		}
		elseif (is_string($str) OR (is_object($str) && method_exists($str, '__toString')))
		{
			return "'".$this->escape_str($str)."'";
		}
		elseif (is_bool($str))
		{
			return ($str === FALSE) ? 0 : 1;
		}
		elseif ($str === NULL)
		{
			return 'NULL';
		}

		return $str;
	}

	/**
	 * Escape String
	 *
	 * @param	string|string[]	$str	Input string
	 * @param	bool	$like	Whether or not the string will be used in a LIKE condition
	 * @return	string
	 */
	public function escape_str($str, $like = FALSE)
	{
		if (is_array($str))
		{
			foreach ($str as $key => $val)
			{
				$str[$key] = $this->escape_str($val, $like);
			}

			return $str;
		}

		$str = $this->_escape_str($str);

		// escape LIKE condition wildcards
		if ($like === TRUE)
		{
			return str_replace(
				array($this->_like_escape_chr, '%', '_'),
				array($this->_like_escape_chr . $this->_like_escape_chr, $this->_like_escape_chr . '%', $this->_like_escape_chr . '_'),
				$str
			);
		}

		return $str;
	}

	/**
	 * Platform-dependant string escape
	 *
	 * @param	string
	 * @return	string
	 */
	private function _escape_str($str)
	{
		return str_replace("'", "''", remove_invisible_characters($str));
	}

	/**
	 * Prep the query
	 *
	 * If needed, each database adapter can prep the query string
	 *
	 * @access	private called by execute()
	 * @param	string	an SQL query
	 * @return	string
	 */
	private function _prep_query($sql)
	{
		return $sql;
	}

	/**
	 * Display an error message
	 *
	 * @access	public
	 * @param	string	the error message
	 * @return	exit
	 */
	protected function display_error($error = '')
	{
		echo "<pre>$error</pre>";
		exit;
	}

	/**
     * Close connection
     * 
     * @return void
     */
    public function __destruct()
    {
    	$this->close();
    }
}