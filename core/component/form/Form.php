<?php

namespace Component;

/**
 * declarative form rendered as json for the react backend (\Core\CrudController)
 * the declared fields are also the whitelist of the columns written (no mass assignment) and give the automatic rules
 *
 * ex: $form->addText('name', 'Name', true)->maxLength(255)->unique();
 */
class Form
{

	// fields with an uppercase initial in every form (typing and saving)
	public const array UCFIRST_NAMES = ['firstname', 'lastname'];

	public array $fields = [];
	public array $tabs = [];
	public array $actions = [];
	private string $last = '';
	private string $tab = '';

	// fields **********************************************************************************************************

	/**
	 * generic field
	 *
	 * @param string $type text, email, password, textarea, html, number, integer, date, datetime, boolean, select
	 * @param string $name column of the table
	 * @param string $label english, translated
	 * @param bool $required
	 * @param array $options
	 *
	 * @return static
	 */
	public function add(string $type, string $name, string $label='', bool $required=false, array $options=[]):static
	{

		$this->fields[$name] = ['name' => $name, 'type' => $type, 'label' => ($label === '') ? $name : $label, 'required' => $required, 'width' => 12] + $options + (($this->tab !== '') ? ['tab' => $this->tab] : []);
		$this->last = $name;

		return $this;

	}

	public function addText(string $name, string $label='', bool $required=false):static
	{

		return $this->add('text', $name, $label, $required);

	}

	public function addEmail(string $name, string $label='', bool $required=false):static
	{

		return $this->add('email', $name, $label, $required);

	}

	/**
	 * password: never sent back to the browser, empty on update = unchanged (hashing is done by the controller, onSaveBefore)
	 */
	public function addPassword(string $name, string $label='', bool $required=false):static
	{

		return $this->add('password', $name, $label, $required);

	}

	public function addTextarea(string $name, string $label='', bool $required=false, int $rows=4):static
	{

		return $this->add('textarea', $name, $label, $required, ['rows' => $rows]);

	}

	/**
	 * html content, cleaned with \Core\Sanitizer before saving
	 *
	 * @param string $profile key of config sanitizer
	 */
	public function addHtml(string $name, string $label='', bool $required=false, string $profile='basic'):static
	{

		return $this->add('html', $name, $label, $required, ['rows' => 8, 'sanitize' => $profile]);

	}

	/**
	 * material symbol name (https://fonts.google.com/icons), shown with a preview
	 */
	public function addIcon(string $name, string $label='', bool $required=false):static
	{

		return $this->add('icon', $name, $label, $required, ['max_length' => 64]);

	}

	/**
	 * color #rrggbb with a color picker
	 */
	public function addColor(string $name, string $label='', bool $required=false):static
	{

		return $this->add('color', $name, $label, $required, ['default' => '#6d4aff']);

	}

	public function addNumber(string $name, string $label='', bool $required=false, bool $integer=false):static
	{

		return $this->add(($integer) ? 'integer' : 'number', $name, $label, $required);

	}

	public function addDate(string $name, string $label='', bool $required=false):static
	{

		return $this->add('date', $name, $label, $required);

	}

	public function addDatetime(string $name, string $label='', bool $required=false):static
	{

		return $this->add('datetime', $name, $label, $required);

	}

	/**
	 * time hh:mm typed freely (9, 930, 9h30, 9:30) or picked in a list of $step minutes
	 * ->set('time_from', 'hour_start') on the end time: durations shown in its list, the duration kept when the start moves
	 */
	public function addTime(string $name, string $label='', bool $required=false, int $step=30):static
	{

		return $this->add('time', $name, $label, $required, ['step' => max(5, min(60, $step))]);

	}

