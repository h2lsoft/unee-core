<?php

namespace Component;

use Symfony\Component\HttpFoundation\Request;

/**
 * declarative grid rendered as json for the react backend (\Core\CrudController::apiList)
 * only the declared columns (and id) are sent, sort and filters are whitelisted by the declaration
 *
 * query string: search[]=column||value (equal) or column||operator||value (operator without value: column||empty||), search[]=q||text (search box),
 * the short form on the chips column = the active tab (column||eq||value is a condition), match=all|any, sort, dir (asc, desc), page, per_page
 */
class DataGrid
{

	/**
	 * operators allowed by filter type, the first one is the default
	 */
	public const array OPERATORS = [
		'text' => ['contains', 'eq', '!eq', 'begins', 'ends', 'empty', '!empty'],
		'number' => ['eq', '!eq', 'lte', 'gte', 'empty', '!empty'],
		'date' => ['eq', 'lte', 'gte', 'empty', '!empty'],
		'select' => ['eq', '!eq', 'in'],
		'boolean' => ['eq'],
	];

	/**
	 * conditions of the advanced filters kept from the request
	 */
	public const int MAX_CONDITIONS = 20;

	public array $columns = [];
	public array $filters = [];
	public ?array $chips = null;
	public array $search_columns = [];
	public string $search_placeholder = '';
	public array $selects = [];
	public string $where = '';
	public array $binds = [];
	public string $order_by = 'id';
	public string $order_dir = 'desc';
	public array $per_page_options = [25, 50, 100];
	public bool $batch = true;
	public array $mobile = ['title' => '', 'subtitle' => ''];

	private string $last = '';
	private $hook = null;

	/**
	 * @param string $table table with the soft delete columns (deleted = 'no' is always added)
	 * @param int $per_page default rows per page, one of per_page_options
	 */
	public function __construct(public string $table, public int $per_page=25)
	{
	}

	// columns *********************************************************************************************************

	/**
	 * text column
	 *
	 * @param string $name column of the table or alias of qSelect
	 * @param string $label english label, translated
	 * @param bool $sortable
	 * @param string $type text, badge, boolean, date, datetime, number
	 * @param array $options ex: ['align' => 'right', 'colors' => [...], 'toggle' => true]
	 *
	 * @return static
	 */
	public function addCol(string $name, string $label='', bool $sortable=false, string $type='text', array $options=[]):static
	{

		$this->columns[$name] = ['name' => $name, 'label' => ($label === '') ? $name : $label, 'type' => $type, 'sortable' => $sortable] + $options;
		$this->last = $name;

		return $this;

	}

	/**
	 * value shown as a colored badge
	 *
	 * @param string $name
	 * @param string $label
	 * @param bool $sortable
	 * @param array $colors value => grey, orange, red, green, blue, purple, yellow, cyan (default grey)
	 *
	 * @return static
	 */
	public function addColBadge(string $name, string $label='', bool $sortable=false, array $colors=[]):static
	{

		return $this->addCol($name, $label, $sortable, 'badge', ['colors' => (object)$colors]);

	}

	/**
	 * enum('yes','no') column, toggle = switch in the grid (PATCH /api/<module>/<id>/toggle/, edit right)
	 *
	 * @param string $name
	 * @param string $label
	 * @param bool $sortable
	 * @param bool $toggle
	 *
	 * @return static
	 */
	public function addColBoolean(string $name, string $label='', bool $sortable=false, bool $toggle=false):static
	{

		return $this->addCol($name, $label, $sortable, 'boolean', ['toggle' => $toggle]);

	}

	public function addColDate(string $name, string $label='', bool $sortable=false):static
	{

		return $this->addCol($name, $label, $sortable, 'date');

	}

	public function addColDatetime(string $name, string $label='', bool $sortable=false):static
	{

		return $this->addCol($name, $label, $sortable, 'datetime');

	}

	public function addColNumber(string $name, string $label='', bool $sortable=false):static
	{

		return $this->addCol($name, $label, $sortable, 'number', ['align' => 'right']);

	}

	/**
	 * column width in px for the last column
	 *
	 * @param int $px
	 *
	 * @return static
	 */
	public function width(int $px):static
	{

		$this->columns[$this->last]['width'] = $px;
		return $this;

	}

