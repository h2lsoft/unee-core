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
		'text' => ['contains', '!contains', 'eq', '!eq', 'begins', '!begins', 'ends', '!ends', 'empty', '!empty'],
		'number' => ['eq', '!eq', 'lte', 'gte', 'empty', '!empty'],
		'date' => ['eq', 'lte', 'gte', 'empty', '!empty'],
		'select' => ['eq', '!eq', 'in'],
		'boolean' => ['eq'],
		'multiselect' => ['eq', '!eq'], // column holding a json array of values: eq = the value is in it
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
	public array $per_page_options = [10, 25, 100, 500, 1000];
	public bool $batch = true;
	public array $batch_edit = [];
	public string $position = '';
	public array $batch_actions = [];
	public array $row_actions = [];
	public array $grid_actions = [];
	public bool $detail = false;
	public bool $duplicate = true;
	public array $mobile = ['title' => '', 'subtitle' => ''];

	// folder of the module (\Core\CrudController), urls of the file columns
	public string $module = '';

	private string $last = '';
	private $hook = null;

	/**
	 * @param string $table table with the soft delete columns (deleted = 'no' is always added)
	 * @param int $per_page default rows per page, one of per_page_options
	 */
	public function __construct(public string $table, public int $per_page=100)
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
	 * @param string $color_column column holding the color of each row (ex: xcore_list.color), replaces $colors
	 *
	 * @return static
	 */
	public function addColBadge(string $name, string $label='', bool $sortable=false, array $colors=[], string $color_column=''):static
	{

		return $this->addCol($name, $label, $sortable, 'badge', ['colors' => (object)$colors] + (($color_column !== '') ? ['color_column' => $color_column] : []));

	}

	/**
	 * value shown as a mui chip whose look comes from columns of the row (ex: xcore_list)
	 *
	 * @param string $name
	 * @param string $label
	 * @param string $class_column color and variant, ex: success outlined
	 * @param string $style_column inline css added
	 * @param string $icon_column material symbol name
	 *
	 * @return static
	 */
	public function addColChip(string $name, string $label='', string $class_column='', string $style_column='', string $icon_column=''):static
	{

		return $this->addCol($name, $label, false, 'chip', array_filter(['class_column' => $class_column, 'style_column' => $style_column, 'icon_column' => $icon_column]));

	}

	/**
	 * tags of the rows (xcore_tag, \Core\Tag) shown as chips, column _tags
	 */
	public function addColTags(string $label='Tags'):static
	{

		$this->qSelect(\Core\Tag::sql($this->table), '_tags');
		return $this->addCol('_tags', $label, false, 'tags');

	}

	/**
	 * enum('yes','no') column, toggle = switch in the grid (PATCH /api/<module>/<id>/toggle/, edit right)
	 *
	 * @param string $name
	 * @param string $label
	 * @param bool $sortable
	 * @param bool $toggle
	 * @param bool $checkbox read only checked box instead of Yes / No (ignored with toggle)
	 *
	 * @return static
	 */
	public function addColBoolean(string $name, string $label='', bool $sortable=false, bool $toggle=false, bool $checkbox=false):static
	{

		return $this->addCol($name, $label, $sortable, 'boolean', ['toggle' => $toggle, 'checkbox' => $checkbox]);

	}

	public function addColDate(string $name, string $label='', bool $sortable=false):static
	{

		return $this->addCol($name, $label, $sortable, 'date');

	}

	public function addColDatetime(string $name, string $label='', bool $sortable=false):static
	{

		return $this->addCol($name, $label, $sortable, 'datetime');

	}

	/**
	 * material symbol icon (name stored in the column), color = column holding its color (#rrggbb)
	 */
	public function addColIcon(string $name, string $label='', string $color_column=''):static
	{

		return $this->addCol($name, $label, false, 'icon', ['align' => 'center'] + (($color_column !== '') ? ['color_column' => $color_column] : []));

	}

	/**
	 * color swatch (#rrggbb)
	 */
	public function addColColor(string $name, string $label=''):static
	{

		return $this->addCol($name, $label, false, 'color');

	}

	/**
	 * language shown as its flag (\Core\I18n::flag()), the code in its tooltip
	 */
	public function addColFlag(string $name, string $label='Lang', bool $sortable=false):static
	{

		return $this->addCol($name, $label, $sortable, 'flag', ['align' => 'center', 'flag_column' => "_flag_{$name}"]);

	}

	/**
	 * email shown as an icon (mailto link, the address in its tooltip)
	 */
	public function addColEmail(string $name, string $label='Email'):static
	{

		return $this->addCol($name, $label, false, 'email', ['align' => 'center', 'width' => 70]);

	}

	/**
	 * file of a form field addFile(): icon of its type, name and size, link to GET /api/<module>/<id>/file/<name>/
	 */
	public function addColFile(string $name, string $label='File'):static
	{

		return $this->addCol($name, $label, false, 'file', ['file_column' => "_file_{$name}"]);

	}

	/**
	 * number shown as a badge with an icon, ex: count of a qSelect
	 */
	public function addColCount(string $name, string $label='', string $icon=''):static
	{

		return $this->addCol($name, $label, false, 'count', ['align' => 'center', 'icon' => $icon]);

	}

	/**
	 * manual order: drag handle in the first column (POST /api/<module>/batch/position/, edit right), list sorted by this column
	 *
	 * @param string $column integer column of the table
	 *
	 * @return static
	 */
	public function addColPosition(string $column='position'):static
	{

		$this->position = $column;
		$this->setOrderByFirst($column, 'asc');

		return $this->addCol($column, '', true, 'position', ['width' => 40]);

	}

	public function addColNumber(string $name, string $label='', bool $sortable=false):static
	{

		return $this->addCol($name, $label, $sortable, 'number', ['align' => 'right']);

	}

	/**
	 * the value of the last column links to a page of the backend, {id} and {column} replaced by the values of the row
	 * ex: link('plugin/?search[]=xcore_menu_id||{id}'), check the right of the target module before declaring it
	 *
	 * @param string $url
	 *
	 * @return static
	 */
	public function link(string $url):static
	{

		$this->columns[$this->last]['link'] = $url;
		return $this;

	}

	/**
	 * labels shown for the values of the last column (ex: a badge in progress => En cours), translated
	 *
	 * @param array $labels value => label
	 *
	 * @return static
	 */
	public function labels(array $labels):static
	{

		$this->columns[$this->last]['labels'] = $labels;
		return $this;

	}

	/**
	 * avatar of a user before the value of the last column, from the columns of the row: id, firstname, lastname, login, avatar
	 *
	 * @return static
	 */
	public function avatar():static
	{

		$this->columns[$this->last]['avatar_column'] = "_avatar_{$this->last}";
		return $this;

	}

	/**
	 * a click on the value of the last column filters the grid on a column of the row (ex: group name -> xcore_group_id), a second click removes the filter
	 *
	 * @param string $column filter of the grid (addFilter / addFilterChips) and column of the row
	 *
	 * @return static
	 */
	public function filterOnClick(string $column):static
	{

		$this->columns[$this->last]['filter_column'] = $column;
		return $this;

	}

	/**
	 * second line in small under the value of the last column, ex: the description under the name
	 *
	 * @param string $column column of the row
	 * @param string $url_column column of the row holding a url: the second line is a link opened in a new tab
	 *
	 * @return static
	 */
	public function subText(string $column, string $url_column=''):static
	{

		$this->columns[$this->last]['sub_column'] = $column;
		if($url_column !== '')$this->columns[$this->last]['sub_url_column'] = $url_column;
		return $this;

	}

	/**
	 * relative date shown instead of the date of the last column (date_humanizer), ex: 3 hours ago, the full date in its tooltip
	 *
	 * @return static
	 */
	public function humanize():static
	{

		$this->columns[$this->last]['human_column'] = "_human_{$this->last}";
		return $this;

	}

	/**
	 * last column shown very small and grey (secondary information, ex: log message)
	 *
	 * @return static
	 */
	public function small():static
	{

		$this->columns[$this->last]['small'] = true;
		return $this;

	}

	/**
	 * last column shown small in a <code> tag (technical values: method, class, path)
	 *
	 * @return static
	 */
	public function code():static
	{

		$this->columns[$this->last]['code'] = true;
		return $this;

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

	/**
	 * action duplicate in the menu of a row, before delete (add and edit rights, on by default, duplicate(false) to remove it): the add form prefilled with the record, files and passwords empty
	 *
	 * @return static
	 */
	public function duplicate(bool $duplicate=true):static
	{

		$this->duplicate = $duplicate;
		return $this;

	}

	/**
	 * fields of the form editable on several records at once (selection menu: mass edit, edit right)
	 *
	 * @param array $fields names of form fields, a unique field is refused
	 *
	 * @return static
	 */
	public function batchEdit(array $fields):static
	{

		$this->batch_edit = $fields;
		return $this;

	}

	/**
	 * action of the module in the selection menu: POST /api/<module>/batch/<name>/ {"ids"} calls the controller method batch<Name>(array $records)
	 *
	 * @param string $name a-z, 0-9 and dashes, ex: send-invoice calls batchSendInvoice()
	 * @param string $label english, translated
	 * @param string $icon icon name (core/theme/core-admin/src/components/icons.tsx)
	 * @param string $right right needed on the module
	 *
	 * @return static
	 */
	public function batchAction(string $name, string $label, string $icon='', string $right='edit'):static
	{

		if(!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) || in_array($name, ['delete', 'update', 'form', 'position'], true))throw new \InvalidArgumentException("batch action name `{$name}` not allowed");
		$this->batch_actions[$name] = ['name' => $name, 'label' => $label, 'icon' => $icon, 'right' => $right];

		return $this;

	}

	/**
	 * action in the menu of each row
	 * url: link of the backend, {id} replaced (ex: right/?group={id}), empty: POST /api/<module>/<id>/action/<name>/ calls the controller method row<Name>(array $record)
	 *
	 * @param string $name a-z, 0-9 and dashes, ex: connect-as calls rowConnectAs()
	 * @param string $label english, translated
	 * @param string $icon material symbol name
	 * @param string $right right needed on the module (a link to another module: check its right before declaring it)
	 * @param string $url
	 *
	 * @return static
	 */
	public function rowAction(string $name, string $label, string $icon='', string $right='edit', string $url=''):static
	{

		$this->checkActionName($name);
		$this->row_actions[$name] = ['name' => $name, 'label' => $label, 'icon' => $icon, 'right' => $right, 'url' => $url];

		return $this;

	}

	/**
	 * button of the grid header: POST /api/<module>/action/<name>/ calls the controller method action<Name>()
	 *
	 * @param string $name a-z, 0-9 and dashes, ex: purge calls actionPurge()
	 * @param string $label english, translated
	 * @param string $icon material symbol name
	 * @param string $right right needed on the module
	 * @param string $confirm english question asked before (empty = no confirmation)
	 * @param bool $danger red button
	 *
	 * @return static
	 */
	public function gridAction(string $name, string $label, string $icon='', string $right='edit', string $confirm='', bool $danger=false):static
	{

		$this->checkActionName($name);
		$this->grid_actions[$name] = ['name' => $name, 'label' => $label, 'icon' => $icon, 'right' => $right, 'confirm' => $confirm, 'danger' => $danger];

		return $this;

	}

	private function checkActionName(string $name):void
	{

		if(!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name))throw new \InvalidArgumentException("action name `{$name}` not allowed");

	}

	/**
	 * a click on a row opens its read only view (GET /api/<module>/<id>/detail/, controller method detail()), ex: logs
	 */
	public function setDetail(bool $detail=true):static
	{

		$this->detail = $detail;
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
	 * @param string $type text, number, date, select, boolean, multiselect (json array column, ex: xcore_group_ids)
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
	 * filter on the tags of the rows (xcore_tag)
	 */
	public function addFilterTags(string $label='Tags'):static
	{

		return $this->addFilter('_tags', $label, 'text', [], \Core\Tag::sql($this->table));

	}

	/**
	 * quick filter buttons on one column (equality)
	 *
	 * @param string $name column of the table
	 * @param array $options value => label, empty = distinct values of the column
	 * @param bool $all tab All, false = a tab is always active (the first by default)
	 * @param bool $hide_empty chips counting 0 rows hidden (the active one stays), All too when the total is 0, false = all the chips shown
	 *
	 * @return static
	 */
	public function addFilterChips(string $name, array $options=[], bool $all=true, bool $hide_empty=true):static
	{

		$this->chips = ['name' => $name, 'options' => $options, 'all' => $all, 'hide_empty' => $hide_empty];
		return $this;

	}

	/**
	 * background of the chips by value, badge colors (grey, orange, red, green, blue...), ex: ['error' => 'red']
	 *
	 * @param array $colors value => color
	 *
	 * @return static
	 */
	public function chipColors(array $colors):static
	{

		$this->chips['colors'] = $colors;
		return $this;

	}

	/**
	 * json schema of a row and the query parameters of the list (OpenAPI documentation)
	 *
	 * @return array {row: schema, parameters: [...]}
	 */
	public function apiDoc():array
	{

		$properties = ['id' => ['type' => 'integer']];
		foreach($this->columns as $name => $c)
		{

			$properties[$name] = match($c['type'])
			{
				'number' => ['type' => 'number'],
				'boolean' => ['type' => 'string', 'enum' => ['yes', 'no']],
				'date' => ['type' => 'string', 'format' => 'date'],
				'datetime' => ['type' => 'string'],
				default => ($name === 'id') ? ['type' => 'integer'] : ['type' => 'string'],
			} + ['title' => ts($c['label'])];

		}

		$filters = [];
		foreach($this->filters as $name => $f)
			$filters[] = "{$name} (".implode(', ', self::OPERATORS[$f['type']]).')';

		$sortable = array_keys(array_filter($this->columns, fn($c) => $c['sortable']));

		$parameters = [
			['name' => 'search[]', 'in' => 'query', 'style' => 'form', 'explode' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
			 'description' => "column||value (equal), column||operator||value, q||text (search box)\n\nfilters: ".implode(', ', $filters).(($this->chips) ? "\n\ntabs: {$this->chips['name']}||value" : '')],
			['name' => 'match', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['all', 'any'], 'default' => 'all']],
			['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => array_values(array_unique(array_merge([$this->order_by], $sortable))), 'default' => $this->order_by]],
			['name' => 'dir', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => $this->order_dir]],
			['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
			['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => $this->per_page_options, 'default' => $this->per_page]],
		];

		return ['row' => ['type' => 'object', 'properties' => $properties], 'parameters' => $parameters];

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

			// tags: eq = the row has this tag (_tags||s1), !eq = it has not
			if($name === '_tags' && in_array($operator, ['eq', '!eq'], true))
			{

				$binds["_c{$i}"] = $value;
				$parts[] = (($operator === '!eq') ? 'NOT ' : '')."EXISTS(SELECT 1 FROM xcore_tag t WHERE t.deleted = 'no' AND t.signature = '{$this->table}' AND t.record_id = `{$this->table}`.id AND t.tag = :_c{$i})";

			}
			elseif($filter['type'] === 'multiselect')
			{

				$binds["_c{$i}"] = '%'.$this->escapeLike(json_encode((string)$value)).'%';
				$parts[] = (($operator === '!eq') ? 'NOT ' : '')."COALESCE({$filter['sql']}, '') LIKE :_c{$i}";

			}
			else $parts[] = $this->filterSql($filter['sql'], $operator, $value, $binds, "_c{$i}");
			$conditions[] = ['f' => $name, 'o' => $operator, 'v' => $value];

		}
		if($parts)$wheres[] = '('.implode(($match === 'any') ? ' OR ' : ' AND ', $parts).')';

		// chips, counted with the search and the filters but without the chip itself
		$chips = null;
		if($this->chips)
		{

			$chips = ['name' => $this->chips['name'], 'all' => $this->chips['all']] + $this->chipOptions(implode(' AND ', $wheres), $binds);
			if($this->chips['hide_empty'])
			{

				// chips at 0 hidden (the active one stays), All too when nothing is counted and no chip is active
				$chips['options'] = array_values(array_filter($chips['options'], fn($o) => $o['count'] > 0 || $o['value'] === $chip));
				if($chips['total'] === 0 && $chip === '')$chips['all'] = false;

			}
			if($chip === '' && !$this->chips['all'] && $chips['options'])$chip = $chips['options'][0]['value'];
			$chips['active'] = $chip;

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
		$keep = array_flip(array_merge(['id'], array_keys($this->columns), ...array_map(fn($key) => array_filter(array_column($this->columns, $key)), ['color_column', 'class_column', 'style_column', 'icon_column', 'avatar_column', 'filter_column', 'human_column', 'file_column', 'sub_column', 'sub_url_column', 'flag_column'])));
		$locale = (string)(App()->locale ?? 'en');
		foreach($recs as $rec)
		{

			if($this->hook)$rec = call_user_func($this->hook, $rec);

			// a column named like a password is never sent to the browser
			foreach($this->columns as $name => $column)
				if(preg_match('/password|pwd/i', $name))$rec[$name] = ((string)($rec[$name] ?? '') === '') ? '' : '*****';

			// computed values of the columns: user avatar, relative date
			foreach($this->columns as $name => $column)
			{

				if(!empty($column['avatar_column']))$rec[$column['avatar_column']] = \Core\Widget::avatar($rec)['avatar'];
				if(!empty($column['flag_column']))$rec[$column['flag_column']] = \Core\I18n::flag((string)($rec[$name] ?? ''));
				if(!empty($column['human_column']))$rec[$column['human_column']] = date_humanizer($rec[$name] ?? null, $locale);
				if(!empty($column['file_column']) && (string)($rec[$name] ?? '') !== '')$rec[$column['file_column']] = ['name' => basename((string)$rec[$name]), 'size' => \Core\File::privateSize((string)$rec[$name]), 'url' => "/api/{$this->module}/{$rec['id']}/file/{$name}/"];

			}
			$row = array_intersect_key($rec, $keep);
			$row['id'] = (int)$row['id'];
			$rows[] = $row;

		}

		return [
			'columns' => array_values(array_map(fn($c) => ['label' => ts($c['label'])] + ((isset($c['labels'])) ? ['labels' => (object)array_map(fn($l) => ts((string)$l), $c['labels'])] : []) + $c, $this->columns)),
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
			'batch' => ($this->batch) ? [
				'edit' => array_values($this->batch_edit),
				'actions' => array_values(array_map(fn($a) => ['label' => ts($a['label'])] + $a, $this->batch_actions)),
			] : null,
			'detail' => $this->detail,
			'duplicate' => $this->duplicate,
			'row_actions' => array_values(array_map(fn($a) => ['label' => ts($a['label'])] + $a, $this->row_actions)),
			'grid_actions' => array_values(array_map(fn($a) => ['label' => ts($a['label']), 'confirm' => ($a['confirm'] !== '') ? ts($a['confirm']) : ''] + $a, $this->grid_actions)),
			'mobile' => $this->mobile,
			'position' => ($this->position !== '') ? $this->position : null,
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
			'contains', '!contains' => '%'.$this->escapeLike($value).'%',
			'begins', '!begins' => $this->escapeLike($value).'%',
			'ends', '!ends' => '%'.$this->escapeLike($value),
			default => $value,
		};

		return match($operator)
		{
			'contains', 'begins', 'ends' => "{$sql} LIKE :{$key}",
			// null holds no text: kept by the negations
			'!contains', '!begins', '!ends' => "({$sql} IS NULL OR {$sql} NOT LIKE :{$key})",
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
		{

			$list[$k]['count'] = $counts[$option['value']] ?? 0;
			if(isset($this->chips['colors'][$option['value']]))$list[$k]['color'] = $this->chips['colors'][$option['value']];

		}

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