	/**
	 * active users (and the current one) with their avatar, in an autocomplete
	 *
	 * @param string $name foreign key column, ex: xcore_user_id
	 * @param int $current id kept in the list when the user is inactive
	 */
	public function addSelectUser(string $name, string $label='User', bool $required=false, int $current=0):static
	{

		$options = [];
		$avatars = [];
		foreach(DB()->query("SELECT id, firstname, lastname, login, avatar FROM xcore_user WHERE deleted = 'no' AND (active = 'yes' OR id = :current) AND id > 1 ORDER BY firstname, lastname", ['current' => $current])->fetchAll() as $rec)
		{

			$cell = \Core\Widget::avatar($rec);
			$options[$rec['id']] = ($cell['title'] !== '') ? $cell['title'] : $rec['login'];
			$avatars[$rec['id']] = $cell['avatar'];

		}

		return $this->add('select', $name, $label, $required, ['options' => $options, 'translate' => false, 'autocomplete' => true, 'free' => false, 'avatars' => (object)$avatars]);

	}

	/**
	 * enum('yes','no') shown as a switch
	 */
	public function addBoolean(string $name, string $label=''):static
	{

		return $this->add('boolean', $name, $label, false, ['default' => 'no']);

	}

	/**
	 * select
	 *
	 * @param array $options value => label (labels translated)
	 */
	public function addSelect(string $name, string $label='', array $options=[], bool $required=false):static
	{

		return $this->add('select', $name, $label, $required, ['options' => $options]);

	}

	/**
	 * select filled from a table (non deleted rows)
	 *
	 * @param string $table
	 * @param string $label_column
	 * @param string $where sql with named parameters (from code)
	 * @param array $binds
	 */
	public function addSelectSql(string $name, string $label, string $table, string $label_column='name', bool $required=false, string $where='', array $binds=[]):static
	{

		$sql = "SELECT id, `{$label_column}` FROM `{$table}` WHERE deleted = 'no'".(($where !== '') ? " AND ({$where})" : '')." ORDER BY `{$label_column}`";
		$options = [];
		foreach(DB()->query($sql, $binds)->fetchAll() as $rec)
			$options[$rec['id']] = $rec[$label_column];

		return $this->add('select', $name, $label, $required, ['options' => $options, 'translate' => false]);

	}

	/**
	 * list of words typed as chips, stored as a json array (ex: actions of a plugin)
	 *
	 * @param array $suggestions words proposed in the list
	 * @param string $pattern regex of each word
	 */
	public function addTags(string $name, string $label='', bool $required=false, array $suggestions=[], string $pattern='/^[a-z0-9_-]{1,64}$/'):static
	{

		return $this->add('tags', $name, $label, $required, ['suggestions' => array_values($suggestions), 'pattern' => $pattern, 'default' => []]);

	}

	/**
	 * several values of a list, stored as a json array of the values (ex: ids of groups)
	 *
	 * @param array $options value => label (labels translated)
	 */
	public function addSelectMultiple(string $name, string $label='', array $options=[], bool $required=false):static
	{

		return $this->add('multiselect', $name, $label, $required, ['options' => $options, 'default' => []]);

	}

	/**
	 * file uploaded to private_dir/<dir> (POST /api/<module>/upload/<field>/ then saved with the form), the column keeps the path relative to private_dir
	 * served by GET /api/<module>/<id>/file/<field>/ (list right)
	 *
	 * @param string $dir folder in private_dir, ex: files
	 * @param array $extensions allowed extensions (\Core\File::FORBIDDEN_EXTENSIONS are always refused)
	 * @param int $max_size bytes, 0 = upload_max_filesize of php
	 */
	public function addFile(string $name, string $label='', bool $required=false, string $dir='files', array $extensions=[], int $max_size=0):static
	{

		return $this->add('file', $name, $label, $required, ['dir' => $dir, 'extensions' => array_values(array_map('strtolower', $extensions)), 'max_size' => $max_size]);

	}

	/**
	 * radio buttons on one line
	 *
	 * @param array $options value => label (labels translated)
	 */
	public function addRadio(string $name, string $label='', array $options=[], bool $required=false):static
	{

		return $this->add('radio', $name, $label, $required, ['options' => $options]);

	}

