<?php

namespace Job\Core;

/**
 * job workflow_effect: an effect of a workflow transition run in the background (\Core\Workflow::runEffect)
 * payload {"module", "record_id", "transition", "from", "to", "comment", "user_id", "history_id", "effect"}, an exception retries the job
 */
class Workflow_Effect extends \Core\JobHandler
{

	public function handle(array $payload):?array
	{

		return \Core\Workflow::runEffect($payload);

	}

}
