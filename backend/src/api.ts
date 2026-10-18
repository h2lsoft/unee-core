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

/**
 * call the api, path without /api/ and with a final slash (rule), ex: api<{user: User}>('auth/me/')
 */
export async function api<T>(path: string, options: {method?: string, body?: unknown} = {}): Promise<T>
{

	const has_body = (options.body !== undefined)
	const response = await fetch(`/api/${path}`, {
		method: options.method ?? 'GET',
		headers: {
			'X-Requested-With': 'XMLHttpRequest',
			...(has_body ? {'Content-Type': 'application/json'} : {}),
		},
		body: has_body ? JSON.stringify(options.body) : undefined,
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
}

export type MenuCategory = {
	name: string
	icon: string
	items: MenuItem[]
}

// grid of a module (GET /api/<module>/), see \Component\DataGrid
export type Option = {value: string, label: string}

export type GridColumn = {
	name: string
	label: string
	type: 'text' | 'badge' | 'boolean' | 'date' | 'datetime' | 'number'
	sortable: boolean
	width?: number
	align?: 'left' | 'right'
	colors?: Record<string, string>
	toggle?: boolean
}

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
	chips: {name: string, active: string, total: number, options: (Option & {count: number})[]} | null
	search: {placeholder: string} | null
	sort: {column: string, dir: 'asc' | 'desc'}
	rows: Row[]
	pagination: {total: number, page: number, per_page: number, last_page: number}
	per_page_options: number[]
	batch: {edit: string[], actions: {name: string, label: string, icon: string, right: string}[]} | null
	mobile: {title: string, subtitle: string}
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
	type: 'text' | 'email' | 'password' | 'textarea' | 'html' | 'number' | 'integer' | 'date' | 'datetime' | 'boolean' | 'select'
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
}

export type FormPayload = {
	form: {fields: FormField[]}
	record: Record<string, string>
}