	/**
	 * tags of the record in xcore_tag (\Core\Tag), field _tags, suggestions = tags already used in the table
	 *
	 * @param string $table signature of the tags: table of the record
	 */
	public function addTagManager(string $table, string $label='Tags'):static
	{

		return $this->add('tags', '_tags', $label, false, ['suggestions' => \Core\Tag::suggestions($table), 'pattern' => \Core\Tag::PATTERN, 'default' => [], 'manager' => $table]);

	}

	/**
	 * signature of the tag manager of the form, '' when none
	 *
	 * @return string
	 */
	public function tagManager():string
	{

		return (string)($this->fields['_tags']['manager'] ?? '');

	}

	/**
	 * source code (html, json) edited with a code editor, written as typed: never shown as html in the backend
	 *
	 * @param string $language html, json
	 */
	public function addCode(string $name, string $label='', bool $required=false, string $language='html'):static
	{

		return $this->add('code', $name, $label, $required, ['language' => $language]);

	}

	// layout ***********************************************************************************************************

	/**
	 * the next fields go in a tab, tab('') ends the tabs (the next fields are under them)
	 *
	 * @param string $key unique key, ex: credentials
	 * @param string $label english, translated
	 * @param string $flag country code shown as a flag, ex: gb, fr
	 *
	 * @return static
	 */
	public function tab(string $key, string $label='', string $flag=''):static
	{

		$this->tab = $key;
		if($key !== '')$this->tabs[$key] = ['key' => $key, 'label' => ($label === '') ? $key : $label, 'flag' => $flag];

		return $this;

	}

	/**
	 * button of the form when a record is edited: POST /api/<module>/<id>/action/<name>/ with the values of the form, calls row<Name>(array $record, Request $request)
	 *
	 * @param string $name a-z, 0-9 and dashes
	 * @param string $label english, translated
	 * @param string $icon material symbol name
	 * @param string $right right needed on the module
	 *
	 * @return static
	 */
	public function addAction(string $name, string $label, string $icon='', string $right='edit'):static
	{

		if(!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name))throw new \InvalidArgumentException("action name `{$name}` not allowed");
		$this->actions[$name] = ['name' => $name, 'label' => $label, 'icon' => $icon, 'right' => $right];