	// sql *************************************************************************************************************

	/**
	 * computed column, ex: qSelect("(SELECT name FROM xcore_group WHERE id = xcore_group_id)", 'group_name')
	 *
	 * @param string $raw sql expression (from code, never from the request)
	 * @param string $alias
	 *
	 * @return static
	 */
	public function qSelect(string $raw, string $alias):static
	{

		$this->selects[$alias] = $raw;
		return $this;

	}

	/**
	 * condition always applied
	 *
	 * @param string $where sql with named parameters
	 * @param array $binds
	 *
	 * @return static
	 */
	public function qWhere(string $where, array $binds=[]):static
	{

		$this->where = ($this->where === '') ? "({$where})" : "{$this->where} AND ({$where})";
		$this->binds += $binds;

		return $this;

	}

	public function setOrderByFirst(string $column, string $direction='asc'):static
	{

		$this->order_by = $column;
		$this->order_dir = (strtolower($direction) === 'desc') ? 'desc' : 'asc';

		return $this;

	}

	public function setPerPageOptions(array $options):static
	{

		$this->per_page_options = array_map('intval', $options);
		return $this;

	}

	public function setBatchMode(bool $batch):static
	{

		$this->batch = $batch;
		return $this;

	}

	/**
	 * columns shown in the cards of the mobile view, empty = first columns
	 *
	 * @param string $title
	 * @param string $subtitle
	 *
	 * @return static
	 */
	public function setMobile(string $title, string $subtitle=''):static
	{

		$this->mobile = ['title' => $title, 'subtitle' => $subtitle];
		return $this;

	}

	/**
	 * row transformation before output, receives and returns the full row
	 *
	 * @param callable $callback fn(array $row): array
	 *
	 * @return static
	 */
	public function hookData(callable $callback):static
	{

		$this->hook = $callback;
		return $this;

	}

	// search and filters **********************************************************************************************

	/**
	 * search box, LIKE on each column
	 *
	 * @param array $columns columns of the table
	 * @param string $placeholder english, translated
	 *
	 * @return static
	 */
	public function setSearch(array $columns, string $placeholder='Search...'):static
	{

		$this->search_columns = $columns;
		$this->search_placeholder = $placeholder;

		return $this;

	}

	/**
	 * filter with operators (const OPERATORS)
	 *
	 * @param string $name
	 * @param string $label
	 * @param string $type text, number, date, select, boolean
	 * @param array $options select: value => label
	 * @param string $sql expression filtered, empty = the column `name`
	 *
	 * @return static
	 */
	public function addFilter(string $name, string $label='', string $type='text', array $options=[], string $sql=''):static
	{

		if(!isset(self::OPERATORS[$type]))throw new \InvalidArgumentException("filter type `{$type}` unknown");
		if($type === 'boolean' && !$options)$options = ['yes' => 'Yes', 'no' => 'No'];

		$this->filters[$name] = ['name' => $name, 'label' => ($label === '') ? $name : $label, 'type' => $type, 'options' => $options, 'sql' => ($sql === '') ? $this->quote($name) : $sql];

		return $this;

	}

	/**
	 * quick filter buttons on one column (equality)
	 *
	 * @param string $name column of the table
	 * @param array $options value => label, empty = distinct values of the column
	 *
	 * @return static
	 */
	public function addFilterChips(string $name, array $options=[]):static
	{

		$this->chips = ['name' => $name, 'options' => $options];
		return $this;

	}

	// render **********************************************************************************************************

