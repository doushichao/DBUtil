# DBUtil
For MySQLi Database Framework

```php
include_once "DB.php";

$db = new DB([
	'hostname' => 'localhost',
	'username' => 'root',
	'password' => 'youpassword',
	'database' => 'youdatabase'
	]);

$sql = "SELECT id, name FROM user WHERE id = ?";
$id = 2;
$row = $db->query($sql, array($id))->result_array();

print_r($row);
```
