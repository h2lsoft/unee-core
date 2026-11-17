// Drive (module drive): types of the api /api/drive/, formats, display preferences, uploads by chunks
import { api, ApiException } from '../../api'
import { getLanguage, t } from '../../i18n'
import type { AvatarData } from '../UserAvatar'

export type DriveUser = {id: number, name: string, avatar: AvatarData}

export type Rights = {read: boolean, write: boolean, delete: boolean, manage: boolean}

export type DriveFolder = {
	type: 'folder'
	id: number
	name: string
	count: number
	date: string
	by: DriveUser | null
	// search, trash
	location?: string
	deleted_at?: string
	deleted_by?: DriveUser | null
}

export type DriveFile = {
	type: 'file'
	id: number
	name: string
	extension: string
	mime: string
	size: number
	status: string
	date: string
	by: DriveUser | null
	favorite: boolean
	url: string
	tags: string[]
	// sha256 of the content: base of the editors (conflict when another user saved meanwhile)
	hash: string
	// recent, favorites, search, trash
	folder?: number
	location?: string
	deleted_at?: string
	deleted_by?: DriveUser | null
}

export type DriveItem = DriveFolder | DriveFile

export type Usage = {quota: number, files: number, database: number, used: number, percent: number, full: boolean}

export type Pin = {id: number, name: string, path: string}

export type DriveIndex = {
	rights: Rights
	usage: Usage
	pins: Pin[]
	upload: {chunk_size: number, max_size: number}
	// filters by type (globals drive.filters): name => extensions
	filters: Record<string, string[]>
	// the image editor can save in avif (written by the server)
	avif: boolean
}

export type FolderContent = {
	folder: {id: number, name: string, path: string, public: boolean, pinned: boolean}
	breadcrumb: {id: number, name: string}[]
	rights: Rights
	settings: {extensions: string[], max_size: number, links: boolean, versions: boolean}
	folders: DriveFolder[]
	files: DriveFile[]
	total: {folders: number, files: number}
	page: number
	per_page: number
}


export type ItemRef = {type: 'folder' | 'file', id: number}

// share link of a file (page /s/<token>/): state ok, expired, revoked, used (max downloads reached), gone (file deleted or links refused by its folder), locked (wrong passwords)
export type DriveLink = {
	id: number
	url: string
	state: 'ok' | 'expired' | 'revoked' | 'used' | 'gone' | 'locked'
	// Y-m-d, '' = never
	expires_at: string
	// 0 = unlimited
	max_downloads: number
	downloads: number
	password: boolean
	notify: boolean
	note: string
	last_download_at: string
	revoked_at: string
	created_at: string
	by: DriveUser | null
	file: {id: number, name: string, extension: string, size: number, folder: number, location: string, trashed: boolean} | null
}
export type LinkInput = {expires_at: string, max_downloads: number, password?: string, notify: boolean, note: string}

export type SortCol = 'name' | 'size' | 'date' | 'by'
export type Sort = {col: SortCol, dir: 'asc' | 'desc'}

export const DEFAULT_SORT: Sort = {col: 'name', dir: 'asc'}
export const PER_PAGE = 200

export const ref = (item: DriveItem): ItemRef => ({type: item.type, id: item.id})
export const itemKey = (item: {type: string, id: number}) => `${item.type}:${item.id}`

// formats ************************************************************************************************************

// 14 Ko, 2,5 Mo, 1,2 Go (units translated)
export function driveSize(bytes: number): string
{

	const number = (value: number, digits: number) => new Intl.NumberFormat(getLanguage(), {maximumFractionDigits: digits}).format(value)
	if(bytes < 1024)return `${bytes} ${t('B')}`
	if(bytes < 1048576)return `${number(Math.round(bytes / 1024), 0)} ${t('KB')}`
	if(bytes < 1073741824)return `${number(bytes / 1048576, 1)} ${t('MB')}`
	return `${number(bytes / 1073741824, 1)} ${t('GB')}`

}

// 1 item, 5 items
export const itemCount = (n: number) => (n === 1) ? t('1 item') : t('[N] items', {N: n})

// 17/11/2026 10:42
export function driveDate(value: string): string
{

	const date = new Date(value.replace(' ', 'T'))
	if(!value || isNaN(date.getTime()))return value
	return new Intl.DateTimeFormat(getLanguage(), {dateStyle: 'short', timeStyle: 'short', hourCycle: 'h23'}).format(date)

}

