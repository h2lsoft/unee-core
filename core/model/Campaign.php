<?php

namespace Model;

/**
 * marketing campaigns (xcore_campaign), their expenses and revenues in xcore_campaign_entry (amounts incl. tax)
 */
class Campaign extends \Core\Entity
{

	public static string $table = 'xcore_campaign';

	public const array TYPES = ['expense' => 'expense', 'revenue' => 'revenue'];
	public const array TYPE_COLORS = ['expense' => 'red', 'revenue' => 'green'];

	/**
	 * adds an expense or a revenue to a campaign, from the code of another module (ex: a donation validated)
	 * an entry with a record of origin is added once by type: the call returns the id of the existing entry
	 *
	 * @param string $campaign name of the campaign
	 * @param string $type expense or revenue
	 * @param float $amount incl. tax
	 * @param string $label
	 * @param string $record_type origin, ex: donation
	 * @param string $record_id id of the record of origin
	 * @param string $note
	 * @param string $date Y-m-d, empty = today
	 * @param string $by author
	 *
	 * @return int id of the entry
	 */
	public static function addEntry(string $campaign, string $type, float $amount, string $label, string $record_type='', string $record_id='', string $note='', string $date='', string $by='system'):int
	{

		$campaign_id = (int)DB()->query("SELECT id FROM xcore_campaign WHERE name = :name AND deleted = 'no' LIMIT 1", ['name' => $campaign])->fetchOne();
		if(!$campaign_id)throw new \InvalidArgumentException("campaign `{$campaign}` not found");
		if(!isset(self::TYPES[$type]))throw new \InvalidArgumentException("entry type `{$type}` unknown");
		if(($record_type === '') !== ($record_id === ''))throw new \InvalidArgumentException('record_type and record_id go together');
		if($date === '')$date = now('', false);

		if($record_type !== '' && $id = self::entryOf($type, $record_type, $record_id))return $id;

		$row = ['xcore_campaign_id' => $campaign_id, 'type' => $type, 'date' => $date, 'label' => mb_substr($label, 0, 255), 'amount' => round($amount, 2), 'record_type' => $record_type, 'record_id' => $record_id, 'note' => $note];
		return (int)DB('xcore_campaign_entry')->insert($row, $by);

	}

	/**
	 * id of the entry of a type made from a record, 0 = none
	 *
	 * @param int $exclude_id entry being updated
	 */
	public static function entryOf(string $type, string $record_type, string $record_id, int $exclude_id=0):int
	{

		return (int)DB()->query("SELECT id FROM xcore_campaign_entry WHERE deleted = 'no' AND type = :type AND record_type = :record_type AND record_id = :record_id AND id != :id LIMIT 1", ['type' => $type, 'record_type' => $record_type, 'record_id' => $record_id, 'id' => $exclude_id])->fetchOne();

	}

	/**
	 * amount with its currency symbol for the locale of the request: 1,234.50 € (en), 1 234,50 € (others)
	 * shortcut: separators en / others only, use intl NumberFormatter when a locale needs its own
	 */
	public static function money(float $amount, string $currency):string
	{

		$symbol = \Plugin\Core_Backend\ProjectController::CURRENCIES[$currency] ?? $currency;
		$en = ((App()?->locale ?? 'en') === 'en');
		return number_formatX($amount, 2, $en ? '.' : ',', $en ? ',' : "\u{202F}")." {$symbol}";

	}

}
