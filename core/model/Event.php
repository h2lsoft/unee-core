<?php

namespace Model;

/**
 * event names, registered automatically by \Core\EventManager::emit() in debug mode
 */
class Event extends \Core\Entity
{

	public static string $table = 'xcore_event';

}
