import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router'
import { Alert, Box, Button, CircularProgress } from '@mui/material'
import { api, ApiException } from '../api'
import { useAuth } from '../auth'
import AuthLayout, { BackToSignIn, useLoginConfig } from '../components/AuthLayout'
import { PasswordField } from '../components/Field'

const INVALID_LINK = 'This link is invalid or has expired, ask for a new one.'

export default function ResetPassword()
{

	const {user} = useAuth()
	const navigate = useNavigate()
	const config = useLoginConfig()
	const [search] = useSearchParams()
	const token = search.get('token') ?? ''
	const [form, setForm] = useState({password: '', password_confirmation: ''})
	const [error, setError] = useState(/^[a-f0-9]{64}$/.test(token) ? '' : INVALID_LINK)
	const [fields, setFields] = useState<Record<string, string[]>>({})
	const [submitting, setSubmitting] = useState(false)

	if(user)return <Navigate to="/" replace />

	const onSubmit = async (e: FormEvent) => {

		e.preventDefault()
		setSubmitting(true)
		setError('')
		setFields({})

		try
		{

			const data = await api<{message: string}>('auth/password/reset/', {method: 'POST', body: {token, ...form}})
			navigate('/login/', {replace: true, state: {notice: data.message}})

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			setError((api_error?.code === 400) ? INVALID_LINK : (api_error?.message ?? 'network error, please retry'))
			setFields(api_error?.fields ?? {})
			if(api_error?.fields)setError('')

		}
		finally
		{

			setSubmitting(false)

		}

	}

	return (
		<AuthLayout config={config} title="Reset password" subtitle="Choose a new password for your account.">
			<Box component="form" onSubmit={onSubmit} noValidate sx={{display: 'flex', flexDirection: 'column', gap: {xs: 3, md: 3.5}}}>
				{error && <Alert severity="error">{error}</Alert>}

				<Box sx={{display: 'flex', flexDirection: 'column', gap: 2.25}}>
					<PasswordField
						id="password"
						label="New password"
						autoComplete="new-password"
						autoFocus
						value={form.password}
						onChange={(e) => setForm({...form, password: e.target.value})}
						errors={fields.password}
					/>
					<PasswordField
						id="password-confirmation"
						label="Confirm password"
						autoComplete="new-password"
						value={form.password_confirmation}
						onChange={(e) => setForm({...form, password_confirmation: e.target.value})}
						errors={fields.password_confirmation}
					/>
				</Box>

				<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75}}>
					<Button type="submit" variant="contained" fullWidth disabled={submitting}>
						{submitting ? <CircularProgress size={20} color="inherit" /> : 'Reset password'}
					</Button>
					<BackToSignIn />
				</Box>
			</Box>
		</AuthLayout>
	)

}
