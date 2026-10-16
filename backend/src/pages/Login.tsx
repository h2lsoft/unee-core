import { useEffect, useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { Alert, Box, Button, CircularProgress, IconButton, InputAdornment, TextField, Typography } from '@mui/material'
import { Visibility, VisibilityOff } from '@mui/icons-material'
import { api, ApiException, type LoginConfig } from '../api'
import { useAuth } from '../auth'
import LockIllustration from '../components/LockIllustration'

const DEFAULT_LOGO = `${import.meta.env.BASE_URL}logo.svg`

export default function Login()
{

	const {user, login} = useAuth()
	const navigate = useNavigate()
	const [config, setConfig] = useState<LoginConfig | null>(null)
	const [form, setForm] = useState({login: '', password: ''})
	const [show_password, setShowPassword] = useState(false)
	const [error, setError] = useState('')
	const [fields, setFields] = useState<Record<string, string[]>>({})
	const [submitting, setSubmitting] = useState(false)

	useEffect(() => {

		api<LoginConfig>('auth/config/').then(setConfig).catch(() => setConfig(null))

	}, [])

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

		}
		finally
		{

			setSubmitting(false)

		}

	}

	const login_label = (config?.login_key === 'login') ? 'Login' : 'Email address'
	const title = config?.login.title ?? 'Welcome to Unee'
	const subtitle = config?.login.subtitle ?? 'Sign in to manage your application.'

	return (
		<Box sx={{minHeight: '100vh', display: 'flex'}}>

			{/* form */}
			<Box sx={{flex: 1, display: 'flex', flexDirection: 'column', p: {xs: 2, sm: 3}}}>
				<Box component="img" src={config?.logo || DEFAULT_LOGO} alt="logo" sx={{height: 40, width: 'auto', alignSelf: 'flex-start'}} />

				<Box sx={{flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', py: 4}}>
					<Box component="form" onSubmit={onSubmit} noValidate sx={{width: '100%', maxWidth: 450, display: 'flex', flexDirection: 'column', gap: 2.5}}>
						<Typography variant="h4" component="h1" sx={{fontWeight: 500}}>Sign in</Typography>

						{error && <Alert severity="error">{error}</Alert>}

						<TextField
							label={login_label}
							type={(config?.login_key === 'login') ? 'text' : 'email'}
							autoComplete="username"
							autoFocus
							fullWidth
							value={form.login}
							onChange={(e) => setForm({...form, login: e.target.value})}
							error={!!fields.login}
							helperText={fields.login?.join(', ')}
						/>

						<TextField
							label="Password"
							type={show_password ? 'text' : 'password'}
							autoComplete="current-password"
							fullWidth
							value={form.password}
							onChange={(e) => setForm({...form, password: e.target.value})}
							error={!!fields.password}
							helperText={fields.password?.join(', ')}
							slotProps={{
								input: {
									endAdornment: (
										<InputAdornment position="end">
											<IconButton aria-label={show_password ? 'hide password' : 'show password'} onClick={() => setShowPassword(!show_password)} edge="end">
												{show_password ? <VisibilityOff /> : <Visibility />}
											</IconButton>
										</InputAdornment>
									),
								},
							}}
						/>

						<Button type="submit" variant="contained" size="large" fullWidth disabled={submitting}>
							{submitting ? <CircularProgress size={26} color="inherit" /> : 'Sign in'}
						</Button>

						{/* information zone, from globals backend.login.notice (plain text) */}
						{config?.login.notice && <Alert severity={config.login.notice_severity} sx={{whiteSpace: 'pre-line'}}>{config.login.notice}</Alert>}
					</Box>
				</Box>
			</Box>

			{/* brand panel, hidden under 900px */}
			<Box
				sx={{
					flex: 1,
					display: {xs: 'none', md: 'flex'},
					flexDirection: 'column',
					alignItems: 'center',
					justifyContent: 'center',
					gap: 6,
					p: 6,
					color: '#fff',
					textAlign: 'center',
					background: 'radial-gradient(circle at 50% 35%, #1d2347 0%, #0b1020 70%)',
				}}
			>
				<Box>
					<Typography variant="h4" component="p" sx={{fontWeight: 600, mb: 1}}>{title}</Typography>
					<Typography sx={{opacity: 0.8}}>{subtitle}</Typography>
				</Box>
				{config?.login.image ? <Box component="img" src={config.login.image} alt="" sx={{maxWidth: '80%', maxHeight: 420}} /> : <LockIllustration />}
			</Box>

		</Box>
	)

}
