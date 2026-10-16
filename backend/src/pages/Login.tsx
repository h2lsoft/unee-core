import { useState, type FormEvent } from 'react'
import { Link as RouterLink, Navigate, useLocation, useNavigate } from 'react-router'
import { Alert, Box, Button, CircularProgress, Link } from '@mui/material'
import { ApiException } from '../api'
import { useAuth } from '../auth'
import AuthLayout, { useLoginConfig } from '../components/AuthLayout'
import { Field, PasswordField } from '../components/Field'

export default function Login()
{

	const {user, login} = useAuth()
	const navigate = useNavigate()
	const location = useLocation()
	const config = useLoginConfig()
	const [form, setForm] = useState({login: '', password: ''})
	const [error, setError] = useState('')
	const [fields, setFields] = useState<Record<string, string[]>>({})
	const [submitting, setSubmitting] = useState(false)

	// message from another page, ex: password changed
	const notice = (location.state as {notice?: string} | null)?.notice ?? ''

	if(user)return <Navigate to="/" replace />

	const onSubmit = async (e: FormEvent) => {

		e.preventDefault()
		setSubmitting(true)
		setError('')
		setFields({})

		try
		{

			await login(form.login, form.password)
			navigate('/', {replace: true})

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			setError(api_error?.message ?? 'network error, please retry')
			setFields(api_error?.fields ?? {})
			if(api_error?.fields)setError('')

		}
		finally
		{

			setSubmitting(false)

		}

	}

	const is_login_key = (config?.login_key === 'login')

	return (
		<AuthLayout config={config} title="Sign in" subtitle="Enter your credentials to access the back office.">
			<Box component="form" onSubmit={onSubmit} noValidate sx={{display: 'flex', flexDirection: 'column', gap: {xs: 3, md: 3.5}}}>
				{error && <Alert severity="error">{error}</Alert>}
				{!error && notice && <Alert severity="success">{notice}</Alert>}

				<Box sx={{display: 'flex', flexDirection: 'column', gap: 2.25}}>
					<Field
						id="login"
						label={is_login_key ? 'Login' : 'Email address'}
						type={is_login_key ? 'text' : 'email'}
						autoComplete="username"
						placeholder={is_login_key ? '' : 'name@company.com'}
						autoFocus
						value={form.login}
						onChange={(e) => setForm({...form, login: e.target.value})}
						errors={fields.login}
					/>
					<PasswordField
						id="password"
						label="Password"
						autoComplete="current-password"
						value={form.password}
						onChange={(e) => setForm({...form, password: e.target.value})}
						errors={fields.password}
					/>
				</Box>

				<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75}}>
					<Button type="submit" variant="contained" fullWidth disabled={submitting}>
						{submitting ? <CircularProgress size={20} color="inherit" /> : 'Sign in'}
					</Button>
					<Link component={RouterLink} to="/password/forgot/" underline="hover" sx={{alignSelf: 'flex-end', fontSize: 13, fontWeight: 500}}>Forgot password?</Link>
				</Box>
			</Box>
		</AuthLayout>
	)

}