// 25/11/2026 (date of a Y-m-d)
export function driveDay(value: string): string
{

	const date = new Date(`${value.slice(0, 10)}T12:00:00`)
	if(!value || isNaN(date.getTime()))return value
	return new Intl.DateTimeFormat(getLanguage(), {dateStyle: 'short'}).format(date)

}

// families of extensions (accept of the picker, previews)
export const FAMILIES: Record<string, string[]> = {
	image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'heic', 'tif', 'tiff'],
	video: ['mp4', 'webm', 'mov', 'avi', 'mkv'],
	audio: ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
	document: ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp'],
}

// images the browsers show (thumbnails, preview)
export const isViewableImage = (file: DriveFile) => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'].includes(file.extension)

// files of the editors (same lists as \Core\Drive::TEXT_MIMES and IMAGE_EDITABLE)
export const TEXT_EDITABLE = ['txt', 'md', 'csv', 'json', 'xml', 'log', 'ini', 'yml', 'yaml']
export const IMAGE_EDITABLE = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'bmp']
export const isText = (file: DriveFile) => TEXT_EDITABLE.includes(file.extension)
export const isEditableImage = (file: DriveFile) => IMAGE_EDITABLE.includes(file.extension)
// text files read by the preview and the editor: 5 MB at most (\Core\Drive::TEXT_MAX)
export const TEXT_MAX = 5242880

/**
 * content of a text file (preview, editor), no cache: the editor needs the last saved version
 */
export async function readText(file: DriveFile): Promise<string>
{

	if(file.size > TEXT_MAX)throw new DriveError(t('This file is too large to be shown ([SIZE] at most).', {SIZE: driveSize(TEXT_MAX)}))
	const response = await fetch(`${file.url}?v=${file.hash}`, {credentials: 'same-origin', cache: 'no-store'})
	if(!response.ok)throw new DriveError(t('An error occurred'))
	return await response.text()

}

/**
 * extensions accepted by the picker (accept=pdf,jpg or a family: image, video, audio, document), null = all
 */
export function acceptList(accept: string): string[] | null
{

	const parts = accept.split(',').map((p) => p.trim().toLowerCase().replace(/^\./, '')).filter(Boolean)
	if(!parts.length)return null

	return parts.flatMap((p) => FAMILIES[p] ?? [p])

}

// preferences of display (localStorage unee.files.prefs) *************************************************************

const PREFS_KEY = 'unee.files.prefs'
const SORTS_MAX = 200
const COLS: SortCol[] = ['name', 'size', 'date', 'by']

export const SIDEBAR_MIN = 180
export const SIDEBAR_MAX = 400
export const SIDEBAR_DEFAULT = 220

export type Prefs = {view: 'list' | 'grid', details: boolean, sidebar: number, sorts: Record<string, Sort>}

/**
 * preferences of the browser, invalid values left out, blocked storage ignored
 */
export function readPrefs(): Prefs
{

	const prefs: Prefs = {view: 'list', details: true, sidebar: SIDEBAR_DEFAULT, sorts: {}}

	try
	{

		const raw = JSON.parse(localStorage.getItem(PREFS_KEY) || '{}')
		if(raw.view === 'list' || raw.view === 'grid')prefs.view = raw.view
		if(typeof raw.details === 'boolean')prefs.details = raw.details
		if(Number.isInteger(raw.sidebar) && raw.sidebar >= SIDEBAR_MIN && raw.sidebar <= SIDEBAR_MAX)prefs.sidebar = raw.sidebar
		if(raw.sorts && typeof raw.sorts === 'object')
		{

			for(const [key, sort] of Object.entries(raw.sorts as Record<string, Sort>))
			{

				if(/^f\d+$/.test(key) && COLS.includes(sort?.col) && (sort?.dir === 'asc' || sort?.dir === 'desc'))prefs.sorts[key] = {col: sort.col, dir: sort.dir}

			}

		}

	}
	catch
	{

		// storage blocked or broken: defaults

	}

	return prefs

}

/**
 * merges a change in the stored preferences
 */
export function savePrefs(patch: Partial<Omit<Prefs, 'sorts'>>)
{

	try
	{

		const raw = JSON.parse(localStorage.getItem(PREFS_KEY) || '{}')
		localStorage.setItem(PREFS_KEY, JSON.stringify({...raw, ...patch}))

	}
	catch
	{

		// storage blocked

	}

}

