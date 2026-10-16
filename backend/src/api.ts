// api client: json body, csrf header, session cookie (same origin)

export type User = {
	id: number
	login: string
	email: string
	firstname: string
	lastname: string
	language: string
	group_id: number
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
}
