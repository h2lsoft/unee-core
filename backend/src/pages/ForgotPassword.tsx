import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router'
import { Alert, Box, Button, CircularProgress } from '@mui/material'
import { api, ApiException } from '../api'
import { useAuth } from '../auth'
import AuthLayout, { BackToSignIn, useLoginConfig } from '../components/AuthLayout'
import { Field } from '../components/Field'

export default function ForgotPassword()
{

	const {user} = useAuth()
	const config = useLoginConfig()
	const [login, setLogin] = useState('')
	const [error, setError] = useState('')
	const [sent, setSent] = useState('')
	const [fields, setFields] = useState<Record<string, string[]>>({})
	const [submitting, setSubmitting] = useState(false)

	if(user)return <Navigate to="/" replace />

	const onSubmit = async (e: FormEvent) => {

		e.preventDefault()
		setSubmitting(true)
		setError('')
		setSent('')
		setFields({})

		try
		{

			// same answer whether the account exists or not
			const data = await api<{message: string}>('auth/password/forgot/', {method: 'POST', body: {login}})
			setSent(data.message)

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
		<AuthLayout config={config} title="Forgot password" documentTitle="Forgot Password" subtitle="Enter your email address and we will send you a recovery link.">
			<Box component="form" onSubmit={onSubmit} noValidate sx={{display: 'flex', flexDirection: 'column', gap: {xs: 3, md: 3.5}}}>
				{error && <Alert severity="error">{error}</Alert>}
				{sent && <Alert severity="success">{sent}</Alert>}

				<Field
					id="login"
					label={is_login_key ? 'Login' : 'Email address'}
					type={is_login_key ? 'text' : 'email'}
					autoComplete="username"
					placeholder={is_login_key ? '' : 'name@company.com'}
					autoFocus
					value={login}
					onChange={(e) => setLogin(e.target.value)}
					errors={fields.login}
				/>

				<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75}}>
					<Button type="submit" variant="contained" fullWidth disabled={submitting}>
						{submitting ? <CircularProgress size={20} color="inherit" /> : 'Send me recovery link'}
					</Button>
					<BackToSignIn />
				</Box>
			</Box>
		</AuthLayout>
	)

}
