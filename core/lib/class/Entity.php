<?php

namespace Core;

class Entity
{
	static public string $table = '';
	static public string $fields = "*";
	static public string $where_added = "";

	public static function _setAutoTableName()
	{
		if(empty($table))
		{
			$parent_class = get_parent_class(static::class);
			if (!empty($parent_class))
			{
				$parts = explode('\\', $parent_class);
				$parent_class_name = end($parts);
				self::$table = strtolower($parent_class_name); // assign lowercase parent class name
			}
		}
	}


	/**
	 * Insert multiple lines into table
	 *
	 * @param array $data
	 * @param string $created_by
	 * @return array
	 */
	public static function hydrate(array $data, string $created_by=""):array
	{
		self::_setAutoTableName();

		$result = [];
		foreach($data as $row)
		{
			$result[] = DB(static::$table)->insert($row, $created_by);
		}
		return $result;
	}

	/**
	 * insert record
	 *
	 * @param array $row
	 * @param string $created_by
	 *
	 * @return int id of new record
	 */
	public static function insert(array $row, string $created_by=""):int
	{
		self::_setAutoTableName();
		return DB(static::$table)->insert($row, $created_by);
	}
	
	/**
	 * update record
	 *
	 * @param array  $row
	 * @param int|array  $where
	 * @param int    $limit
	 * @param string $updated_by
	 *
	 * @return int number of updated
	 */
	public static function update(array $row, int|string|array $where=[], int $limit=-1, string $updated_by=''):int
	{
		self::_setAutoTableName();
		return DB(static::$table)->update($row, $where, $limit, $updated_by);
	}
	
	/**
	 * @param array     $row
	 * @param array|string     $where
	 * @param array     $exclude_from_update
	 * @param string    $by
	 *
	 * @return int
	 */
	public static function replace(array $row, array|int|string $where=[], array $exclude_from_update=[], string $by=''):int
	{
		self::_setAutoTableName();

		$params = [];
		if(!is_array($where))
		{

		}
		else
		{
			$params = $where[1];
			$where = $where[0];
		}
		
		$rec = self::findOne($where, $params);
		if($rec)
		{
			$row2 = [];
			foreach($row as $key => $val)
			{
				if(!in_array($key, $exclude_from_update))
					$row2[$key] = $val;
			}
			return DB(static::$table)->update($row2, $where, 1, $by);
		}
		else
		{
			foreach($row as $key => $val)
			{
				if($key != 'id')
				{
					$row[$key] = $val;
				}
			}
			
			return DB(static::$table)->insert($row, $by);
		}
		
		
	}
	
	/**
	 * delete record
	 *
	 * @param int    $id
	 * @param string $deleted_by
	 *
	 * @return int number of deleted
	 */
	public static function delete(int|string|array $id_or_where, string $deleted_by=''):int
	{
		self::_setAutoTableName();
		return DB(static::$table)->delete($id_or_where, -1, $deleted_by);
	}
	
	/**
	 * get record by id
	 *
	 * @param int    $id
	 * @param string $fields
	 *
	 * @return false|array
	 */
	public static function findById(int $id, string $fields=''):false|array
	{
		self::_setAutoTableName();

		if(empty($fields))$fields = static::$fields;
		$record = self::findOne("id = {$id}", [], $fields, );
		return $record;
	}
	
	/**
	 * get one record
	 *
	 * @param string|array $where
	 * @param array  $params
	 * @param string $fields
	 * @param array  $links
	 * @param string $order_by
	 * @param int $limit 1
	 *
	 * @return false|array
	 */
	public static function findOne(string $where, array $params=[], string $fields='', array $links=[], string $order_by=''):false|array
	{
		self::_setAutoTableName();

		if(empty($fields)) $fields = static::$fields;
		
		if(!empty(static::$where_added))
			$where = (empty($where)) ? static::$where_added : "{$where} AND ".static::$where_added;
		
		$obj = DB()->select($fields)->from(static::$table)->where($where)->limit(1);
		
		if(!empty($order_by))
			$obj->orderBy($order_by);
		
		$record = $obj->executeSQL($params)->fetch();
		
		return $record;
	}
	
