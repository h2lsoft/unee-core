import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, UNAUTHORIZED_EVENT, type User } from './api'

type Auth = {
	user: User | null
	loading: boolean
	login: (login: string, password: string) => Promise<void>
	logout: () => Promise<void>
}

const AuthContext = createContext<Auth | null>(null)

/**
 * logged user, loaded from /api/auth/me/ at startup
 */
export function AuthProvider({children}: {children: ReactNode})
{

	const [user, setUser] = useState<User | null>(null)
	const [loading, setLoading] = useState(true)

	useEffect(() => {

		api<{user: User}>('auth/me/')
			.then((data) => setUser(data.user))
			.catch(() => setUser(null))
			.finally(() => setLoading(false))

		// session expired or account disabled on any call
		const onUnauthorized = () => setUser(null)
		window.addEventListener(UNAUTHORIZED_EVENT, onUnauthorized)
		return () => window.removeEventListener(UNAUTHORIZED_EVENT, onUnauthorized)

	}, [])

	const login = async (login: string, password: string) => {

		const data = await api<{user: User}>('auth/login/', {method: 'POST', body: {login, password}})
		setUser(data.user)

	}

	const logout = async () => {

		await api('auth/logout/', {method: 'POST'}).catch(() => null)
		setUser(null)

	}

	return <AuthContext.Provider value={{user, loading, login, logout}}>{children}</AuthContext.Provider>

}

export function useAuth(): Auth
{

	const auth = useContext(AuthContext)
	if(!auth)throw new Error('useAuth must be used inside AuthProvider')
	return auth

}