	/**
	 * definition and page of rows
	 *
	 * @param Request $request
	 *
	 * @return array
	 */
	public function render(Request $request):array
	{

		$binds = $this->binds;
		$wheres = ["deleted = 'no'"];
		if($this->where !== '')$wheres[] = $this->where;

		// search[]: column||value (equal), column||operator||value, q||text = search box, short form on the chips column = the active tab
		$q = '';
		$chip = '';
		$entries = [];
		foreach(array_slice((array)$request->query->all('search'), 0, self::MAX_CONDITIONS + 2) as $raw)
		{

			$parts = explode('||', (string)$raw, 3);
			if(count($parts) < 2)continue;

			$short = (count($parts) === 2);
			[$name, $operator, $value] = ($short) ? [$parts[0], 'eq', $parts[1]] : $parts;
			$value = trim($value);

			if($name === 'q' && $q === '')$q = $value;
			elseif($short && $this->chips && $name === $this->chips['name'] && $chip === '' && $value !== '')$chip = $value;
			else $entries[] = [$name, $operator, $value];

		}

		if($q !== '' && $this->search_columns)
		{

			$parts = [];
			foreach(array_values($this->search_columns) as $i => $column)
			{

				$parts[] = "{$this->quote($column)} LIKE :_q{$i}";
				$binds["_q{$i}"] = '%'.$this->escapeLike($q).'%';

			}
			$wheres[] = '('.implode(' OR ', $parts).')';

		}

		// conditions of the advanced filters, combined with AND (match=all) or OR (match=any)
		$match = ($request->query->get('match') === 'any') ? 'any' : 'all';
		$conditions = [];
		$parts = [];
		foreach(array_slice($entries, 0, self::MAX_CONDITIONS) as $i => [$name, $operator, $value])
		{

			if(!isset($this->filters[$name]))continue;

			$filter = $this->filters[$name];
			if(!in_array($operator, self::OPERATORS[$filter['type']], true))continue;
			if($value === '' && !in_array($operator, ['empty', '!empty'], true))continue;

			$parts[] = $this->filterSql($filter['sql'], $operator, $value, $binds, "_c{$i}");
			$conditions[] = ['f' => $name, 'o' => $operator, 'v' => $value];

		}
		if($parts)$wheres[] = '('.implode(($match === 'any') ? ' OR ' : ' AND ', $parts).')';

		// chips, counted with the search and the filters but without the chip itself
		$chips = null;
		if($this->chips)
		{

			$chips = ['name' => $this->chips['name'], 'active' => $chip] + $this->chipOptions(implode(' AND ', $wheres), $binds);

			if($chip !== '')
			{

				$wheres[] = "{$this->quote($this->chips['name'])} = :_chip";
				$binds['_chip'] = $chip;

			}

		}

		$where = implode(' AND ', $wheres);

		// sort
		$sort = (string)$request->query->get('sort', $this->order_by);
		$sortable = array_keys(array_filter($this->columns, fn($c) => $c['sortable']));
		if(!in_array($sort, $sortable, true) && $sort !== $this->order_by)$sort = $this->order_by;
		$dir = strtolower((string)$request->query->get('dir', $this->order_dir));
		if(!in_array($dir, ['asc', 'desc'], true))$dir = $this->order_dir;

		// pagination
		$per_page = (int)$request->query->get('per_page', $this->per_page);
		if(!in_array($per_page, $this->per_page_options, true))$per_page = $this->per_page;
		$total = (int)DB()->query("SELECT COUNT(*) FROM {$this->quote($this->table)} WHERE {$where}", $binds)->fetchOne();
		$last_page = max(1, (int)ceil($total / $per_page));
		$page = min(max(1, (int)$request->query->get('page', 1)), $last_page);
		$offset = ($page - 1) * $per_page;

		$fields = '*';
		foreach($this->selects as $alias => $raw)
			$fields .= ", {$raw} AS {$this->quote($alias)}";

		// id as second key: stable order between pages
		$sql = "SELECT {$fields} FROM {$this->quote($this->table)} WHERE {$where} ORDER BY {$this->quote($sort)} {$dir}, id {$dir} LIMIT {$offset}, {$per_page}";
		$recs = DB()->query($sql, $binds)->fetchAll();

		$rows = [];
		$keep = array_flip(array_merge(['id'], array_keys($this->columns)));
		foreach($recs as $rec)
		{

			if($this->hook)$rec = call_user_func($this->hook, $rec);
			$row = array_intersect_key($rec, $keep);
			$row['id'] = (int)$row['id'];
			$rows[] = $row;

		}

		return [
			'columns' => array_values(array_map(fn($c) => ['label' => ts($c['label'])] + $c, $this->columns)),
			'filters' => array_values(array_map(fn($f) => [
				'name' => $f['name'],
				'label' => ts($f['label']),
				'type' => $f['type'],
				'operators' => self::OPERATORS[$f['type']],
				'options' => $this->options($f['options']),
			], $this->filters)),
			'conditions' => $conditions,
			'match' => $match,
			'q' => $q,
			'chips' => $chips,
			'search' => ($this->search_columns) ? ['placeholder' => ts($this->search_placeholder)] : null,
			'sort' => ['column' => $sort, 'dir' => $dir],
			'rows' => $rows,
			'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $per_page, 'last_page' => $last_page],
			'per_page_options' => $this->per_page_options,
			'batch' => $this->batch,
			'mobile' => $this->mobile,
		];

	}