	/**
	 * get multiple record
	 * @param string $where
	 * @param array  $params
	 * @param string $fields
	 * @param string $group_by
	 * @param string $order_by
	 * @param int    $limit
	 * @param array  $links
	 *
	 * @return array|bool
	 */
	public static function all(string $where="", array $params=[], string $fields='', string $group_by='', string $order_by='', int $limit=-1, array $links=[])
	{
		self::_setAutoTableName();

		if(empty($fields))$fields = static::$fields;
		
		if(!empty(static::$where_added))
			$where = (empty($where)) ? static::$where_added : "{$where} AND ".static::$where_added;
		
		$obj = DB()->select($fields)->from(static::$table);
		
		if(!empty($where))$obj->where($where);
		if(!empty($order_by))$obj->orderBy($order_by);
		if(!empty($group_by))$obj->groupBy($group_by);
		if($limit != -1)$obj->limit($limit);
		
		$records = $obj->executeSQL($params)->fetchAll();
		
		return $records;
	}
	
	/**
	 * get records by one column
	 * @param string $where
	 * @param array  $params
	 * @param string $fields
	 * @param string $order_by
	 * @param int    $limit
	 *
	 * @return bool
	 */
	public static function allOne(string $where="", array $params=[], string $fields='id', string $order_by='', int $limit=-1):array
	{
		self::_setAutoTableName();

		if(empty($fields))$fields = static::$fields;
		
		if(!empty(static::$where_added))
			$where = (empty($where)) ? static::$where_added : "{$where} AND ".static::$where_added;
		
		$obj = DB()->select($fields)->from(static::$table)->where($where);
		if(!empty($order_by))$obj->orderBy($order_by);
		if($limit != -1)$obj->limit($limit);
		
		$records = $obj->executeSQL($params)->fetchAllOne();
		return $records;
		
	}
	
	/**
	 * get count
	 * @param string $conditions
	 * @param array  $binds
	 * @param string $count_field
	 *
	 * @return int
	 */
	public static function count(string $conditions, array $binds=[], string $count_field='*'):int
	{
		self::_setAutoTableName();

		$table = static::$table;
		$sql = "SELECT count({$count_field}) FROM {$table} WHERE {$conditions} LIMIT 1";
		$count = DB()->query($sql, $binds)->fetchOne();
		
		return $count;
	}

	/**
	 * return id or false if record is not found
	 *
	 * @param string $conditions
	 * @param array  $binds
	 * @param int    $id_allowed
	 *
	 * @return bool|int
	 */
	public static function found(string $conditions, array $binds=[], int $id_allowed = 0): bool|int
	{
		self::_setAutoTableName();

		if(stripos($conditions, "deleted = 'no'") === false)
		{
			if(!empty($conditions)) $conditions .= " and ";
			$conditions .= " deleted = 'no' ";
		}

		if($id_allowed)
			$conditions .= " and id != {$id_allowed}";


		
		$table = static::$table;
		$sql = "SELECT id FROM {$table} WHERE {$conditions} LIMIT 1";



		
		$found = DB()->query($sql, $binds)->fetch();
		if(is_array($found))$found = $found['id'];
		
		return $found;
	}

	/**
	 * Paginate results based on the provided query and parameters.
	 *
	 * @param string $sql_added Additional SQL conditions to append to the query.
	 * @param array $params Parameters for the SQL query.
	 * @param string $sql_fields Fields to select in the query. Defaults to '*'.
	 * @param int $current_page Current page number for pagination. Defaults to 1.
	 * @param int $limit Number of records per page. Defaults to 15.
	 *
	 * @return array
	 */
	public static function paginate(string $sql_added, array $params, int $current_page=1, int $limit=15, string $sql_fields=''):array
	{
		self::_setAutoTableName();

		if(empty($sql_fields))
			$sql_fields = static::$fields;

		if(!empty($sql_added))
			$sql_added = " AND {$sql_added}";

		$table = static::$table;
		$sql = "SELECT
						{$sql_fields} 
	FROM
						{$table}
	WHERE 
		deleted = 'no' 
		{$sql_added}";

		return DB()->paginate($sql, $params, $current_page, $limit);
	}

	/**
	 * log in table xcore_entity_log
	 *
	 * @param string $category
	 * @param string $type
	 * @param string $entity
	 * @param int $entity_id
	 * @param string $action
	 * @param string $entity2
	 * @param int $entity2_id
	 * @param array $parameters
	 * @return void
	 */
	public static function log(string $category, string $type, string $entity, int $entity_id=0, string $action="", string $entity2='', int $entity2_id=0, array $parameters=[]):void
	{
		$f = [];
		$f['date'] = now();
		$f['category'] = $category;
		$f['type'] = $type;
		$f['entity'] = $entity;
		$f['entity_id'] = $entity_id;
		$f['action'] = $action;
		$f['entity2'] = $entity2;
		$f['entity2_id'] = $entity2_id;

		$f['parameters'] = json_encode($parameters);

		DB('xcore_entity_log')->insert($f);
	}


}