/**
 * sort of a folder, the least recently sorted dropped above SORTS_MAX
 * key prefixed with f: integer-like keys would be listed in numeric order, not in insertion order
 */
export function saveSort(folder: number, sort: Sort)
{

	try
	{

		const raw = JSON.parse(localStorage.getItem(PREFS_KEY) || '{}')
		const sorts: Record<string, Sort> = (raw.sorts && typeof raw.sorts === 'object') ? raw.sorts : {}
		const key = `f${folder}`
		delete sorts[key]
		sorts[key] = sort

		const keys = Object.keys(sorts)
		for(const old of keys.slice(0, Math.max(0, keys.length - SORTS_MAX)))delete sorts[old]

		localStorage.setItem(PREFS_KEY, JSON.stringify({...raw, sorts}))

	}
	catch
	{

		// storage blocked

	}

}

// api ****************************************************************************************************************

export const driveApi = {

	index: () => api<DriveIndex>('drive/'),
	folder: (id: number, sort: Sort, q: string, page = 1, type = '') => api<FolderContent>(`drive/folders/${id}/?sort=${sort.col}&dir=${sort.dir}&page=${page}&per_page=${PER_PAGE}${q ? `&q=${encodeURIComponent(q)}` : ''}${type ? `&type=${encodeURIComponent(type)}` : ''}`),
	createFolder: (parent: number, name: string) => api<{folder: DriveFolder}>('drive/folders/', {method: 'POST', body: {parent, name}}),
	rename: (item: ItemRef, name: string) => api<{name: string}>(`drive/${item.type}s/${item.id}/`, {method: 'PATCH', body: {name}}),
	pin: (folder: number, pinned: boolean) => api<{pins: Pin[]}>('drive/pins/', {method: 'POST', body: {folder, pinned}}),
	favorite: (file: number, favorite: boolean) => api<{favorite: boolean}>('drive/favorites/', {method: 'POST', body: {file, favorite}}),
	favorites: () => api<{files: DriveFile[]}>('drive/favorites/'),
	recent: () => api<{files: DriveFile[]}>('drive/recent/'),
	search: (q: string, type = '') => api<{folders: DriveFolder[], files: DriveFile[]}>(`drive/search/?q=${encodeURIComponent(q)}${type ? `&type=${encodeURIComponent(type)}` : ''}`),
	tags: () => api<{tags: string[]}>('drive/tags/'),
	setTags: (file: number, tags: string[]) => api<{tags: string[]}>(`drive/files/${file}/tags/`, {method: 'PUT', body: {tags}}),
	file: (id: number) => api<{file: DriveFile, rights: Rights}>(`drive/files/${id}/`),
	fileLinks: (id: number) => api<{links: DriveLink[], allowed: boolean}>(`drive/files/${id}/links/`),
	createLink: (id: number, data: LinkInput) => api<{link: DriveLink}>(`drive/files/${id}/links/`, {method: 'POST', body: data}),
	updateLink: (id: number, data: LinkInput) => api<{link: DriveLink}>(`drive/links/${id}/`, {method: 'PUT', body: data}),
	revokeLink: (id: number) => api<{link: DriveLink}>(`drive/links/${id}/revoke/`, {method: 'POST', body: {}}),
	deleteLink: (id: number) => api(`drive/links/${id}/`, {method: 'DELETE'}),
	links: () => api<{links: DriveLink[]}>('drive/links/'),
	// new content from the editors: text or image (blob), replace or copy, base = hash at the opening (409 reason changed), force = save anyway
	saveContent: (id: number, data: {content?: string, blob?: Blob, extension?: string, quality?: number, mode: 'replace' | 'copy', base?: string, force?: boolean}) => {

		const body = new FormData()
		if(data.content !== undefined)body.append('content', data.content)
		if(data.blob)body.append('file', data.blob, 'image')
		if(data.extension)body.append('extension', data.extension)
		if(data.quality !== undefined)body.append('quality', String(data.quality))
		body.append('mode', data.mode)
		if(data.base)body.append('base', data.base)
		if(data.force)body.append('force', '1')
		return api<{file: DriveFile}>(`drive/files/${id}/content/`, {method: 'POST', body})

	},
	locate: (value: string) => api<{folder: number, file: number}>(`drive/locate/?value=${encodeURIComponent(value)}`),
	move: (items: ItemRef[], target: number, conflict = '', confirm = false) => api<{moved: number, skipped: number}>('drive/move/', {method: 'POST', body: {items, target, conflict, confirm}}),
	copy: (items: ItemRef[], target: number, conflict = '') => api<{copied: number, skipped: number}>('drive/copy/', {method: 'POST', body: {items, target, conflict}}),
	trash: (items: ItemRef[]) => api<{trashed: number}>('drive/trash/', {method: 'POST', body: {items}}),
	trashList: () => api<{folders: DriveFolder[], files: DriveFile[]}>('drive/trash/'),
	restore: (items: ItemRef[]) => api<{restored: number}>('drive/trash/restore/', {method: 'POST', body: {items}}),
	purge: (items: ItemRef[] | 'all') => api<{deleted: number, freed: number}>('drive/trash/delete/', {method: 'POST', body: (items === 'all') ? {all: true} : {items}}),

}

