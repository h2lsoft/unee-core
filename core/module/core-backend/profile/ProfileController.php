<?php

namespace Plugin\Core_Backend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * account of the logged user (user menu: my profile): same fields and rules as the module user, without group, activation and note
 */
#[Route('/api/profile', name: 'api.profile.')]
class ProfileController extends \Core\Controller
{

	/**
	 * form of the profile with the values of the logged user (password never sent)
	 *
	 * @return \Core\JsonResponse {"form": {"fields", "tabs"}, "record"}
	 */
	#[Route('/', name: 'read', methods: ['GET'])]
	public function read():\Core\JsonResponse
	{

		$record = \Model\User::findById(\Model\User::getUID());
		if(!$record)return \Core\JsonResponse::error(404, 'record not found');

		$form = $this->buildForm($record);
		$values = [];
		foreach($form->fields as $name => $field)
			$values[$name] = ($field['type'] === 'password') ? '' : ($record[$name] ?? '');

		return new \Core\JsonResponse(['form' => $form->definition(), 'record' => $values]);

	}

	/**
	 * update the profile, json body: the fields of the form (password empty = unchanged)
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"id"}, 422 {"fields"}
	 */
	#[Route('/', name: 'update', methods: ['PUT'])]
	public function update(Request $request):\Core\JsonResponse
	{

		$id = \Model\User::getUID();
		$record = \Model\User::findById($id);
		if(!$record)return \Core\JsonResponse::error(404, 'record not found');

		$form = $this->buildForm($record);
		$v = $this->validator;
		$form->rules($v, 'xcore_user', $id);
		\Plugin\Core\UserController::credentialRules($v, $record);
		if($v->fails())return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $v->result()['error_stack_deep']]);

		$row = $form->hydrate($request->request->all(), true);
		if(isset($row['login']))$row['login'] = mb_strtolower($row['login']);
		if(isset($row['password']))
		{

			$cfg = \Core\Config::get('auth/security/password');
			$row['password'] = password_hash($row['password'], $cfg['algo'], $cfg['algo_options']);

		}

		$changed = [];
		foreach($row as $key => $value)
			if((string)($record[$key] ?? '') !== (string)$value)$changed[$key] = ($key === 'password') ? '********' : [$record[$key] ?? null, $value];

		if($changed)
		{

			DB('xcore_user')->update($row, $id, 1, (string)\Core\Session::get('auth.login'));
			\Plugin\Core\UserController::syncSession($row);
			\Core\Log::write('edit', 'profile', $changed, $id, 'info', 'profile');

		}

		return new \Core\JsonResponse(['id' => $id]);

	}

	private function buildForm(array $record):\Component\Form
	{

		$form = new \Component\Form();
		\Plugin\Core\UserController::accountFields($form, $record, false);

		return $form;

	}

}
