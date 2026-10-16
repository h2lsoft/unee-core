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

	public array $fields = [];
	private string $last = '';

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

		$this->fields[$name] = ['name' => $name, 'type' => $type, 'label' => ($label === '') ? $name : $label, 'required' => $required, 'width' => 12] + $options;
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

			unset($field['sanitize'], $field['translate'], $field['unique']);
			$fields[] = $field;

		}

		return ['fields' => $fields];

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
	 * automatic rules from the declaration (short messages, shown under the fields)
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

			$v->input($name, ts($f['label']));

			// password kept when empty on update
			if($f['required'] && !($f['type'] === 'password' && $id))$v->required('required');
			if(isset($f['max_length']))$v->maxLength($f['max_length'], '[LENGTH] characters maximum');

			match($f['type'])
			{
				'email' => $v->email('invalid email address'),
				'number' => $v->float(false, 'invalid number'),
				'integer' => $v->integer(false, 'invalid number'),
				'date' => $v->date('Y-m-d', 'invalid date'),
				'datetime' => $v->datetime('Y-m-d H:i:s', 'invalid date'),
				'boolean' => $v->in(['yes', 'no'], 'invalid value'),
				'select' => $v->in(array_map('strval', array_keys($f['options'])), 'invalid value'),
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

			if(!empty($f['readonly']))continue;

			$value = $input[$name] ?? (($f['type'] === 'boolean') ? 'no' : '');
			if(is_array($value))$value = '';
			$value = ($f['type'] === 'password') ? (string)$value : trim((string)$value);

			if($f['type'] === 'password' && $value === '' && $is_update)continue;
			if(in_array($f['type'], ['number', 'integer', 'date', 'datetime'], true) && $value === '')$value = null;
			if($f['type'] === 'html' && $value !== '')$value = \Core\Sanitizer::clean($value, $f['sanitize']);

			$row[$name] = $value;

		}

		return $row;

	}

}