/**
 * reason of an error of the api (access_change, conflict, storage_full), '' otherwise
 */
export function errorReason(e: unknown): string
{

	return (e instanceof ApiException) ? String((e.error as unknown as Record<string, unknown>).reason ?? '') : ''

}

// error of the drive with a translated message for the user
export class DriveError extends Error {}

export const errorMessage = (e: unknown) => (e instanceof ApiException) ? e.error.message : ((e instanceof DriveError) ? e.message : t('An error occurred'))

// uploads ************************************************************************************************************

export type Conflict = 'replace' | 'keep_both' | 'skip'

export type Upload = {
	key: number
	file: File
	folder: number
	name: string
	status: 'waiting' | 'uploading' | 'conflict' | 'done' | 'skipped' | 'error' | 'canceled'
	received: number
	error: string
	duplicate: string
	upload: string
}

// a chunk failing for the network is sent again (resume) after these delays
const RETRY_DELAYS = [1000, 3000, 8000, 15000, 30000]

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * sends a file by chunks: start (name taken = conflict thrown), chunks with resume after a cut, complete
 *
 * @param on progress of the upload (received bytes, upload id)
 * @param canceled true when the user canceled
 */
export async function sendFile(item: Upload, conflict: Conflict | '', on: (patch: Partial<Upload>) => void, canceled: () => boolean): Promise<{skipped?: boolean, duplicate?: string, file?: DriveFile}>
{

	const start = await api<{upload?: string, chunk_size?: number, received?: number, name?: string, skipped?: boolean}>('drive/uploads/', {method: 'POST', body: {folder: item.folder, name: item.name, size: item.file.size, conflict}})
	if(start.skipped)return {skipped: true}

	const upload = String(start.upload)
	const chunk_size = Number(start.chunk_size) || 1048576
	let received = 0
	on({upload, received, name: start.name ?? item.name})

	while(received < item.file.size)
	{

		if(canceled())
		{

			await api(`drive/uploads/${upload}/`, {method: 'DELETE'}).catch(() => {})
			throw new Error('canceled')

		}

		const body = new FormData()
		body.append('offset', String(received))
		body.append('chunk', item.file.slice(received, received + chunk_size), 'chunk')

		let done = false
		for(let attempt = 0; !done; attempt++)
		{

			try
			{

				received = (await api<{received: number}>(`drive/uploads/${upload}/chunk/`, {method: 'POST', body})).received
				done = true

			}
			catch(e)
			{

				// offset out of step (a chunk arrived twice): go on from what the server has
				if(e instanceof ApiException && e.error.code === 409)
				{

					received = Number((e.error as unknown as Record<string, unknown>).received ?? received)
					done = true

				}
				else if(e instanceof ApiException && e.error.code !== 0 && e.error.code < 500)throw e
				else
				{

					// network cut or server error: wait, ask what arrived, send the rest
					if(attempt >= RETRY_DELAYS.length || canceled())throw e
					await sleep(RETRY_DELAYS[attempt])
					received = await api<{received: number}>(`drive/uploads/${upload}/`).then((s) => s.received).catch(() => received)
					done = true

				}

			}

		}

		on({received})

	}

	const end = await api<{file: DriveFile, duplicate: string}>(`drive/uploads/${upload}/complete/`, {method: 'POST', body: {}})
	return {file: end.file, duplicate: end.duplicate}

}