	/**
	 * sql condition of a filter, values always bound
	 *
	 * @param string $sql expression
	 * @param string $operator
	 * @param string $value
	 * @param array $binds
	 * @param string $key prefix of the bind parameters
	 *
	 * @return string
	 */
	private function filterSql(string $sql, string $operator, string $value, array &$binds, string $key):string
	{

		switch($operator)
		{

			case 'empty': return "({$sql} IS NULL OR {$sql} = '')";
			case '!empty': return "({$sql} IS NOT NULL AND {$sql} != '')";
			case 'in':
				$parts = [];
				foreach(array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen')) as $i => $item)
				{

					$parts[] = ":{$key}_{$i}";
					$binds["{$key}_{$i}"] = $item;

				}
				return ($parts) ? "{$sql} IN(".implode(', ', $parts).')' : '1 = 0';

		}

		$binds[$key] = match($operator)
		{
			'contains' => '%'.$this->escapeLike($value).'%',
			'begins' => $this->escapeLike($value).'%',
			'ends' => '%'.$this->escapeLike($value),
			default => $value,
		};

		return match($operator)
		{
			'contains', 'begins', 'ends' => "{$sql} LIKE :{$key}",
			'!eq' => "{$sql} != :{$key}",
			'lte' => "{$sql} <= :{$key}",
			'gte' => "{$sql} >= :{$key}",
			default => "{$sql} = :{$key}",
		};

	}

	/**
	 * chip options with their count, distinct values of the column when not declared (kept while searching, count 0)
	 *
	 * @param string $where conditions of the list without the chip
	 * @param array $binds
	 *
	 * @return array {options: [{value, label, count}], total: rows of every value (all)}
	 */
	private function chipOptions(string $where, array $binds):array
	{

		$table = $this->quote($this->table);
		$column = $this->quote($this->chips['name']);

		$options = $this->chips['options'];
		if(!$options)
		{

			$base = "deleted = 'no'".(($this->where !== '') ? " AND {$this->where}" : '');
			$values = DB()->query("SELECT DISTINCT {$column} FROM {$table} WHERE {$base} AND {$column} IS NOT NULL AND {$column} != '' ORDER BY 1 LIMIT 50", $this->binds)->fetchAllOne();
			$options = array_combine($values, $values);

		}

		$counts = [];
		foreach(DB()->query("SELECT {$column} AS v, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY {$column}", $binds)->fetchAll() as $rec)
			$counts[(string)$rec['v']] = (int)$rec['n'];

		$list = $this->options($options);
		foreach($list as $k => $option)
			$list[$k]['count'] = $counts[$option['value']] ?? 0;

		return ['options' => $list, 'total' => array_sum($counts)];

	}

	/**
	 * value => label to a list (keeps the order in json), labels translated
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	private function options(array $options):array
	{

		$list = [];
		foreach($options as $value => $label)
			$list[] = ['value' => (string)$value, 'label' => ts((string)$label)];

		return $list;

	}

	/**
	 * backticks for an identifier, ex: name or table.name
	 *
	 * @param string $identifier
	 *
	 * @return string
	 */
	private function quote(string $identifier):string
	{

		if(!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $identifier))throw new \InvalidArgumentException("invalid identifier `{$identifier}`");
		return '`'.str_replace('.', '`.`', $identifier).'`';

	}

	private function escapeLike(string $value):string
	{

		return addcslashes($value, '\\%_');

	}

}
