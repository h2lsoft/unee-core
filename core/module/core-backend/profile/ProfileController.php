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

		// file fields (avatar): uploaded by the route upload of the profile
		$definition = $form->definition();
		foreach($definition['fields'] as &$field)
		{

			if($field['type'] !== 'file')continue;
			$field['upload_url'] = "profile/upload/{$field['name']}/";
			if(!empty($field['public']))$field['public_url'] = \Core\File::url();

		}
		unset($field);

		return new \Core\JsonResponse(['form' => $definition, 'record' => $values]);

	}

	/**
	 * upload of a file field of the profile (avatar), kept until the profile is saved with the token as value
	 *
	 * @param Request $request multipart, field file
	 * @param string $field name of the file field
	 *
	 * @return \Core\JsonResponse {"token": "upload:<token>", "name", "size"}
	 */
	#[Route('/upload/{field}/', name: 'upload', methods: ['POST'], requirements: ['field' => '[a-z0-9_]+'])]
	public function upload(Request $request, string $field):\Core\JsonResponse
	{

		$record = \Model\User::findById(\Model\User::getUID());
		if(!$record)return \Core\JsonResponse::error(404, 'record not found');

		$f = $this->buildForm($record)->fields[$field] ?? null;
		if(($f['type'] ?? '') !== 'file' || !empty($f['readonly']))return \Core\JsonResponse::error(404, 'field not found');

		$result = \Core\File::uploadStore($request->files->get('file'), $f['extensions'], (int)$f['max_size'], (array)($f['convert'] ?? []));
		if(is_string($result))return \Core\JsonResponse::error(422, $result);

		return new \Core\JsonResponse($result, 201);

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
		$removed = \Core\CrudController::fileFieldsMove($form, $row, $record);
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

		// replaced or removed files
		foreach($removed as [$relative, $public])
			\Core\File::remove($relative, $public);

		return new \Core\JsonResponse(['id' => $id]);

	}

	private function buildForm(array $record):\Component\Form
	{

		$form = new \Component\Form();
		\Plugin\Core\UserController::accountFields($form, $record, false);

		return $form;

	}

}
