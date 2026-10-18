// api client: json body, csrf header, session cookie (same origin)

export type User = {
	id: number
	login: string
	email: string
	firstname: string
	lastname: string
	language: string
	group_id: number
	group_name: string
}

export type ApiError = {
	code: number
	message: string
	fields?: Record<string, string[]>
}

export class ApiException extends Error
{

	error: ApiError

	constructor(error: ApiError)
	{

		super(error.message)
		this.error = error

	}

}

// fired on any 401: the auth provider goes back to the login page
export const UNAUTHORIZED_EVENT = 'unee:unauthorized'

// fired after a write in a module (detail = module): the menu reloads when its own modules change
export const DATA_CHANGED_EVENT = 'unee:data-changed'

export function dataChanged(module: string)
{

	window.dispatchEvent(new CustomEvent(DATA_CHANGED_EVENT, {detail: module}))

}

/**
 * call the api, path without /api/ and with a final slash (rule), ex: api<{user: User}>('auth/me/')
 */
export async function api<T>(path: string, options: {method?: string, body?: unknown} = {}): Promise<T>
{

	const has_body = (options.body !== undefined)
	// FormData (uploads) is sent as multipart, the browser sets its boundary
	const is_form = (options.body instanceof FormData)
	const response = await fetch(`/api/${path}`, {
		method: options.method ?? 'GET',
		headers: {
			'X-Requested-With': 'XMLHttpRequest',
			...(has_body && !is_form ? {'Content-Type': 'application/json'} : {}),
		},
		body: is_form ? options.body as FormData : (has_body ? JSON.stringify(options.body) : undefined),
		credentials: 'same-origin',
	})

	if(response.status === 204)return undefined as T

	const data = await response.json().catch(() => null)
	if(!response.ok)
	{

		if(response.status === 401)window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
		throw new ApiException(data?.error ?? {code: response.status, message: response.statusText})

	}

	return data as T

}

// public settings of the login page (GET /api/auth/config/), null = default of the backend
export type LoginConfig = {
	name: string
	login_key: 'email' | 'login'
	logo: string | null
	header_background: string | null
	login: {
		image: string | null
		title: string | null
		subtitle: string | null
	}
	maintenance_message: string | null
	languages: Record<string, string>
}

// menu of the logged user (GET /api/backend/menu/), names translated
export type MenuItem = {
	name: string
	icon: string
	route: string
	url: string | null
	rights: string[]
	hidden: boolean
}

// bookmark of the logged user (GET /api/backend/bookmarks/)
export type Bookmark = {route: string, name: string, icon: string, url: string | null}

export type MenuCategory = {
	name: string
	icon: string
	color: string
	items: MenuItem[]
}

// grid of a module (GET /api/<module>/), see \Component\DataGrid
export type Option = {value: string, label: string}

export type GridColumn = {
	name: string
	label: string
	type: 'text' | 'badge' | 'boolean' | 'date' | 'datetime' | 'number' | 'icon' | 'color' | 'count' | 'position' | 'chip' | 'tags'
	sortable: boolean
	width?: number
	align?: 'left' | 'right'
	colors?: Record<string, string>
	toggle?: boolean
	color_column?: string
	icon?: string
	class_column?: string
	style_column?: string
	icon_column?: string
	link?: string
	labels?: Record<string, string>
}

// action in the menu of a row (url = link of the backend, {id} replaced) or button of the grid header
export type RowAction = {name: string, label: string, icon: string, right: string, url: string}
export type GridAction = {name: string, label: string, icon: string, right: string, confirm: string, danger: boolean}

export type GridFilter = {
	name: string
	label: string
	type: 'text' | 'number' | 'date' | 'select' | 'boolean'
	operators: string[]
	options: Option[]
}

export type Row = {id: number} & Record<string, unknown>

export type Grid = {
	title: string
	label: string
	rights: string[]
	columns: GridColumn[]
	filters: GridFilter[]
	conditions: {f: string, o: string, v: string}[]
	match: 'all' | 'any'
	q: string
	chips: {name: string, all: boolean, active: string, total: number, options: (Option & {count: number})[]} | null
	search: {placeholder: string} | null
	sort: {column: string, dir: 'asc' | 'desc'}
	rows: Row[]
	pagination: {total: number, page: number, per_page: number, last_page: number}
	per_page_options: number[]
	batch: {edit: string[], actions: {name: string, label: string, icon: string, right: string}[]} | null
	detail: boolean
	row_actions: RowAction[]
	grid_actions: GridAction[]
	mobile: {title: string, subtitle: string}
	position: string | null
}

// saved filter of a grid (GET /api/backend/filters/?module=), personal or shared with the group
export type SavedFilter = {
	id: number
	name: string
	shared: boolean
	mine: boolean
	match: 'all' | 'any'
	conditions: {f: string, o: string, v: string}[]
}

// form of a module (GET /api/<module>/form/ and /api/<module>/<id>/), see \Component\Form
export type FormField = {
	name: string
	type: 'text' | 'email' | 'password' | 'textarea' | 'html' | 'number' | 'integer' | 'date' | 'datetime' | 'boolean' | 'select' | 'icon' | 'color' | 'tags' | 'code' | 'radio'
	label: string
	required: boolean
	width: number
	help?: string
	placeholder?: string
	max_length?: number
	rows?: number
	options?: Option[]
	readonly?: boolean
	autocomplete?: boolean
	free?: boolean
	suggestions?: string[]
	show_if?: {field: string, values: string[]}
	tab?: string
	language?: string
	ucfirst?: boolean
	lowercase?: boolean
	swatch?: boolean
	snippets?: {label: string, group: string, content: string}[]
}

// tab of a form (flag: country code) and button of the form on a record (POST /api/<module>/<id>/action/<name>/)
export type FormTab = {key: string, label: string, flag: string}
export type FormAction = {name: string, label: string, icon: string, right: string}

export type FormPayload = {
	form: {fields: FormField[], tabs: FormTab[], actions: FormAction[]}
	record: Record<string, string | string[]>
	versions?: number
}

// history of a record (plugin with versioning): GET /api/<module>/<id>/versions/
export type Version = {id: number, date: string, author: string, created: boolean, changes: {field: string, label: string, before: string, after: string}[]}

// read only view of a record (GET /api/<module>/<id>/detail/)
export type DetailField = {label: string, value: string, type?: 'text' | 'badge' | 'code', color?: string, language?: string, wide?: boolean}
export type DetailPayload = {title: string, fields: DetailField[]}

// rights of a group (GET /api/right/?group=)
export type RightsPayload = {
	title: string
	groups: {id: number, name: string}[]
	group: number
	menus: {name: string, icon: string, color: string, plugins: {id: number, name: string, icon: string, actions: string[]}[]}[]
	rights: string[]
	can_edit: boolean
}
