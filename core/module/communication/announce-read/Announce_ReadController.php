<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * reads of the announcements (xcore_announce_read, \Core\Announce::read()), hidden plugin, read only, csv export (right export)
 * opened from the reads of an announcement: announce-read/?search[]=xcore_announce_id||<id>
 * kept apart from the logs: a purge of the logs keeps this history
 */
#[Route('/api/announce-read', name: 'api.announce_read.')]
class Announce_ReadController extends \Core\CrudController
{

	public string $table = 'xcore_announce_read';
	public string $title = 'Announce reads';
	public string $object_label = 'read';

	private const array APPLICATIONS = ['backend' => 'Backend', 'frontend' => 'Frontend'];

	protected function grid(DataGrid $grid):void
	{

		$user = fn(string $column):string => "(SELECT u.{$column} FROM xcore_user u WHERE u.id = xcore_announce_read.xcore_user_id)";
		$groups = DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' ORDER BY priority, name")->fetchAll(\PDO::FETCH_KEY_PAIR);
		$announces = DB()->query("SELECT id, title FROM xcore_announce WHERE deleted = 'no' ORDER BY start_at DESC, id DESC LIMIT 200")->fetchAll(\PDO::FETCH_KEY_PAIR);

		$grid->qSelect("(SELECT a.title FROM xcore_announce a WHERE a.id = xcore_announce_read.xcore_announce_id)", 'announce');
		foreach(['firstname', 'lastname', 'login', 'email', 'avatar', 'xcore_group_id'] as $column)
			$grid->qSelect($user($column), $column);
		$grid->qSelect("(SELECT g.name FROM xcore_user u INNER JOIN xcore_group g ON g.id = u.xcore_group_id WHERE u.id = xcore_announce_read.xcore_user_id)", 'group_name');

		$grid->setSearch(['ip', 'user_agent'], 'Search ip, device...');
		$grid->addFilter('xcore_announce_id', 'Announcement', 'select', $announces);
		$grid->addFilter('user', 'User', 'text', [], "(SELECT CONCAT(u.firstname, ' ', u.lastname, ' ', u.login, ' ', u.email) FROM xcore_user u WHERE u.id = xcore_announce_read.xcore_user_id)");
		$grid->addFilter('xcore_group_id', 'Group', 'select', $groups, $user('xcore_group_id'));
		$grid->addFilter('read_at', 'Read at', 'date', [], 'DATE(read_at)');
		$grid->addFilter('application', 'Application', 'select', self::APPLICATIONS);
		$grid->addFilter('ip', 'IP');
		$grid->addFilter('user_agent', 'User agent');
		$grid->setOrderByFirst('read_at', 'desc');
		$grid->setMobile('user', 'read_at');
		$grid->setBatchMode(false);
		$grid->duplicate(false);
		$grid->allowExport();

		$grid->hookData(function(array $row):array{

			$row['user'] = trim("{$row['firstname']} {$row['lastname']}") ?: (string)$row['login'];
			$row['device'] = $this->device((string)$row['user_agent']);
			return $row;

		});

		$grid->addCol('user', 'User')->avatar()->subText('email');
		$grid->addCol('announce', 'Announcement')->filterOnClick('xcore_announce_id');
		$grid->addCol('group_name', 'Group')->filterOnClick('xcore_group_id')->width(140);
		$grid->addColDatetime('read_at', 'Read at', true)->width(150);
		$grid->addCol('ip', 'IP', true)->small()->width(120);
		$grid->addCol('device', 'Device')->small()->width(150);

	}

	// read only: no form (no add or edit right declared on the plugin)
	protected function form(Form $form, array $record):void
	{
	}

	/**
	 * browser and system of a user agent, ex: Chrome / Windows
	 *
	 * @param string $ua
	 *
	 * @return string
	 */
	private function device(string $ua):string
	{

		if($ua === '')return '';

		$browser = match(true)
		{

			str_contains($ua, 'Edg/') => 'Edge',
			str_contains($ua, 'OPR/') => 'Opera',
			str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
			str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
			str_contains($ua, 'Safari/') => 'Safari',
			default => '',

		};

		// android and ios before linux and macos: their agents hold these words too
		$os = match(true)
		{

			str_contains($ua, 'Windows') => 'Windows',
			str_contains($ua, 'Android') => 'Android',
			(bool)preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
			str_contains($ua, 'Mac OS X') => 'macOS',
			str_contains($ua, 'Linux') => 'Linux',
			default => '',

		};

		return ($browser !== '' && $os !== '') ? "{$browser} / {$os}" : (($browser.$os !== '') ? $browser.$os : mb_strimwidth($ua, 0, 40, '...'));

	}

}
