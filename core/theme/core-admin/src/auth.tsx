import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, UNAUTHORIZED_EVENT, type User } from './api'
import { setLanguage } from './i18n'

// logon waiting for its second factor (\Core\TwoFactor): code of the authenticator app (or app to set up with its secret), of a sms or of an email
// destination: masked mobile or email, resend_in: seconds before a new code can be asked, resends_left: new codes left
export type TwoFactorMethod = 'authenticator' | 'sms' | 'email'
export type TwoFactorStep = {two_factor: 'verify' | 'setup', method: TwoFactorMethod, destination: string, resend_in: number, resends_left: number, trust_days: number, secret?: string, uri?: string}

type Auth = {
	user: User | null
	loading: boolean
	login: (login: string, password: string) => Promise<TwoFactorStep | null>
	verifyTwoFactor: (code: string, trust: boolean) => Promise<void>
	resendTwoFactor: () => Promise<TwoFactorStep>
	logout: () => Promise<void>
	changeLanguage: (language: string) => Promise<void>
	reload: () => Promise<void>
}

const AuthContext = createContext<Auth | null>(null)
const BROWSER_LANGUAGE = (navigator.language || 'en').slice(0, 2).toLowerCase()

/**
 * logged user, loaded from /api/auth/me/ at startup, its language drives the interface
 */
export function AuthProvider({children}: {children: ReactNode})
{

	const [user, setUserState] = useState<User | null>(null)
	const [loading, setLoading] = useState(true)

	// language set before the render that uses it
	const setUser = (value: User | null) => {

		setLanguage(value?.language || BROWSER_LANGUAGE)
		setUserState(value)

	}

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

		const data = await api<{user?: User} & Partial<TwoFactorStep>>('auth/login/', {method: 'POST', body: {login, password}})
		if(data.two_factor)return data as TwoFactorStep

		setUser(data.user ?? null)
		return null

	}

	const verifyTwoFactor = async (code: string, trust: boolean) => {

		const data = await api<{user: User}>('auth/2fa/', {method: 'POST', body: {code, trust}})
		setUser(data.user)

	}

	// new code by sms or email of the pending logon
	const resendTwoFactor = () => api<TwoFactorStep>('auth/2fa/resend/', {method: 'POST'})

	const logout = async () => {

		await api('auth/logout/', {method: 'POST'}).catch(() => null)
		setUser(null)

	}

	const changeLanguage = async (language: string) => {

		const data = await api<{user: User}>('auth/me/', {method: 'PATCH', body: {language}})
		setUser(data.user)

	}

	// account changed elsewhere (my profile)
	const reload = async () => {

		const data = await api<{user: User}>('auth/me/')
		setUser(data.user)

	}

	return <AuthContext.Provider value={{user, loading, login, verifyTwoFactor, resendTwoFactor, logout, changeLanguage, reload}}>{children}</AuthContext.Provider>

}

export function useAuth(): Auth
{

	const auth = useContext(AuthContext)
	if(!auth)throw new Error('useAuth must be used inside AuthProvider')
	return auth

}
