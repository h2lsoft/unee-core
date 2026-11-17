<?php

namespace Plugin\Core_Backend;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Core\Drive;
use Core\JsonResponse;

/**
 * Drive (menu Tools): folders and files of the instance, api of the File Manager (DrivePage.tsx) and of its picker mode
 * rights: \Core\Drive::rights() (plugin rights narrowed by the groups of the folders), files in private_dir/drive (\Core\Drive)
 * an item of a request is ['type' => 'folder'|'file', 'id' => int]
 */
#[Route('/api/drive', name: 'api.drive.')]
class DriveController extends \Core\Controller
{

	// sorts of a folder: key => [sql of the folders, sql of the files]
	private const array SORTS = [
		'name' => ['f.name', 'f.name'],
		'size' => ['children', 'f.size'],
		'date' => ['COALESCE(f.updated_at, f.created_at)', 'COALESCE(f.updated_at, f.created_at)'],
		'by' => ['COALESCE(f.updated_by, f.created_by)', 'COALESCE(f.updated_by, f.created_by)'],
	];

	// an upload not completed is removed after one day
	private const int UPLOAD_TTL = 86400;

	// ================================================================================================================
	// drive, folders
	// ================================================================================================================

	/**
	 * start of the File Manager: rights at the root, storage used, pinned folders of the user, upload settings
	 *
	 * @return JsonResponse {"rights", "usage", "pins": [{"id", "name", "path"}], "upload": {"chunk_size", "max_size"}, "filters": {"image": ["jpg", ...], ...}, "avif" (the image editor can write avif)}
	 */
	#[Route('/', name: 'index', methods: ['GET'])]
	public function index():JsonResponse
	{

		if(!Drive::can(0, 'read'))return JsonResponse::error(403, 'access denied');

		return new JsonResponse([
			'rights' => Drive::rights(0),
			'usage' => \Core\Storage::usage(),
			'pins' => $this->pins(),
			'upload' => ['chunk_size' => $this->chunkSize(), 'max_size' => Drive::settings(0)['max_size']],
			'filters' => (object)Drive::filters(),
			'avif' => Drive::avifWrite(),
		]);

	}