		return $this;

	}

	// modifiers of the last field *************************************************************************************

	public function maxLength(int $length):static
	{

		return $this->set('max_length', $length);

	}

	/**
	 * value not used by another record (\Core\Validator::unique)
	 */
	public function unique():static
	{

		return $this->set('unique', true);

	}

	public function help(string $text):static
	{

		return $this->set('help', $text);

	}

	public function placeholder(string $text):static
	{

		return $this->set('placeholder', $text);

	}

	/**
	 * width on a 12 columns grid (desktop), 6 = half
	 */
	public function width(int $columns):static
	{

		return $this->set('width', max(1, min(12, $columns)));

	}

	public function defaultValue(mixed $value):static
	{

		return $this->set('default', $value);

	}

	/**
	 * select with a search field, free = any typed value is accepted (the options are suggestions)
	 */
	public function autocomplete(bool $free=false):static
	{

		$this->set('autocomplete', true);
		return $this->set('free', $free);

	}

	/**
	 * field shown only when another field has one of the values, its rules are checked only then
	 *
	 * @param string $field name of the other field
	 * @param array|string $values
	 */
	public function showIf(string $field, array|string $values):static
	{

		return $this->set('show_if', ['field' => $field, 'values' => array_map('strval', (array)$values)]);

	}

	/**
	 * shown, never written
	 */
	public function readonly(bool $readonly=true):static
	{

		return $this->set('readonly', $readonly);

	}

	/**
	 * set an option of the last field
	 *
	 * @param string $key
	 * @param mixed $value
	 *
	 * @return static
	 */
	public function set(string $key, mixed $value):static
	{

		$this->fields[$this->last][$key] = $value;
		return $this;

	}

	// output **********************************************************************************************************

	/**
	 * json definition, labels translated
	 *
	 * @return array
	 */
	public function definition():array
	{

		$fields = [];
		foreach($this->fields as $field)
		{

			$field['label'] = ts($field['label']);

			// same rules while typing (react)
			if($field['type'] === 'email' || $field['name'] === 'login')$field['lowercase'] = true;
			if(in_array($field['name'], self::UCFIRST_NAMES, true))$field['ucfirst'] = true;
			foreach(['help', 'placeholder'] as $key)
				if(isset($field[$key]))$field[$key] = ts($field[$key]);

			if(isset($field['options']))
			{

				$translate = ($field['translate'] ?? true);
				$options = [];
				foreach($field['options'] as $value => $label)
					$options[] = ['value' => (string)$value, 'label' => ($translate) ? ts((string)$label) : (string)$label];

				$field['options'] = $options;

			}

			unset($field['sanitize'], $field['translate'], $field['unique'], $field['pattern'], $field['manager'], $field['dir']);
			$fields[] = $field;

		}

		$tabs = array_values(array_map(fn($t) => ['label' => ts($t['label'])] + $t, $this->tabs));
		$actions = array_values(array_map(fn($a) => ['label' => ts($a['label'])] + $a, $this->actions));

		return ['fields' => $fields, 'tabs' => $tabs, 'actions' => $actions];

	}

	/**
	 * json schema of the body written by the form (OpenAPI documentation)
	 *
	 * @param bool $with_required false for a partial body (mass edit)
	 *
	 * @return array
	 */
	public function schema(bool $with_required=true):array
	{

		$properties = [];
		$required = [];
		foreach($this->fields as $name => $f)
		{

			if(!empty($f['readonly']))continue;

			$property = match($f['type'])
			{
				'number' => ['type' => 'number'],
				'integer' => ['type' => 'integer'],
				'email' => ['type' => 'string', 'format' => 'email'],
				'password' => ['type' => 'string', 'format' => 'password', 'writeOnly' => true],
				'date' => ['type' => 'string', 'format' => 'date'],
				'datetime' => ['type' => 'string', 'example' => '2026-01-31 18:30:00'],
				'time' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$', 'example' => '09:30'],
				'boolean' => ['type' => 'string', 'enum' => ['yes', 'no']],
				'icon' => ['type' => 'string', 'pattern' => '^[a-z0-9_]+$', 'description' => 'material symbol name'],
				'color' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'],
				'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => trim($f['pattern'], '/')]] + (($f['suggestions']) ? ['examples' => [$f['suggestions']]] : []),
				'multiselect' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_map('strval', array_keys($f['options']))]],
				'file' => ['type' => 'string', 'description' => 'upload:<token> of POST upload/'.$name.'/, the current path to keep the file, empty to remove it'],
				default => ['type' => 'string'],
			};

			$property['title'] = ts($f['label']);
			if(isset($f['help']))$property['description'] = ts($f['help']);
			if(isset($f['max_length']))$property['maxLength'] = $f['max_length'];
			if(($f['type'] === 'select' && empty($f['free'])) || $f['type'] === 'radio')$property['enum'] = array_map('strval', array_keys($f['options']));
			if($f['type'] === 'select' && !empty($f['free']))$property['examples'] = array_map('strval', array_keys($f['options']));
			if($f['type'] === 'html')$property['contentMediaType'] = 'text/html';

			$properties[$name] = $property;
			if($with_required && $f['required'] && $f['type'] !== 'password')$required[] = $name;

		}

		return ['type' => 'object', 'properties' => (object)$properties] + (($required) ? ['required' => $required] : []);

	}

	/**
	 * default values of a new record
	 *
	 * @return array
	 */
	public function defaults():array
	{

		$values = [];
		foreach($this->fields as $name => $field)
			$values[$name] = $field['default'] ?? '';

		return $values;

	}

	/**
	 * automatic rules from the declaration, messages of h2lsoft/validator (translated, [FIELD] = label)
	 *
	 * @param \Core\Validator $v
	 * @param string $table
	 * @param int $id 0 on create
	 *
	 * @return void
	 */
	public function rules(\Core\Validator $v, string $table, int $id):void
	{

		foreach($this->fields as $name => $f)
		{

			if(!empty($f['readonly']))continue;
			if(isset($f['show_if']) && !in_array((string)$v->inputGet($f['show_if']['field']), $f['show_if']['values'], true))continue;

			$v->input($name, ts($f['label']));

			// password kept when empty on update
			if($f['required'] && !($f['type'] === 'password' && $id))$v->required();
			if(isset($f['max_length']))$v->maxLength($f['max_length']);

			match($f['type'])
			{
				'email' => $v->email(),
				'number' => $v->float(false),
				'integer' => $v->integer(false),
				'date' => $v->date('Y-m-d'),
				'datetime' => $v->datetime('Y-m-d H:i:s'),
				'time' => $v->regex('/^([01]\d|2[0-3]):[0-5]\d$/', '`[FIELD]` must be a time hh:mm'),
				'boolean' => $v->in(['yes', 'no']),
				'icon' => $v->regex('/^[a-z0-9_]+$/', '`[FIELD]` must be an icon name, ex: settings'),
				'color' => $v->regex('/^#[0-9a-f]{6}$/i', '`[FIELD]` must be a color #rrggbb'),
				'select' => (empty($f['free'])) ? $v->in(array_map('strval', array_keys($f['options']))) : null,
				'radio' => $v->in(array_map('strval', array_keys($f['options']))),
				'tags' => $v->custom(fn($value) => is_array($value) && count($value) <= 100 && !array_filter($value, fn($w) => !is_string($w) || !preg_match($f['pattern'], trim($w))), '`[FIELD]` holds an invalid word'),
				'multiselect' => $v->custom(fn($value) => is_array($value) && !array_diff(array_map('strval', $value), array_map('strval', array_keys($f['options']))), '`[FIELD]` holds an invalid value'),
				'file' => $v->custom(fn($value) => !str_starts_with((string)$value, 'upload:') || \Core\File::uploadGet((string)$value) !== null, '`[FIELD]`: the uploaded file expired, upload it again'),
				default => null,
			};

			if(!empty($f['unique']))$v->unique($table, $name, $id);

		}

	}

	/**
	 * columns to write from the input: declared fields only, values trimmed, empty numbers and dates = null
	 *
	 * @param array $input request data
	 * @param bool $is_update a password left empty is not written
	 *
	 * @return array
	 */
	public function hydrate(array $input, bool $is_update):array
	{

		$row = [];
		foreach($this->fields as $name => $f)
		{

			if(!empty($f['readonly']) || !empty($f['manager']))continue;

			$value = $input[$name] ?? (($f['type'] === 'boolean') ? 'no' : '');

			// json array of the chosen values
			if($f['type'] === 'multiselect')
			{

				$row[$name] = json_encode(array_values(array_unique(array_map('strval', (is_array($value)) ? $value : []))));
				continue;

			}

			// json array of unique trimmed words
			if($f['type'] === 'tags')
			{

				$words = array_values(array_unique(array_filter(array_map(fn($w) => trim((string)$w), (is_array($value)) ? $value : []), 'strlen')));
				$row[$name] = json_encode($words);
				continue;

			}

			if(is_array($value))$value = '';
			$value = ($f['type'] === 'password') ? (string)$value : trim((string)$value);

			if($f['type'] === 'password' && $value === '' && $is_update)continue;
			// app rules: emails and logins in lower case, first and last names with an uppercase initial
			if($f['type'] === 'email' || $name === 'login')$value = mb_strtolower($value);
			if(in_array($name, self::UCFIRST_NAMES, true))$value = preg_replace_callback('/^(\p{Ll})/u', fn($m) => mb_strtoupper($m[1]), $value);
			if(in_array($f['type'], ['number', 'integer', 'date', 'datetime'], true) && $value === '')$value = null;
			// no record chosen: 0 in the foreign key (column xxx_id unsigned default 0)
			if($f['type'] === 'select' && $value === '' && str_ends_with($name, '_id'))$value = 0;
			if($f['type'] === 'html' && $value !== '')$value = \Core\Sanitizer::clean($value, $f['sanitize']);

			$row[$name] = $value;

		}

		return $row;

	}

}