	/**
	 * content of a folder: its folders (all) and its files (by page), sorted, folders first
	 *
	 * @param Request $request query string: sort (name, size, date, by), dir (asc, desc), q (name with the wildcards * and ?, #tag), type (key of the global drive.filters), page, per_page (200, 1000 at most)
	 * @param int $id 0 = root
	 *
	 * @return JsonResponse {"folder": {"id", "name", "path", "public", "pinned"}, "breadcrumb": [{"id", "name"}], "rights", "settings", "folders": [...], "files": [...], "total": {"folders", "files"}, "page", "per_page"}
	 */
	#[Route('/folders/{id}/', name: 'folder', methods: ['GET'])]
	public function folder(Request $request, int $id):JsonResponse
	{

		$chain = Drive::chain($id);
		if($chain === null)return JsonResponse::error(404, 'folder not found');
		if(!Drive::can($id, 'read'))return JsonResponse::error(403, 'access denied');

		$sort = (string)$request->query->get('sort', 'name');
		if(!isset(self::SORTS[$sort]))$sort = 'name';
		$dir = (strtolower((string)$request->query->get('dir', 'asc')) === 'desc') ? 'DESC' : 'ASC';
		$query = Drive::parseQuery((string)$request->query->get('q', ''));
		$type = $this->typeExtensions((string)$request->query->get('type', ''));
		$per_page = min(1000, max(1, (int)$request->query->get('per_page', 200)));
		$page = max(1, (int)$request->query->get('page', 1));

		// name (wildcards * and ?), tags and type: tags and type narrow the files, the folders follow the name only
		$binds = ['id' => $id];
		$filter = '';
		if($query['name'] !== null)
		{

			$filter = " AND f.name LIKE :q";
			$binds['q'] = $query['name'];

		}

		$file_binds = $binds;
		$file_filter = $filter.Drive::tagsSql($query['tags'], $file_binds).$this->typeSql($type, $file_binds);

		$folders = ($query['tags']) ? [] : DB()->query("SELECT f.*,
									(SELECT COUNT(*) FROM xcore_drive_folder c WHERE c.xcore_drive_folder_id = f.id AND c.deleted = 'no') + (SELECT COUNT(*) FROM xcore_drive_file c WHERE c.xcore_drive_folder_id = f.id AND c.deleted = 'no') AS children
								FROM xcore_drive_folder f
								WHERE f.xcore_drive_folder_id = :id AND f.deleted = 'no'{$filter}
								ORDER BY ".self::SORTS[$sort][0]." {$dir}, f.name ASC, f.id ASC", $binds)->fetchAll();

		$total_files = (int)DB()->query("SELECT COUNT(*) FROM xcore_drive_file f WHERE f.xcore_drive_folder_id = :id AND f.deleted = 'no'{$file_filter}", $file_binds)->fetchOne();
		$files = DB()->query("SELECT f.* FROM xcore_drive_file f
							  WHERE f.xcore_drive_folder_id = :id AND f.deleted = 'no'{$file_filter}
							  ORDER BY ".self::SORTS[$sort][1]." {$dir}, f.name ASC, f.id ASC
							  LIMIT ".(($page - 1) * $per_page).", {$per_page}", $file_binds)->fetchAll();

		$users = $this->users(array_merge($folders, $files));
		$favorites = $this->favorites(array_column($files, 'id'));
		$tags = $this->tags(array_column($files, 'id'));
		$settings = Drive::settings($id);
		$pinned = ($id > 0) && (bool)DB()->query("SELECT id FROM xcore_drive_pin WHERE xcore_user_id = :uid AND xcore_drive_folder_id = :id AND deleted = 'no'", ['uid' => \Model\User::getUID(), 'id' => $id])->fetchOne();

		return new JsonResponse([
			'folder' => [
				'id' => $id,
				'name' => ($id > 0) ? (string)end($chain)['name'] : '',
				'path' => Drive::folderPath($id),
				'public' => $settings['public'],
				'pinned' => $pinned,
			],
			'breadcrumb' => array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name']], $chain),
			'rights' => Drive::rights($id),
			'settings' => ['extensions' => $settings['extensions'], 'max_size' => $settings['max_size'], 'links' => $settings['links'], 'versions' => $settings['versions']],
			'folders' => array_map(fn($f) => $this->folderOut($f, $users), $folders),
			'files' => array_map(fn($f) => $this->fileOut($f, $users, $favorites, $tags), $files),
			'total' => ['folders' => count($folders), 'files' => $total_files],
			'page' => $page,
			'per_page' => $per_page,
		]);

	}

	/**
	 * new folder (write right in the parent)
	 *
	 * @param Request $request {"parent", "name"}
	 *
	 * @return JsonResponse 201 {"folder"}
	 */
	#[Route('/folders/', name: 'folder.create', methods: ['POST'])]
	public function folderCreate(Request $request):JsonResponse
	{

		$parent = (int)$request->request->get('parent', 0);
		if(Drive::chain($parent) === null)return JsonResponse::error(404, 'folder not found');
		if(!Drive::can($parent, 'write'))return JsonResponse::error(403, 'access denied');

		$name = Drive::cleanName((string)$request->request->get('name', ''));
		if($name === '')return JsonResponse::error(422, ts('`[FIELD]` is required', ['FIELD' => ts('Name')]), ['fields' => ['name' => [ts('`[FIELD]` is required', ['FIELD' => ts('Name')])]]]);
		if(Drive::nameTaken($parent, $name))return JsonResponse::error(422, ts('this name is already used in the folder'), ['fields' => ['name' => [ts('this name is already used in the folder')]]]);

		$id = Drive::createFolder($parent, $name);
		\Core\Log::write('create', 'folder', ['name' => $name, 'path' => Drive::folderPath($id)], $id, 'info', $this->module);

		$folder = DB()->query("SELECT f.*, 0 AS children FROM xcore_drive_folder f WHERE f.id = :id", ['id' => $id])->fetch();
		return new JsonResponse(['folder' => $this->folderOut($folder, $this->users([$folder]))], 201);

	}

	/**
	 * renames a folder (write right in its parent)
	 *
	 * @param Request $request {"name"}
	 * @param int $id
	 *
	 * @return JsonResponse {"name"}
	 */
	#[Route('/folders/{id}/', name: 'folder.rename', methods: ['PATCH'])]
	public function folderRename(Request $request, int $id):JsonResponse
	{

		if(!$folder = Drive::folder($id))return JsonResponse::error(404, 'folder not found');
		if(!Drive::can((int)$folder['xcore_drive_folder_id'], 'write'))return JsonResponse::error(403, 'access denied');

		$name = Drive::cleanName((string)$request->request->get('name', ''));
		if($name === '')return JsonResponse::error(422, ts('`[FIELD]` is required', ['FIELD' => ts('Name')]), ['fields' => ['name' => [ts('`[FIELD]` is required', ['FIELD' => ts('Name')])]]]);
		if($name === $folder['name'])return new JsonResponse(['name' => $name]);
		if(Drive::nameTaken((int)$folder['xcore_drive_folder_id'], $name, 'folder', $id))return JsonResponse::error(422, ts('this name is already used in the folder'), ['fields' => ['name' => [ts('this name is already used in the folder')]]]);

		DB('xcore_drive_folder')->update(['name' => $name], $id, -1, $this->author());
		\Core\Log::write('rename', 'folder', ['name' => [$folder['name'], $name]], $id, 'info', $this->module);

		return new JsonResponse(['name' => $name]);

	}

	/**
	 * form of the settings of a folder (manage right), shown by FormDialog: groups by level, file types, max size, public
	 * every setting left empty is inherited from the parents, it can only narrow them
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"form", "record"}
	 */
	#[Route('/folders/{id}/settings/', name: 'folder.settings', methods: ['GET'])]
	public function folderSettings(int $id):JsonResponse
	{

		if(!$folder = Drive::folder($id))return JsonResponse::error(404, 'folder not found');
		if(!Drive::can($id, 'manage'))return JsonResponse::error(403, 'access denied');

		$s = $folder['settings'];
		return new JsonResponse([
			'form' => $this->settingsForm($id)->definition(),
			'record' => [
				'read' => array_map('strval', $s['read'] ?? []),
				'write' => array_map('strval', $s['write'] ?? []),
				'manage' => array_map('strval', $s['manage'] ?? []),
				'extensions' => $s['extensions'] ?? [],
				'max_size' => isset($s['max_size']) ? (string)round($s['max_size'] / 1048576, 1) : '',
				'public' => $s['public'] ?? 'inherit',
				'links' => $s['links'] ?? 'inherit',
			],
		]);

	}

	/**
	 * saves the settings of a folder (manage right): groups, file types and size can only narrow the parents
	 *
	 * @param Request $request {"read", "write", "manage" (group ids), "extensions", "max_size" (MB), "public" and "links" (inherit, yes, no)}
	 * @param int $id
	 *
	 * @return JsonResponse {"settings"}
	 */
	#[Route('/folders/{id}/settings/', name: 'folder.settings.update', methods: ['PUT'])]
	public function folderSettingsUpdate(Request $request, int $id):JsonResponse
	{

		if(!$folder = Drive::folder($id))return JsonResponse::error(404, 'folder not found');
		if(!Drive::can($id, 'manage'))return JsonResponse::error(403, 'access denied');

		// versions and keep are not in the form yet: kept as they are
		$input = $request->request->all();
		$mb = (float)str_replace(',', '.', (string)($input['max_size'] ?? ''));
		$input['max_size'] = ($mb > 0) ? (int)round($mb * 1048576) : 0;
		$input['public'] = in_array($input['public'] ?? '', ['yes', 'no'], true) ? $input['public'] : '';
		$input['links'] = in_array($input['links'] ?? '', ['yes', 'no'], true) ? $input['links'] : '';
		foreach(['versions', 'keep'] as $key)$input[$key] = $folder['settings'][$key] ?? '';

		$errors = [];
		$settings = Drive::checkSettings($input, $id, $errors);
		if($errors)return JsonResponse::error(422, ts('validation failed'), ['fields' => array_map(fn($m) => [$m], $errors)]);

		// the user keeps the right to manage the folder he changes (superadmin excepted)
		if(!\Model\User::isSuperAdmin())
		{

			$group = (int)\Core\Session::get('auth.group_id', 0);
			foreach(Drive::LEVELS as $level)
			{

				if(isset($settings[$level]) && !in_array($group, $settings[$level], true))
					return JsonResponse::error(422, ts('your group must keep this level'), ['fields' => [$level => [ts('your group must keep this level')]]]);

			}

		}

		DB('xcore_drive_folder')->update(['settings' => ($settings) ? json_encode($settings) : null], $id, -1, $this->author());
		\Core\Log::write('settings', 'folder', ['settings' => [$folder['settings'], $settings]], $id, 'info', $this->module);
		Drive::reset();

		return new JsonResponse(['settings' => (object)$settings]);

	}

	/**
	 * form of the settings of a folder, the inherited values in the help texts
	 *
	 * @param int $id
	 *
	 * @return \Component\Form
	 */
	private function settingsForm(int $id):\Component\Form
	{

		$inherited = Drive::settings($id, true);
		$groups = [];
		foreach(DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' AND id > 2 ORDER BY priority, name")->fetchAll() as $rec)
			$groups[(string)$rec['id']] = $rec['name'];

		$form = new \Component\Form();
		$form->addSelectMultiple('read', 'Read access', $groups)->set('translate', false)->help('Groups that see and download, empty = inherited');
		$form->addSelectMultiple('write', 'Write access', $groups)->set('translate', false)->help('Groups that add, rename, move and trash, among the ones that read, empty = inherited');
		$form->addSelectMultiple('manage', 'Manage access', $groups)->set('translate', false)->help('Groups that change these settings, among the ones that write, empty = inherited');
		$form->addSelectMultiple('extensions', 'File types', array_combine($inherited['extensions'], $inherited['extensions']))->set('translate', false)->set('display', 'checkboxes')->set('columns', 3)->help(ts('Empty = inherited ([N] types allowed by the parent folders)', ['N' => count($inherited['extensions'])]));
		$form->addNumber('max_size', 'Max size (MB)')->set('size', 160)->set('align', 'center')->help(ts('Empty = inherited ([SIZE] MB)', ['SIZE' => round($inherited['max_size'] / 1048576, 1)]));
		$form->addRadio('public', 'Public folder', ['inherit' => ($inherited['public']) ? 'Inherited (yes)' : 'Inherited (no)', 'yes' => 'Yes', 'no' => 'No'])->help('Public: anyone with the link of a file opens it without logon, in this folder and its subfolders');
		$form->addRadio('links', 'Share links', ['inherit' => ($inherited['links']) ? 'Inherited (yes)' : 'Inherited (no)', 'yes' => 'Allowed', 'no' => 'Refused'])->help('Links to share a file with people out of the backend (expiration, password, max downloads)');

		return $form;

	}

	/**
	 * pins or unpins a folder in the sidebar of the user (read right)
	 *
	 * @param Request $request {"folder", "pinned"}
	 *
	 * @return JsonResponse {"pins"}
	 */
	#[Route('/pins/', name: 'pin', methods: ['POST'])]
	public function pin(Request $request):JsonResponse
	{

		$id = (int)$request->request->get('folder', 0);
		if(!Drive::folder($id))return JsonResponse::error(404, 'folder not found');
		if(!Drive::can($id, 'read'))return JsonResponse::error(403, 'access denied');

		$uid = \Model\User::getUID();
		DB()->query("DELETE FROM xcore_drive_pin WHERE xcore_user_id = :uid AND xcore_drive_folder_id = :id", ['uid' => $uid, 'id' => $id]);

		if(filter_var($request->request->get('pinned', true), FILTER_VALIDATE_BOOLEAN))
		{

			$position = (int)DB()->query("SELECT COALESCE(MAX(position), 0) + 1 FROM xcore_drive_pin WHERE xcore_user_id = :uid", ['uid' => $uid])->fetchOne();
			DB('xcore_drive_pin')->insert(['xcore_user_id' => $uid, 'xcore_drive_folder_id' => $id, 'position' => $position], $this->author());

		}

		return new JsonResponse(['pins' => $this->pins()]);

	}

	/**
	 * folder (and file) of a value given to the picker: url of a file of the drive (absolute or public) or path of names
	 *
	 * @param Request $request query string: value (ex: /api/drive/files/12/download/, https://.../api/drive/files/12/download/, /Clients/Dupont SARL/devis.pdf), path (folder only)
	 *
	 * @return JsonResponse {"folder", "file"} (file 0 when the value is a folder)
	 */
	#[Route('/locate/', name: 'locate', methods: ['GET'])]
	public function locate(Request $request):JsonResponse
	{

		$value = trim((string)$request->query->get('value', $request->query->get('path', '')));
		if($value === '')return JsonResponse::error(404, 'not found');

		// url of a file
		if(preg_match('#/api/drive/files/(\d+)/#', $value, $m))
		{

			$file = Drive::file((int)$m[1]);
			if(!$file || !Drive::can((int)$file['xcore_drive_folder_id'], 'read'))return JsonResponse::error(404, 'not found');

			return new JsonResponse(['folder' => (int)$file['xcore_drive_folder_id'], 'file' => (int)$file['id']]);

		}

		// path of a folder, else path of a file
		$folder = Drive::folderByPath($value);
		if($folder !== null)
		{

			if(!Drive::can($folder, 'read'))return JsonResponse::error(404, 'not found');
			return new JsonResponse(['folder' => $folder, 'file' => 0]);

		}

		$parts = array_values(array_filter(explode('/', str_replace('\\', '/', $value)), fn($s) => trim($s) !== ''));
		$name = array_pop($parts);
		$folder = Drive::folderByPath(implode('/', $parts));
		if($folder === null || $name === null || !Drive::can($folder, 'read'))return JsonResponse::error(404, 'not found');

		$file = (int)DB()->query("SELECT id FROM xcore_drive_file WHERE xcore_drive_folder_id = :folder AND name = :name AND deleted = 'no' ORDER BY id LIMIT 1", ['folder' => $folder, 'name' => trim($name)])->fetchOne();
		if(!$file)return JsonResponse::error(404, 'not found');

		return new JsonResponse(['folder' => $folder, 'file' => $file]);

	}

	// ================================================================================================================
	// files
	// ================================================================================================================

	/**
	 * a file (read right): its row, its location, its urls
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"file", "path", "rights"}
	 */
	#[Route('/files/{id}/', name: 'file', methods: ['GET'])]
	public function file(int $id):JsonResponse
	{

		if(!$file = Drive::file($id))return JsonResponse::error(404, 'file not found');
		$folder_id = (int)$file['xcore_drive_folder_id'];
		if(!Drive::can($folder_id, 'read'))return JsonResponse::error(404, 'file not found');

		$out = $this->fileOut($file, $this->users([$file]), $this->favorites([$file['id']]), $this->tags([$file['id']]));
		$out['created_at'] = $file['created_at'];
		$out['created_by'] = $this->users([['created_by' => $file['created_by']]])[(string)$file['created_by']] ?? null;
		$out['folder'] = $folder_id;
		$out['path'] = rtrim(Drive::folderPath($folder_id), '/').'/'.$file['name'];
		$out['public'] = Drive::settings($folder_id)['public'];

		return new JsonResponse(['file' => $out, 'rights' => Drive::rights($folder_id)]);

	}

	/**
	 * renames a file (write right), its extension never changes
	 *
	 * @param Request $request {"name"}
	 * @param int $id
	 *
	 * @return JsonResponse {"name"}
	 */
	#[Route('/files/{id}/', name: 'file.rename', methods: ['PATCH'])]
	public function fileRename(Request $request, int $id):JsonResponse
	{

		if(!$file = Drive::file($id))return JsonResponse::error(404, 'file not found');
		$folder_id = (int)$file['xcore_drive_folder_id'];
		if(!Drive::can($folder_id, 'write'))return JsonResponse::error(403, 'access denied');

		$name = Drive::cleanName((string)$request->request->get('name', ''));
		if($name === '' || pathinfo($name, PATHINFO_FILENAME) === '')return JsonResponse::error(422, ts('`[FIELD]` is required', ['FIELD' => ts('Name')]), ['fields' => ['name' => [ts('`[FIELD]` is required', ['FIELD' => ts('Name')])]]]);
		if(strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower((string)$file['extension']))return JsonResponse::error(422, ts('the extension of a file cannot change (.[EXTENSION])', ['EXTENSION' => $file['extension']]), ['fields' => ['name' => [ts('the extension of a file cannot change (.[EXTENSION])', ['EXTENSION' => $file['extension']])]]]);
		if($name === $file['name'])return new JsonResponse(['name' => $name]);
		if(Drive::nameTaken($folder_id, $name, 'file', $id))return JsonResponse::error(422, ts('this name is already used in the folder'), ['fields' => ['name' => [ts('this name is already used in the folder')]]]);

		DB('xcore_drive_file')->update(['name' => $name], $id, -1, $this->author());
		\Core\Log::write('rename', 'file', ['name' => [$file['name'], $name]], $id, 'info', $this->module);

		return new JsonResponse(['name' => $name]);

	}

	/**
	 * content of a file (public route): read right, or a folder set public (no logon needed)
	 * images, pdf, audio and video shown inline (ranges for the big files), the others downloaded; ?download=1 always downloads
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return mixed
	 */
	#[Route('/files/{id}/download/', name: 'file.download', methods: ['GET'], defaults: ['_public' => true])]
	public function fileDownload(Request $request, int $id):mixed
	{

		// a file that cannot be seen answers 404: ids are never confirmed to a visitor
		$file = Drive::file($id);
		if(!$file || $file['status'] !== 'ready')return JsonResponse::error(404, 'file not found');

		$folder_id = (int)$file['xcore_drive_folder_id'];
		if(!Drive::can($folder_id, 'read') && !Drive::settings($folder_id)['public'])return JsonResponse::error(404, 'file not found');

		$path = \Core\File::privateDir().'/'.$file['disk'];
		if(!is_file($path))return JsonResponse::error(404, 'file not found');

		$mime = (string)$file['mime'];
		$inline = !$request->query->getBoolean('download') && ((str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') || in_array($mime, ['application/pdf', 'text/plain'], true) || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/'));

		$response = new BinaryFileResponse($path);
		$response->headers->set('Content-Type', ($mime === 'text/plain') ? 'text/plain; charset=utf-8' : ($mime ?: 'application/octet-stream'));
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		// sandboxed (no script, no form), except the pdf: the viewers of chrome (plugin, blocked by object-src) and firefox (pdf.js) need a document without csp
		if($mime !== 'application/pdf')$response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
		$response->setContentDisposition(($inline) ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string)$file['name'], 'file.'.$file['extension']);
		$response->setAutoEtag();
		$response->setPrivate();

		// ranges (video, resumed downloads)
		$response->prepare($request);
		return $response;

	}

	/**
	 * adds or removes a file from the favorites of the user (read right)
	 *
	 * @param Request $request {"file", "favorite"}
	 *
	 * @return JsonResponse {"favorite"}
	 */
	#[Route('/favorites/', name: 'favorite', methods: ['POST'])]
	public function favorite(Request $request):JsonResponse
	{

		$id = (int)$request->request->get('file', 0);
		if(!$file = Drive::file($id))return JsonResponse::error(404, 'file not found');
		if(!Drive::can((int)$file['xcore_drive_folder_id'], 'read'))return JsonResponse::error(404, 'file not found');

		$uid = \Model\User::getUID();
		$favorite = filter_var($request->request->get('favorite', true), FILTER_VALIDATE_BOOLEAN);

		DB()->query("DELETE FROM xcore_drive_favorite WHERE xcore_user_id = :uid AND xcore_drive_file_id = :id", ['uid' => $uid, 'id' => $id]);
		if($favorite)DB('xcore_drive_favorite')->insert(['xcore_user_id' => $uid, 'xcore_drive_file_id' => $id], $this->author());

		return new JsonResponse(['favorite' => $favorite]);

	}

	/**
	 * favorite files of the user that he can still read
	 *
	 * @return JsonResponse {"files"}
	 */
	#[Route('/favorites/', name: 'favorites', methods: ['GET'])]
	public function favoritesList():JsonResponse
	{

		if(!Drive::can(0, 'read') && !\Model\User::hasRight(Drive::PLUGIN, 'list'))return JsonResponse::error(403, 'access denied');

		$files = DB()->query("SELECT f.* FROM xcore_drive_favorite fav INNER JOIN xcore_drive_file f ON f.id = fav.xcore_drive_file_id AND f.deleted = 'no'
							  WHERE fav.xcore_user_id = :uid AND fav.deleted = 'no' ORDER BY f.name", ['uid' => \Model\User::getUID()])->fetchAll();

		return new JsonResponse(['files' => $this->filesReadable($files)]);

	}

	/**
	 * replaces the tags of a file (write right)
	 *
	 * @param Request $request {"tags": ["client", ...]}
	 * @param int $id
	 *
	 * @return JsonResponse {"tags"}
	 */
	#[Route('/files/{id}/tags/', name: 'file.tags', methods: ['PUT'])]
	public function fileTags(Request $request, int $id):JsonResponse
	{

		if(!$file = Drive::file($id))return JsonResponse::error(404, 'file not found');
		if(!Drive::can((int)$file['xcore_drive_folder_id'], 'write'))return JsonResponse::error(403, 'access denied');

		$old = \Core\Tag::get(Drive::TAGS, $id);
		$tags = array_map(fn($t) => mb_substr(trim((string)$t, " #"), 0, 64), (array)$request->request->all('tags'));
		\Core\Tag::set(Drive::TAGS, $id, $tags, $this->author());
		$new = \Core\Tag::get(Drive::TAGS, $id);

		if($old !== $new)\Core\Log::write('tags', 'file', ['tags' => [implode(', ', $old), implode(', ', $new)]], $id, 'info', $this->module);
		return new JsonResponse(['tags' => $new]);

	}

	/**
	 * new content of a file from the editors of the File Manager (write right): text of the text editor, image of the image editor
	 * replace = new content of the file (a new image format changes its extension), copy = new file "name (2).ext" in the same folder
	 *
	 * @param Request $request multipart: "content" (text) or "file" (image), "mode" (replace, copy), "extension" (format of an image), "quality" (avif, 0 to 100), "base" (hash read at the opening), "force"
	 * @param int $id
	 *
	 * @return JsonResponse {"file"}, 201 for a copy; 409 reason changed when the file was saved by someone else since "base"
	 */
	#[Route('/files/{id}/content/', name: 'file.content', methods: ['POST'])]
	public function fileContent(Request $request, int $id):JsonResponse
	{

		if(!$file = Drive::file($id))return JsonResponse::error(404, 'file not found');
		$folder_id = (int)$file['xcore_drive_folder_id'];
		if(!Drive::can($folder_id, 'write'))return JsonResponse::error(403, 'access denied');

		$copy = ($request->request->get('mode') === 'copy');
		$extension = strtolower((string)$file['extension']);
		$base = (string)$request->request->get('base', '');
		if(!$copy && $base !== '' && !hash_equals((string)$file['sha256'], $base) && !$request->request->getBoolean('force'))
			return JsonResponse::error(409, ts('this file was saved by someone else since you opened it'), ['reason' => 'changed']);

		$settings = Drive::settings($folder_id);
		$tmp = \Core\File::privateDir('tmp/drive').'/'.bin2hex(random_bytes(16));
		$fail = function(string $message) use ($tmp):JsonResponse
		{

			@unlink($tmp);
			return JsonResponse::error(422, $message);

		};

		// text: valid utf-8 without nul, json checked, type stored by extension (never detected: a text file is never served as a page)
		if(isset(Drive::TEXT_MIMES[$extension]) && $request->request->has('content'))
		{

			$content = (string)$request->request->get('content', '');
			if(strlen($content) > Drive::TEXT_MAX)return $fail(ts('the text is larger than [SIZE] MB', ['SIZE' => Drive::TEXT_MAX / 1048576]));
			if(!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0"))return $fail(ts('the text contains invalid characters'));
			if($extension === 'json' && trim($content) !== '' && !json_validate($content))return $fail(ts('invalid json: [ERROR]', ['ERROR' => json_last_error_msg()]));

			file_put_contents($tmp, $content);
			$target = $extension;
			$mime = Drive::TEXT_MIMES[$extension];

		}
		// image: written by the browser in jpg, png or webp, checked like an upload; avif: png of the browser converted by gd
		elseif(in_array($extension, Drive::IMAGE_EDITABLE, true) && ($upload = $request->files->get('file')) && $upload->isValid())
		{

			$target = strtolower((string)$request->request->get('extension', $extension));
			if(!in_array($target, Drive::IMAGE_OUTPUTS, true) || ($target === 'avif' && !Drive::avifWrite()))return $fail(ts('format not allowed'));

			$upload->move(dirname($tmp), basename($tmp));
			$mime = '';

			if($target === 'avif')
			{

				$image = (Drive::detectMime($tmp) === 'image/png') ? @imagecreatefrompng($tmp) : false;
				if(!$image)return $fail(ts('the content of the file does not match its extension'));

				imagesavealpha($image, true);
				$quality = max(0, min(100, (int)$request->request->get('quality', 80)));
				$written = imageavif($image, $tmp, $quality, 6);
				if(!$written)return $fail(ts('the image cannot be converted'));

			}

		}
		else return $fail(ts('this file cannot be edited'));

		$name = ($target === $extension) ? (string)$file['name'] : pathinfo((string)$file['name'], PATHINFO_FILENAME).'.'.$target;
		if($copy)$name = Drive::uniqueName($folder_id, $name);
		elseif($target !== $extension && Drive::nameTaken($folder_id, $name, 'file', $id))return $fail(ts('[NAME] already exists in this folder', ['NAME' => $name]));

		if($mime === '' && ($error = Drive::checkFile($tmp, $name, $folder_id)))return $fail($error);
		if($mime !== '' && !in_array($target, $settings['extensions'], true))return $fail(ts('file type not allowed: [EXTENSIONS]', ['EXTENSIONS' => implode(', ', $settings['extensions'])]));

		$size = (int)filesize($tmp);
		if($size > $settings['max_size'])return $fail(ts('the file is larger than [SIZE] MB', ['SIZE' => round($settings['max_size'] / 1048576, 1)]));
		$grow = $size - (($copy) ? 0 : (int)$file['size']);
		if($grow > 0 && !\Core\Storage::canStore($grow))
		{

			@unlink($tmp);
			return JsonResponse::error(422, ts('Storage full, contact your administrator'), ['reason' => 'storage_full']);

		}

		$row = Drive::storeFile($tmp, $name, $folder_id, ($copy) ? 0 : $id, $mime);
		\Core\Log::write(($copy) ? 'edit_copy' : 'edit', 'file', ['name' => $name, 'size' => $size, 'from' => (string)$file['name']], (int)$row['id'], 'info', $this->module);

		$out = $this->fileOut($row, $this->users([$row]), $this->favorites([(int)$row['id']]), $this->tags([(int)$row['id']]));
		return new JsonResponse(['file' => $out], ($copy) ? 201 : 200);

	}

	/**
	 * tags already used on the files (suggestions)
	 *
	 * @return JsonResponse {"tags"}
	 */
	#[Route('/tags/', name: 'tags', methods: ['GET'])]
	public function tagsList():JsonResponse
	{

		if(!\Model\User::hasRight(Drive::PLUGIN, 'list'))return JsonResponse::error(403, 'access denied');
		return new JsonResponse(['tags' => \Core\Tag::suggestions(Drive::TAGS)]);

	}

	// share links ****************************************************************************************************

	/**
	 * share links of a file (read right): the links of the user, all of them with the manage right of the folder
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"links", "allowed" (the folder allows the links and the user can create one)}
	 */
	#[Route('/files/{id}/links/', name: 'file.links', methods: ['GET'])]
	public function fileLinks(int $id):JsonResponse
	{

		if(!$file = Drive::file($id))return JsonResponse::error(404, 'file not found');
		$folder_id = (int)$file['xcore_drive_folder_id'];
		if(!Drive::can($folder_id, 'read'))return JsonResponse::error(404, 'file not found');

		$all = Drive::can($folder_id, 'manage');
		$links = DB()->query("SELECT * FROM xcore_drive_link WHERE xcore_drive_file_id = :file_id AND deleted = 'no'".(($all) ? '' : ' AND xcore_user_id = :uid')." ORDER BY id DESC",
							 ($all) ? ['file_id' => $id] : ['file_id' => $id, 'uid' => \Model\User::getUID()])->fetchAll();

		return new JsonResponse([
			'links' => $this->linksOut($links),
			'allowed' => Drive::can($folder_id, 'write') && Drive::settings($folder_id)['links'],
		]);

	}

	/**
	 * new share link of a file (write right, links allowed by the folder)
	 *
	 * @param Request $request {"expires_at" (Y-m-d, '' = never), "max_downloads" (0 = unlimited), "password" ('' = none), "notify" (bool), "note" (100 characters, for the creator only)}
	 * @param int $id
	 *
	 * @return JsonResponse 201 {"link"}
	 */
	#[Route('/files/{id}/links/', name: 'file.links.create', methods: ['POST'])]
	public function fileLinkCreate(Request $request, int $id):JsonResponse
	{

		if(!$file = Drive::file($id))return JsonResponse::error(404, 'file not found');
		$folder_id = (int)$file['xcore_drive_folder_id'];
		if(!Drive::can($folder_id, 'write'))return JsonResponse::error(403, 'access denied');
		if(!Drive::settings($folder_id)['links'])return JsonResponse::error(422, ts('this folder does not allow the share links'));

		$errors = [];
		$row = $this->linkInput($request, $errors, true);
		if($errors)return JsonResponse::error(422, ts('validation failed'), ['fields' => $errors]);

		$row += ['xcore_drive_file_id' => $id, 'token' => Drive::linkToken(), 'xcore_user_id' => \Model\User::getUID()];
		$link_id = (int)DB('xcore_drive_link')->insert($row, $this->author());
		\Core\Log::write('link', 'file', ['name' => $file['name'], 'expires_at' => $row['expires_at'], 'max_downloads' => $row['max_downloads'], 'password' => ($row['password'] !== ''), 'notify' => $row['notify'], 'note' => $row['note']], $id, 'info', $this->module);

		$link = DB()->query("SELECT * FROM xcore_drive_link WHERE id = :id", ['id' => $link_id])->fetch();
		return new JsonResponse(['link' => $this->linksOut([$link])[0]], 201);

	}

	/**
	 * changes a share link (its creator, or the manage right of the folder); password: absent = kept, '' = removed
	 *
	 * @param Request $request same fields as the creation
	 * @param int $id
	 *
	 * @return JsonResponse {"link"}
	 */
	#[Route('/links/{id}/', name: 'link.update', methods: ['PUT'])]
	public function linkUpdate(Request $request, int $id):JsonResponse
	{

		if(!$link = $this->linkEditable($id))return JsonResponse::error(404, 'link not found');

		$errors = [];
		$row = $this->linkInput($request, $errors, $request->request->has('password'));
		if($errors)return JsonResponse::error(422, ts('validation failed'), ['fields' => $errors]);

		// a new limit or date gives the link a new life, the lock of the wrong passwords is lifted
		$row += ['failures' => 0, 'locked_until' => null];
		DB('xcore_drive_link')->update($row, $id, -1, $this->author());
		\Core\Log::write('link_update', 'file', ['link' => $id, 'expires_at' => $row['expires_at'], 'max_downloads' => $row['max_downloads'], 'notify' => $row['notify'], 'note' => $row['note']], (int)$link['xcore_drive_file_id'], 'info', $this->module);

		$link = DB()->query("SELECT * FROM xcore_drive_link WHERE id = :id", ['id' => $id])->fetch();
		return new JsonResponse(['link' => $this->linksOut([$link])[0]]);

	}

	/**
	 * revokes a share link: its page answers "link not available" (kept in the list)
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"link"}
	 */
	#[Route('/links/{id}/revoke/', name: 'link.revoke', methods: ['POST'])]
	public function linkRevoke(int $id):JsonResponse
	{

		if(!$link = $this->linkEditable($id))return JsonResponse::error(404, 'link not found');

		if(!$link['revoked_at'])
		{

			DB('xcore_drive_link')->update(['revoked_at' => now()], $id, -1, $this->author());
			\Core\Log::write('link_revoke', 'file', ['link' => $id], (int)$link['xcore_drive_file_id'], 'info', $this->module);

		}

		$link = DB()->query("SELECT * FROM xcore_drive_link WHERE id = :id", ['id' => $id])->fetch();
		return new JsonResponse(['link' => $this->linksOut([$link])[0]]);

	}

	/**
	 * removes a share link from the lists (revoked first)
	 *
	 * @param int $id
	 *
	 * @return JsonResponse 204
	 */
	#[Route('/links/{id}/', name: 'link.delete', methods: ['DELETE'])]
	public function linkDelete(int $id):JsonResponse
	{

		if(!$link = $this->linkEditable($id))return JsonResponse::error(404, 'link not found');

		if(!$link['revoked_at'])DB('xcore_drive_link')->update(['revoked_at' => now()], $id, -1, $this->author());
		DB('xcore_drive_link')->delete($id, -1, $this->author());
		\Core\Log::write('link_delete', 'file', ['link' => $id], (int)$link['xcore_drive_file_id'], 'info', $this->module);

		return new JsonResponse(null, 204);

	}

	/**
	 * share links of the user (all the links of the drive with the manage right of the plugin), the ones of the files he can read
	 *
	 * @return JsonResponse {"links"}
	 */
	#[Route('/links/', name: 'links', methods: ['GET'])]
	public function links():JsonResponse
	{

		if(!\Model\User::hasRight(Drive::PLUGIN, 'list'))return JsonResponse::error(403, 'access denied');

		$all = \Model\User::hasRight(Drive::PLUGIN, 'manage');
		$links = DB()->query("SELECT l.* FROM xcore_drive_link l WHERE l.deleted = 'no'".(($all) ? '' : ' AND l.xcore_user_id = :uid')." ORDER BY l.id DESC LIMIT 1000",
							 ($all) ? [] : ['uid' => \Model\User::getUID()])->fetchAll();

		// files gone or not readable left out (the trashed ones stay: their links are shown unavailable)
		$readable = [];
		foreach(array_unique(array_map(fn($l) => (int)$l['xcore_drive_file_id'], $links)) as $file_id)
		{

			$folder = DB()->query("SELECT xcore_drive_folder_id FROM xcore_drive_file WHERE id = :id", ['id' => $file_id])->fetchOne();
			if($folder !== false && $folder !== null && Drive::chain((int)$folder) !== null && Drive::can((int)$folder, 'read'))$readable[$file_id] = true;

		}

		return new JsonResponse(['links' => $this->linksOut(array_values(array_filter($links, fn($l) => isset($readable[(int)$l['xcore_drive_file_id']]))))]);

	}

	/**
	 * fields of a link checked: expiration (date, never in the past), max downloads, password (6 characters at least), notify, note (one line, 100 characters)
	 *
	 * @param Request $request
	 * @param array $errors
	 * @param bool $password the password is set (creation, or sent in a change)
	 *
	 * @return array row
	 */
	private function linkInput(Request $request, array &$errors, bool $password):array
	{

		$row = [];

		$expires = trim((string)$request->request->get('expires_at', ''));
		if($expires === '')$row['expires_at'] = null;
		elseif(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || !strtotime($expires))$errors['expires_at'] = [ts('invalid date')];
		elseif($expires < date('Y-m-d'))$errors['expires_at'] = [ts('the date is in the past')];
		else $row['expires_at'] = "{$expires} 23:59:59";

		$max = $request->request->get('max_downloads', 0);
		if(!is_numeric($max) || (int)$max < 0 || (int)$max > 100000)$errors['max_downloads'] = [ts('invalid number')];
		else $row['max_downloads'] = (int)$max;

		if($password)
		{

			$value = (string)$request->request->get('password', '');
			if($value !== '' && mb_strlen($value) < 6)$errors['password'] = [ts('6 characters at least')];
			elseif(mb_strlen($value) > 100)$errors['password'] = [ts('100 characters at most')];
			else $row['password'] = ($value === '') ? '' : password_hash($value, PASSWORD_DEFAULT);

		}

		$row['notify'] = filter_var($request->request->get('notify', false), FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';

		// note of the creator (ex: client number): one line, never shown on the public page
		$note = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$request->request->get('note', '')) ?? '');
		if(mb_strlen($note) > 100)$errors['note'] = [ts('100 characters at most')];
		else $row['note'] = $note;

		return $row;

	}

	/**
	 * link the user can change: its creator, or the manage right of the folder of its file
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	private function linkEditable(int $id):?array
	{

		$link = DB()->query("SELECT * FROM xcore_drive_link WHERE id = :id AND deleted = 'no'", ['id' => $id])->fetch();
		if(!$link)return null;

		$folder = DB()->query("SELECT xcore_drive_folder_id FROM xcore_drive_file WHERE id = :id", ['id' => (int)$link['xcore_drive_file_id']])->fetchOne();
		if($folder === false || $folder === null || Drive::chain((int)$folder) === null)return null;

		$mine = ((int)$link['xcore_user_id'] === \Model\User::getUID()) && Drive::can((int)$folder, 'read');
		return ($mine || Drive::can((int)$folder, 'manage')) ? $link : null;

	}

	/**
	 * links for the interface: url, state, file, creator; never the password nor its hash
	 *
	 * @param array $links rows
	 *
	 * @return array
	 */
	private function linksOut(array $links):array
	{

		$out = [];
		$creators = [];
		foreach($links as $l)$creators[(int)$l['xcore_user_id']] = true;
		$users = [];
		if($creators)
		{

			foreach(DB()->query("SELECT id, login, firstname, lastname, avatar FROM xcore_user WHERE id IN (".implode(',', array_map('intval', array_keys($creators))).")")->fetchAll() as $rec)
			{

				$avatar = \Core\Widget::avatar($rec);
				$users[(int)$rec['id']] = ['id' => (int)$rec['id'], 'name' => $avatar['title'] ?: (string)$rec['login'], 'avatar' => $avatar['avatar']];

			}

		}

		foreach($links as $l)
		{

			$file = DB()->query("SELECT id, name, extension, size, xcore_drive_folder_id, deleted FROM xcore_drive_file WHERE id = :id", ['id' => (int)$l['xcore_drive_file_id']])->fetch();
			$l['file'] = ($file && $file['deleted'] === 'no') ? Drive::file((int)$file['id']) : null;

			$out[] = [
				'id' => (int)$l['id'],
				'url' => Drive::linkUrl((string)$l['token']),
				'state' => Drive::linkState($l),
				'expires_at' => $l['expires_at'] ? substr((string)$l['expires_at'], 0, 10) : '',
				'max_downloads' => (int)$l['max_downloads'],
				'downloads' => (int)$l['downloads'],
				'password' => ((string)$l['password'] !== ''),
				'notify' => ($l['notify'] === 'yes'),
				'note' => (string)($l['note'] ?? ''),
				'last_download_at' => (string)($l['last_download_at'] ?? ''),
				'revoked_at' => (string)($l['revoked_at'] ?? ''),
				'created_at' => (string)$l['created_at'],
				'by' => $users[(int)$l['xcore_user_id']] ?? null,
				'file' => ($file) ? ['id' => (int)$file['id'], 'name' => (string)$file['name'], 'extension' => (string)$file['extension'], 'size' => (int)$file['size'], 'folder' => (int)$file['xcore_drive_folder_id'], 'location' => Drive::folderPath((int)$file['xcore_drive_folder_id']), 'trashed' => ($file['deleted'] === 'yes')] : null,
			];

		}

		return $out;

	}

	/**
	 * files changed lately that the user can read (50 at most)
	 *
	 * @return JsonResponse {"files"}
	 */
	#[Route('/recent/', name: 'recent', methods: ['GET'])]
	public function recent():JsonResponse
	{

		if(!\Model\User::hasRight(Drive::PLUGIN, 'list'))return JsonResponse::error(403, 'access denied');

		$files = DB()->query("SELECT f.* FROM xcore_drive_file f WHERE f.deleted = 'no' ORDER BY COALESCE(f.updated_at, f.created_at) DESC, f.id DESC LIMIT 300")->fetchAll();
		return new JsonResponse(['files' => array_slice($this->filesReadable($files), 0, 50)]);

	}

	/**
	 * search in the whole drive, folders and files the user can read: name (2 characters at least, wildcards * and ?) and / or #tags, type
	 *
	 * @param Request $request query string: q (ex: devis, *.pdf, #client, devis #client), type (key of the global drive.filters)
	 *
	 * @return JsonResponse {"folders", "files"}
	 */
	#[Route('/search/', name: 'search', methods: ['GET'])]
	public function search(Request $request):JsonResponse
	{

		if(!\Model\User::hasRight(Drive::PLUGIN, 'list'))return JsonResponse::error(403, 'access denied');

		$query = Drive::parseQuery((string)$request->query->get('q', ''));
		$type = $this->typeExtensions((string)$request->query->get('type', ''));
		if(mb_strlen(str_replace(['%', '_'], '', (string)$query['name'])) < 2 && !$query['tags'] && !$type)return new JsonResponse(['folders' => [], 'files' => []]);

		// folders by their name only, when neither a tag nor a type is asked
		$folders = [];
		if($query['name'] !== null && !$query['tags'] && !$type)
		{

			$folders = DB()->query("SELECT f.*, 0 AS children FROM xcore_drive_folder f WHERE f.deleted = 'no' AND f.name LIKE :q ORDER BY f.name LIMIT 300", ['q' => $query['name']])->fetchAll();
			$folders = array_values(array_filter($folders, fn($f) => Drive::chain((int)$f['id']) !== null && Drive::can((int)$f['id'], 'read')));

		}
		$users = $this->users($folders);

		$binds = [];
		$sql = ($query['name'] !== null) ? " AND f.name LIKE :q" : '';
		if($query['name'] !== null)$binds['q'] = $query['name'];
		$sql .= Drive::tagsSql($query['tags'], $binds).$this->typeSql($type, $binds);
		$files = DB()->query("SELECT f.* FROM xcore_drive_file f WHERE f.deleted = 'no'{$sql} ORDER BY f.name LIMIT 300", $binds)->fetchAll();

		return new JsonResponse([
			'folders' => array_slice(array_map(function($f) use ($users)
			{

				$out = $this->folderOut($f, $users);
				$out['location'] = Drive::folderPath((int)$f['xcore_drive_folder_id']);
				return $out;

			}, $folders), 0, 100),
			'files' => array_slice($this->filesReadable($files), 0, 100),
		]);

	}

	// ================================================================================================================
	// upload by chunks
	// ================================================================================================================

	/**
	 * starts an upload in a folder (write right): type, size and quota checked first, a name taken asks what to do
	 *
	 * @param Request $request {"folder", "name", "size", "conflict": ""|"replace"|"keep_both"|"skip"}
	 *
	 * @return JsonResponse 201 {"upload", "chunk_size", "received", "name"}, 409 {"error", "conflict": {"type", "id", "name"}}, 200 {"skipped": true}
	 */
	#[Route('/uploads/', name: 'upload.start', methods: ['POST'])]
	public function uploadStart(Request $request):JsonResponse
	{

		$folder_id = (int)$request->request->get('folder', 0);
		if(Drive::chain($folder_id) === null)return JsonResponse::error(404, 'folder not found');
		if(!Drive::can($folder_id, 'write'))return JsonResponse::error(403, 'access denied');

		$name = Drive::cleanName((string)$request->request->get('name', ''));
		$size = (int)$request->request->get('size', -1);
		if($name === '' || $size < 0)return JsonResponse::error(422, ts('the upload failed'));

		$settings = Drive::settings($folder_id);
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if($extension === '' || in_array($extension, \Core\File::FORBIDDEN_EXTENSIONS, true) || !in_array($extension, $settings['extensions'], true))
			return JsonResponse::error(422, ts('file type not allowed: [EXTENSIONS]', ['EXTENSIONS' => implode(', ', $settings['extensions'])]));
		if($size > $settings['max_size'])return JsonResponse::error(422, ts('the file is larger than [SIZE] MB', ['SIZE' => round($settings['max_size'] / 1048576, 1)]));
		if(!\Core\Storage::canStore($size))return JsonResponse::error(422, ts('Storage full, contact your administrator'), ['reason' => 'storage_full']);

		// name taken: the user chooses replace, keep both or skip
		$conflict = (string)$request->request->get('conflict', '');
		$replace_id = 0;
		if($taken = Drive::nameTaken($folder_id, $name))
		{

			if($conflict === 'skip')return new JsonResponse(['skipped' => true]);
			if($conflict === 'replace' && $taken['type'] === 'file')$replace_id = $taken['id'];
			elseif($conflict === 'keep_both' || ($conflict === 'replace' && $taken['type'] === 'folder'))$name = Drive::uniqueName($folder_id, $name);
			else return JsonResponse::error(409, ts('[NAME] already exists in this folder', ['NAME' => $name]), ['conflict' => ['type' => $taken['type'], 'id' => $taken['id'], 'name' => $name]]);

		}

		$dir = $this->uploadDir();
		$upload = bin2hex(random_bytes(16));
		touch("{$dir}/{$upload}");
		file_put_contents("{$dir}/{$upload}.json", json_encode(['uid' => \Model\User::getUID(), 'folder' => $folder_id, 'name' => $name, 'size' => $size, 'replace' => $replace_id]));

		return new JsonResponse(['upload' => $upload, 'chunk_size' => $this->chunkSize(), 'received' => 0, 'name' => $name], 201);

	}

	/**
	 * bytes received by an upload (resume after a cut)
	 *
	 * @param string $upload
	 *
	 * @return JsonResponse {"received", "size"}
	 */
	#[Route('/uploads/{upload}/', name: 'upload.status', methods: ['GET'], requirements: ['upload' => '[a-f0-9]{32}'])]
	public function uploadStatus(string $upload):JsonResponse
	{

		if(!$meta = $this->uploadMeta($upload))return JsonResponse::error(404, 'upload not found');

		clearstatcache(true, $meta['path']);
		return new JsonResponse(['received' => (int)filesize($meta['path']), 'size' => $meta['size']]);

	}

	/**
	 * next chunk of an upload, multipart: chunk (file), offset (bytes already received)
	 *
	 * @param Request $request
	 * @param string $upload
	 *
	 * @return JsonResponse {"received"}, 409 {"received"} when the offset is not the end of the file
	 */
	#[Route('/uploads/{upload}/chunk/', name: 'upload.chunk', methods: ['POST'], requirements: ['upload' => '[a-f0-9]{32}'])]
	public function uploadChunk(Request $request, string $upload):JsonResponse
	{

		if(!$meta = $this->uploadMeta($upload))return JsonResponse::error(404, 'upload not found');

		$chunk = $request->files->get('chunk');
		if(!$chunk || !$chunk->isValid())return JsonResponse::error(422, ts('the upload failed'));

		clearstatcache(true, $meta['path']);
		$received = (int)filesize($meta['path']);
		if((int)$request->request->get('offset', -1) !== $received)return JsonResponse::error(409, 'wrong offset', ['received' => $received]);
		if($received + $chunk->getSize() > $meta['size'])return JsonResponse::error(422, ts('the upload failed'));

		$in = fopen($chunk->getPathname(), 'rb');
		$out = fopen($meta['path'], 'ab');
		if(!$in || !$out || stream_copy_to_stream($in, $out) === false)return JsonResponse::error(500, 'the chunk cannot be written');
		fclose($in);
		fclose($out);

		clearstatcache(true, $meta['path']);
		return new JsonResponse(['received' => (int)filesize($meta['path'])]);

	}

	/**
	 * ends an upload: the whole file checked (real type, size, quota) and stored, a duplicate in the folder is reported
	 *
	 * @param string $upload
	 *
	 * @return JsonResponse 201 {"file", "duplicate"}
	 */
	#[Route('/uploads/{upload}/complete/', name: 'upload.complete', methods: ['POST'], requirements: ['upload' => '[a-f0-9]{32}'])]
	public function uploadComplete(string $upload):JsonResponse
	{

		if(!$meta = $this->uploadMeta($upload))return JsonResponse::error(404, 'upload not found');

		clearstatcache(true, $meta['path']);
		if((int)filesize($meta['path']) !== $meta['size'])return JsonResponse::error(422, ts('the upload is not complete'), ['received' => (int)filesize($meta['path'])]);

		$folder_id = $meta['folder'];
		if(Drive::chain($folder_id) === null)
		{

			$this->uploadDrop($meta['path']);
			return JsonResponse::error(404, 'folder not found');

		}
		if(!Drive::can($folder_id, 'write'))return JsonResponse::error(403, 'access denied');

		if($error = Drive::checkFile($meta['path'], $meta['name'], $folder_id))
		{

			$this->uploadDrop($meta['path']);
			return JsonResponse::error(422, $error);

		}

		// replaced file gone meanwhile: new file; name taken meanwhile: kept both
		$replace = ($meta['replace'] > 0 && ($old = Drive::file($meta['replace'])) && (int)$old['xcore_drive_folder_id'] === $folder_id) ? $meta['replace'] : 0;
		$name = ($replace) ? $meta['name'] : Drive::uniqueName($folder_id, $meta['name']);
		$grow = $meta['size'] - (($replace) ? (int)$old['size'] : 0);
		if($grow > 0 && !\Core\Storage::canStore($grow))
		{

			$this->uploadDrop($meta['path']);
			return JsonResponse::error(422, ts('Storage full, contact your administrator'), ['reason' => 'storage_full']);

		}

		$sha256 = (string)hash_file('sha256', $meta['path']);
		$duplicate = (string)DB()->query("SELECT name FROM xcore_drive_file WHERE xcore_drive_folder_id = :folder AND sha256 = :sha AND deleted = 'no' AND id <> :replace LIMIT 1", ['folder' => $folder_id, 'sha' => $sha256, 'replace' => $replace])->fetchOne();

		$file = Drive::storeFile($meta['path'], $name, $folder_id, $replace);
		@unlink("{$meta['path']}.json");

		\Core\Log::write(($replace) ? 'replace' : 'upload', 'file', ['name' => $name, 'size' => (int)$file['size'], 'path' => Drive::folderPath($folder_id)], (int)$file['id'], 'info', $this->module);

		return new JsonResponse(['file' => $this->fileOut($file, $this->users([$file]), []), 'duplicate' => $duplicate], 201);

	}

	/**
	 * cancels an upload
	 *
	 * @param string $upload
	 *
	 * @return JsonResponse 204
	 */
	#[Route('/uploads/{upload}/', name: 'upload.cancel', methods: ['DELETE'], requirements: ['upload' => '[a-f0-9]{32}'])]
	public function uploadCancel(string $upload):JsonResponse
	{

		if($meta = $this->uploadMeta($upload))$this->uploadDrop($meta['path']);
		return new JsonResponse(null, 204);

	}

	// ================================================================================================================
	// move, copy, trash
	// ================================================================================================================

	/**
	 * moves folders and files to a folder (write right in their folder and in the target)
	 * a move that changes who sees the items asks a confirmation (409 access_change), names taken ask what to do (409 conflict)
	 *
	 * @param Request $request {"items": [{"type", "id"}], "target", "conflict": ""|"replace"|"keep_both"|"skip", "confirm": bool}
	 *
	 * @return JsonResponse {"moved", "skipped"}
	 */
	#[Route('/move/', name: 'move', methods: ['POST'])]
	public function move(Request $request):JsonResponse
	{

		$target = (int)$request->request->get('target', 0);
		$check = $this->transferCheck($request, $target, true);
		if($check instanceof JsonResponse)return $check;

		[$items, $conflicts] = $check;
		$conflict = (string)$request->request->get('conflict', '');
		if($conflicts && !in_array($conflict, ['replace', 'keep_both', 'skip'], true))
			return JsonResponse::error(409, ts('some names already exist in the target folder'), ['reason' => 'conflict', 'names' => array_values(array_unique(array_column($conflicts, 'name')))]);

		$moved = 0;
		$skipped = 0;
		foreach($items as $item)
		{

			$name = (string)$item['row']['name'];
			$taken = Drive::nameTaken($target, $name, $item['type'], $item['id']);
			if($taken)
			{

				if($conflict === 'skip')
				{

					$skipped++;
					continue;

				}

				// a file replaces a file: the old one goes to the trash
				if($conflict === 'replace' && $item['type'] === 'file' && $taken['type'] === 'file')DB('xcore_drive_file')->delete($taken['id'], -1, $this->author());
				else $name = Drive::uniqueName($target, $name, $item['type'] === 'folder');

			}

			$from = Drive::folderPath((int)$item['row']['xcore_drive_folder_id']);
			if($item['type'] === 'folder')Drive::moveFolder($item['id'], $target, $name);
			else DB('xcore_drive_file')->update(['xcore_drive_folder_id' => $target, 'name' => $name], $item['id'], -1, $this->author());

			\Core\Log::write('move', $item['type'], ['name' => $name, 'from' => $from, 'to' => Drive::folderPath($target)], $item['id'], 'info', $this->module);
			$moved++;

		}

		return new JsonResponse(['moved' => $moved, 'skipped' => $skipped]);

	}

	/**
	 * copies folders and files to a folder (read right in their folder, write right in the target), counted in the quota
	 *
	 * @param Request $request {"items": [{"type", "id"}], "target", "conflict": ""|"keep_both"|"skip"}
	 *
	 * @return JsonResponse {"copied", "skipped"}
	 */
	#[Route('/copy/', name: 'copy', methods: ['POST'])]
	public function copy(Request $request):JsonResponse
	{

		$target = (int)$request->request->get('target', 0);
		$check = $this->transferCheck($request, $target, false);
		if($check instanceof JsonResponse)return $check;

		[$items, $conflicts] = $check;
		$conflict = (string)$request->request->get('conflict', '');

		// a copy in its own folder is kept with a free name
		$same_folder = array_filter($conflicts, fn($c) => (int)$c['parent'] === $target);
		if(count($conflicts) > count($same_folder) && !in_array($conflict, ['keep_both', 'skip'], true))
			return JsonResponse::error(409, ts('some names already exist in the target folder'), ['reason' => 'conflict', 'names' => array_values(array_unique(array_column($conflicts, 'name')))]);

		// size and count of everything copied
		$files = 0;
		$bytes = 0;
		foreach($items as $item)
		{

			if($item['type'] === 'file')
			{

				$files++;
				$bytes += (int)$item['row']['size'];

			}
			else
			{

				$count = Drive::treeCount($item['id']);
				$files += $count['files'] + $count['folders'];
				$bytes += $count['bytes'];

			}

		}

		if($files > Drive::COPY_MAX)return JsonResponse::error(422, ts('too many files to copy at once ([MAX] at most)', ['MAX' => Drive::COPY_MAX]));
		if(!\Core\Storage::canStore($bytes))return JsonResponse::error(422, ts('Storage full, contact your administrator'), ['reason' => 'storage_full']);

		$copied = 0;
		$skipped = 0;
		foreach($items as $item)
		{

			$name = (string)$item['row']['name'];
			if(Drive::nameTaken($target, $name))
			{

				if($conflict === 'skip' && (int)$item['row']['xcore_drive_folder_id'] !== $target)
				{

					$skipped++;
					continue;

				}

				$name = Drive::uniqueName($target, $name, $item['type'] === 'folder');

			}

			$id = ($item['type'] === 'folder') ? Drive::copyFolder($item['id'], $target, $name) : Drive::copyFile($item['row'], $target, $name);
			\Core\Log::write('copy', $item['type'], ['name' => $name, 'from' => $item['id'], 'to' => Drive::folderPath($target)], $id, 'info', $this->module);
			$copied++;

		}

		return new JsonResponse(['copied' => $copied, 'skipped' => $skipped]);

	}

	/**
	 * puts folders and files in the trash (write right in their folder)
	 *
	 * @param Request $request {"items": [{"type", "id"}]}
	 *
	 * @return JsonResponse {"trashed"}
	 */
	#[Route('/trash/', name: 'trash', methods: ['POST'])]
	public function trash(Request $request):JsonResponse
	{

		$items = $this->items($request);
		if(!$items)return JsonResponse::error(422, ts('nothing selected'));

		foreach($items as $item)
		{

			if(!$item['row'])return JsonResponse::error(404, 'not found');
			if(!Drive::can((int)$item['row']['xcore_drive_folder_id'], 'write'))return JsonResponse::error(403, 'access denied');

		}

		foreach($items as $item)
		{

			DB(($item['type'] === 'folder') ? 'xcore_drive_folder' : 'xcore_drive_file')->delete($item['id'], -1, $this->author());
			\Core\Log::write('trash', $item['type'], ['name' => $item['row']['name'], 'path' => Drive::folderPath((int)$item['row']['xcore_drive_folder_id'])], $item['id'], 'info', $this->module);

		}

		Drive::reset();
		return new JsonResponse(['trashed' => count($items)]);

	}

	/**
	 * content of the trash: folders and files trashed whose folder is alive and readable
	 *
	 * @return JsonResponse {"folders", "files"}
	 */
	#[Route('/trash/', name: 'trash.list', methods: ['GET'])]
	public function trashList():JsonResponse
	{

		if(!\Model\User::hasRight(Drive::PLUGIN, 'list'))return JsonResponse::error(403, 'access denied');

		$folders = array_values(array_filter(
			DB()->query("SELECT f.*, 0 AS children FROM xcore_drive_folder f WHERE f.deleted = 'yes' ORDER BY f.deleted_at DESC LIMIT 1000")->fetchAll(),
			fn($f) => Drive::chain((int)$f['xcore_drive_folder_id']) !== null && Drive::can((int)$f['xcore_drive_folder_id'], 'read')
		));

		$files = array_values(array_filter(
			DB()->query("SELECT f.* FROM xcore_drive_file f WHERE f.deleted = 'yes' ORDER BY f.deleted_at DESC LIMIT 1000")->fetchAll(),
			fn($f) => Drive::chain((int)$f['xcore_drive_folder_id']) !== null && Drive::can((int)$f['xcore_drive_folder_id'], 'read')
		));

		$users = $this->users(array_merge($folders, $files));
		$out = fn(array $row, array $item) => $item + ['deleted_at' => $row['deleted_at'], 'deleted_by' => $users[(string)$row['deleted_by']] ?? null, 'location' => Drive::folderPath((int)$row['xcore_drive_folder_id'])];

		return new JsonResponse([
			'folders' => array_map(fn($f) => $out($f, $this->folderOut($f, $users)), $folders),
			'files' => array_map(fn($f) => $out($f, $this->fileOut($f, $users, [])), $files),
		]);

	}

	/**
	 * restores trashed folders and files in their folder (write right), a name taken meanwhile gets a free name
	 *
	 * @param Request $request {"items": [{"type", "id"}]}
	 *
	 * @return JsonResponse {"restored"}
	 */
	#[Route('/trash/restore/', name: 'trash.restore', methods: ['POST'])]
	public function trashRestore(Request $request):JsonResponse
	{

		$items = $this->items($request, true);
		if(!$items)return JsonResponse::error(422, ts('nothing selected'));

		foreach($items as $item)
		{

			if(!$item['row'] || $item['row']['deleted'] !== 'yes')return JsonResponse::error(404, 'not found');
			$parent = (int)$item['row']['xcore_drive_folder_id'];
			if(Drive::chain($parent) === null)return JsonResponse::error(422, ts('the folder of [NAME] is in the trash: restore it first', ['NAME' => $item['row']['name']]));
			if(!Drive::can($parent, 'write'))return JsonResponse::error(403, 'access denied');

		}

		foreach($items as $item)
		{

			$parent = (int)$item['row']['xcore_drive_folder_id'];
			$name = Drive::uniqueName($parent, (string)$item['row']['name'], $item['type'] === 'folder');
			$table = ($item['type'] === 'folder') ? 'xcore_drive_folder' : 'xcore_drive_file';

			DB()->query("UPDATE {$table} SET deleted = 'no', name = :name, deleted_at = NULL, deleted_by = NULL, updated_at = :now, updated_by = :author WHERE id = :id", ['name' => $name, 'now' => now(), 'author' => $this->author(), 'id' => $item['id']]);
			\Core\Log::write('restore', $item['type'], ['name' => $name, 'path' => Drive::folderPath($parent)], $item['id'], 'info', $this->module);

		}

		Drive::reset();
		return new JsonResponse(['restored' => count($items)]);

	}

	/**
	 * deletes for good trashed folders and files (delete right in their folder), all of the trash with {"all": true}
	 *
	 * @param Request $request {"items": [{"type", "id"}]} or {"all": true}
	 *
	 * @return JsonResponse {"deleted", "freed"}
	 */
	#[Route('/trash/delete/', name: 'trash.delete', methods: ['POST'])]
	public function trashDelete(Request $request):JsonResponse
	{

		if(filter_var($request->request->get('all', false), FILTER_VALIDATE_BOOLEAN))
		{

			$items = [];
			foreach(['folder' => 'xcore_drive_folder', 'file' => 'xcore_drive_file'] as $type => $table)
			{

				foreach(DB()->query("SELECT * FROM {$table} WHERE deleted = 'yes'")->fetchAll() as $row)
				{

					$parent = (int)$row['xcore_drive_folder_id'];
					if(Drive::chain($parent) !== null && Drive::can($parent, 'delete'))$items[] = ['type' => $type, 'id' => (int)$row['id'], 'row' => $row];

				}

			}

		}
		else
		{

			$items = $this->items($request, true);
			if(!$items)return JsonResponse::error(422, ts('nothing selected'));

			foreach($items as $item)
			{

				if(!$item['row'] || $item['row']['deleted'] !== 'yes')return JsonResponse::error(404, 'not found');
				$parent = (int)$item['row']['xcore_drive_folder_id'];
				if(Drive::chain($parent) === null || !Drive::can($parent, 'delete'))return JsonResponse::error(403, 'access denied');

			}

		}

		$freed = 0;
		foreach($items as $item)
		{

			$freed += Drive::purge($item['type'], $item['id']);
			\Core\Log::write('purge', $item['type'], ['name' => $item['row']['name']], $item['id'], 'warning', $this->module);

		}

		return new JsonResponse(['deleted' => count($items), 'freed' => $freed]);

	}

	// ================================================================================================================
	// helpers
	// ================================================================================================================

	/**
	 * items of the request with their row (null when missing)
	 *
	 * @param Request $request {"items": [{"type", "id"}]}
	 * @param bool $trashed rows of the trash too
	 *
	 * @return array [['type', 'id', 'row']]
	 */
	private function items(Request $request, bool $trashed=false):array
	{

		$items = [];
		foreach((array)$request->request->all('items') as $raw)
		{

			$type = (string)($raw['type'] ?? '');
			$id = (int)($raw['id'] ?? 0);
			if(!in_array($type, ['folder', 'file'], true) || $id <= 0)continue;

			$key = "{$type}:{$id}";
			if(isset($items[$key]))continue;

			$table = ($type === 'folder') ? 'xcore_drive_folder' : 'xcore_drive_file';
			$row = DB()->query("SELECT * FROM {$table} WHERE id = :id".(($trashed) ? '' : " AND deleted = 'no'"), ['id' => $id])->fetch();

			// an alive item lives in an alive folder
			if($row && !$trashed && Drive::chain((int)$row['xcore_drive_folder_id']) === null)$row = false;

			$items[$key] = ['type' => $type, 'id' => $id, 'row' => $row ?: null];

		}

		return array_values($items);

	}

	/**
	 * checks a move or a copy: items alive, rights, a folder never inside itself, access change, names taken in the target
	 *
	 * @param Request $request
	 * @param int $target
	 * @param bool $move write right on the folder of the items (move), read right (copy)
	 *
	 * @return JsonResponse|array error, or [items, conflicts]
	 */
	private function transferCheck(Request $request, int $target, bool $move):JsonResponse|array
	{

		if(Drive::chain($target) === null)return JsonResponse::error(404, 'folder not found');
		if(!Drive::can($target, 'write'))return JsonResponse::error(403, 'access denied');

		$items = $this->items($request);
		if(!$items)return JsonResponse::error(422, ts('nothing selected'));

		$target_access = Drive::access($target);
		$access_change = false;
		$conflicts = [];

		foreach($items as $item)
		{

			if(!$item['row'])return JsonResponse::error(404, 'not found');

			$parent = (int)$item['row']['xcore_drive_folder_id'];
			if(!Drive::can($parent, ($move) ? 'write' : 'read'))return JsonResponse::error(403, 'access denied');
			if($item['type'] === 'folder' && ($item['id'] === $target || Drive::isInside($item['id'], $target)))return JsonResponse::error(422, ts('a folder cannot go inside itself'));

			if($move && $parent !== $target && Drive::access($parent) !== $target_access)$access_change = true;
			if(($move && $parent === $target) === false && Drive::nameTaken($target, (string)$item['row']['name'], ($move) ? $item['type'] : '', ($move) ? $item['id'] : 0))
				$conflicts[] = ['name' => (string)$item['row']['name'], 'parent' => $parent];

		}

		if($access_change && !filter_var($request->request->get('confirm', false), FILTER_VALIDATE_BOOLEAN))
			return JsonResponse::error(409, ts('the target folder does not give access to the same groups: the moved items will take its access'), ['reason' => 'access_change']);

		return [$items, $conflicts];

	}

	/**
	 * folder as sent to react
	 *
	 * @param array $f row (with children)
	 * @param array $users login => user
	 *
	 * @return array
	 */
	private function folderOut(array $f, array $users):array
	{

		$by = (string)($f['updated_by'] ?: $f['created_by']);
		return [
			'type' => 'folder',
			'id' => (int)$f['id'],
			'name' => (string)$f['name'],
			'count' => (int)($f['children'] ?? 0),
			'date' => (string)($f['updated_at'] ?: $f['created_at']),
			'by' => $users[$by] ?? null,
		];

	}

	/**
	 * file as sent to react
	 *
	 * @param array $f row
	 * @param array $users login => user
	 * @param array $favorites ids of the favorite files of the user
	 * @param array $tags id => tags
	 *
	 * @return array
	 */
	private function fileOut(array $f, array $users, array $favorites, array $tags=[]):array
	{

		$by = (string)($f['updated_by'] ?: $f['created_by']);
		return [
			'type' => 'file',
			'id' => (int)$f['id'],
			'name' => (string)$f['name'],
			'extension' => (string)$f['extension'],
			'mime' => (string)$f['mime'],
			'size' => (int)$f['size'],
			'status' => (string)$f['status'],
			'date' => (string)($f['updated_at'] ?: $f['created_at']),
			'by' => $users[$by] ?? null,
			'favorite' => in_array((int)$f['id'], $favorites, true),
			'tags' => $tags[(int)$f['id']] ?? [],
			'hash' => (string)$f['sha256'],
			'url' => Drive::url((int)$f['id']),
		];

	}

	/**
	 * files the user can read, with their location
	 *
	 * @param array $files rows
	 *
	 * @return array
	 */
	private function filesReadable(array $files):array
	{

		$files = array_values(array_filter($files, fn($f) => Drive::chain((int)$f['xcore_drive_folder_id']) !== null && Drive::can((int)$f['xcore_drive_folder_id'], 'read')));
		$users = $this->users($files);
		$favorites = $this->favorites(array_column($files, 'id'));
		$tags = $this->tags(array_column($files, 'id'));

		return array_map(function($f) use ($users, $favorites, $tags)
		{

			$out = $this->fileOut($f, $users, $favorites, $tags);
			$out['folder'] = (int)$f['xcore_drive_folder_id'];
			$out['location'] = Drive::folderPath((int)$f['xcore_drive_folder_id']);
			return $out;

		}, $files);

	}

	/**
	 * authors of rows (updated_by, created_by, deleted_by = logins): login => {id, name, avatar}
	 *
	 * @param array $rows
	 *
	 * @return array
	 */
	private function users(array $rows):array
	{

		$logins = [];
		foreach($rows as $row)
		{

			foreach(['updated_by', 'created_by', 'deleted_by'] as $col)
			{

				if(!empty($row[$col]))$logins[(string)$row[$col]] = true;

			}

		}

		if(!$logins)return [];

		$binds = [];
		foreach(array_keys($logins) as $i => $login)$binds["l{$i}"] = $login;
		$recs = DB()->query("SELECT id, login, firstname, lastname, avatar FROM xcore_user WHERE login IN (:".implode(', :', array_keys($binds)).")", $binds)->fetchAll();

		$users = [];
		foreach($recs as $rec)
		{

			$avatar = \Core\Widget::avatar($rec);
			$users[(string)$rec['login']] = ['id' => (int)$rec['id'], 'name' => $avatar['title'] ?: (string)$rec['login'], 'avatar' => $avatar['avatar']];

		}

		return $users;

	}

	/**
	 * tags of some files
	 *
	 * @param array $ids
	 *
	 * @return array id => tags (sorted)
	 */
	private function tags(array $ids):array
	{

		$ids = array_filter(array_map('intval', $ids));
		if(!$ids)return [];

		$tags = [];
		foreach(DB()->query("SELECT record_id, tag FROM xcore_tag WHERE deleted = 'no' AND signature = '".Drive::TAGS."' AND record_id IN (".implode(',', $ids).") ORDER BY tag")->fetchAll() as $rec)
			$tags[(int)$rec['record_id']][] = (string)$rec['tag'];

		return $tags;

	}

	/**
	 * extensions of a filter by type (global drive.filters)
	 *
	 * @param string $type
	 *
	 * @return string[] [] = no filter
	 */
	private function typeExtensions(string $type):array
	{

		return ($type === '') ? [] : (Drive::filters()[$type] ?? ['-']);

	}

	/**
	 * sql of the files of some extensions
	 *
	 * @param array $extensions
	 * @param array $binds filled
	 *
	 * @return string
	 */
	private function typeSql(array $extensions, array &$binds):string
	{

		if(!$extensions)return '';

		$in = [];
		foreach(array_values($extensions) as $i => $extension)
		{

			$in[] = ":ext{$i}";
			$binds["ext{$i}"] = $extension;

		}

		return " AND f.extension IN (".implode(', ', $in).")";

	}

	/**
	 * ids of the favorite files of the user among some files
	 *
	 * @param array $ids
	 *
	 * @return int[]
	 */
	private function favorites(array $ids):array
	{

		$ids = array_filter(array_map('intval', $ids));
		if(!$ids)return [];

		return array_map('intval', DB()->query("SELECT xcore_drive_file_id FROM xcore_drive_favorite WHERE xcore_user_id = :uid AND deleted = 'no' AND xcore_drive_file_id IN (".implode(',', $ids).")", ['uid' => \Model\User::getUID()])->fetchAllOne());

	}

	/**
	 * pinned folders of the user still alive and readable
	 *
	 * @return array [{"id", "name", "path"}]
	 */
	private function pins():array
	{

		$pins = [];
		$recs = DB()->query("SELECT f.id, f.name FROM xcore_drive_pin p INNER JOIN xcore_drive_folder f ON f.id = p.xcore_drive_folder_id AND f.deleted = 'no'
							 WHERE p.xcore_user_id = :uid AND p.deleted = 'no' ORDER BY p.position, f.name", ['uid' => \Model\User::getUID()])->fetchAll();

		foreach($recs as $rec)
		{

			$id = (int)$rec['id'];
			if(Drive::chain($id) !== null && Drive::can($id, 'read'))$pins[] = ['id' => $id, 'name' => (string)$rec['name'], 'path' => Drive::folderPath($id)];

		}

		return $pins;

	}

	/**
	 * bytes sent by an upload request: config drive/chunk_size, never above upload_max_filesize and post_max_size
	 *
	 * @return int
	 */
	private function chunkSize():int
	{

		$limits = array_filter([
			(int)\Core\Config::get('drive/chunk_size', 5242880),
			size_to_bytes((string)ini_get('upload_max_filesize')),
			size_to_bytes((string)ini_get('post_max_size')) - 65536,
		], fn($v) => $v > 0);

		return max(65536, ($limits) ? min($limits) : 1048576);

	}

	/**
	 * folder of the uploads in progress (private_dir/tmp/drive), the ones older than a day removed
	 *
	 * @return string
	 */
	private function uploadDir():string
	{

		$dir = \Core\File::privateDir('tmp/drive');
		foreach(glob("{$dir}/*.json") ?: [] as $meta)
		{

			if(filemtime($meta) < time() - self::UPLOAD_TTL)$this->uploadDrop(substr($meta, 0, -5));

		}

		return $dir;

	}

	/**
	 * upload in progress of the logged user
	 *
	 * @param string $upload
	 *
	 * @return array|null ['path', 'folder', 'name', 'size', 'replace']
	 */
	private function uploadMeta(string $upload):?array
	{

		if(!preg_match('/^[a-f0-9]{32}$/', $upload))return null;

		$path = \Core\File::privateDir('tmp/drive')."/{$upload}";
		$meta = json_decode((string)@file_get_contents("{$path}.json"), true);
		if(!is_array($meta) || !is_file($path) || (int)($meta['uid'] ?? 0) !== \Model\User::getUID())return null;

		return ['path' => $path, 'folder' => (int)$meta['folder'], 'name' => (string)$meta['name'], 'size' => (int)$meta['size'], 'replace' => (int)($meta['replace'] ?? 0)];

	}

	/**
	 * removes an upload in progress and its meta
	 *
	 * @param string $path
	 *
	 * @return void
	 */
	private function uploadDrop(string $path):void
	{

		@unlink($path);
		@unlink("{$path}.json");

	}

	/**
	 * login of the logged user (created_by, updated_by)
	 *
	 * @return string
	 */
	private function author():string
	{

		return (string)\Core\Session::get('auth.login');

	}

}
