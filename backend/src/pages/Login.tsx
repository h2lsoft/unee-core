import { useEffect, useState, type ComponentProps, type FormEvent } from 'react'
import { Link as RouterLink, Navigate, useNavigate } from 'react-router'
import { Alert, Box, Button, CircularProgress, FormHelperText, IconButton, InputAdornment, InputLabel, Link, OutlinedInput, Typography } from '@mui/material'
import { VisibilityOutlined, VisibilityOffOutlined } from '@mui/icons-material'
import { api, ApiException, type LoginConfig } from '../api'
import { useAuth } from '../auth'
import LockIllustration from '../components/LockIllustration'

const DEFAULT_LOGO = `${import.meta.env.BASE_URL}logo.svg`

// label above the input (corporate form), errors under it
function Field({id, label, errors, ...input}: {id: string, label: string, errors?: string[]} & Omit<ComponentProps<typeof OutlinedInput>, 'error'>)
{

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}>
			<InputLabel htmlFor={id} sx={{fontSize: 13, fontWeight: 500, color: '#3a3f55'}}>{label}</InputLabel>
			<OutlinedInput id={id} fullWidth error={!!errors} {...input} />
			{errors && <FormHelperText error sx={{m: 0}}>{errors.join(', ')}</FormHelperText>}
		</Box>
	)

}

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

	const logo = config?.logo || DEFAULT_LOGO
	const is_login_key = (config?.login_key === 'login')

	return (
		<Box sx={{minHeight: '100vh', display: 'flex', bgcolor: '#fff', color: 'text.primary'}}>

			{/* form */}
			<Box sx={{flex: '1 1 0', minWidth: 0, display: 'flex', flexDirection: 'column', px: {xs: 4, md: 3}, py: {xs: 3, md: 2.5}}}>
				{/* desktop: logo in the corner */}
				<Box component="img" src={logo} alt="logo" sx={{display: {xs: 'none', md: 'block'}, height: 26, width: 'auto', alignSelf: 'flex-start'}} />

				<Box sx={{flexGrow: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', py: {md: 6}}}>
					<Box component="form" onSubmit={onSubmit} noValidate sx={{width: '100%', maxWidth: 360, display: 'flex', flexDirection: 'column', gap: {xs: 3, md: 3.5}}}>
						{/* mobile: logo centered above the title */}
						<Box sx={{display: {xs: 'flex', md: 'none'}, justifyContent: 'center', pb: 1}}>
							<Box component="img" src={logo} alt="logo" sx={{height: 40, width: 'auto'}} />
						</Box>

						<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}>
							<Typography component="h1" sx={{fontSize: {xs: 24, md: 26}, fontWeight: 600, letterSpacing: '-0.01em'}}>Sign in</Typography>
							<Typography sx={{fontSize: 14, color: 'text.secondary'}}>Enter your credentials to access the back office.</Typography>
						</Box>

						{error && <Alert severity="error">{error}</Alert>}

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
							<Field
								id="password"
								label="Password"
								type={show_password ? 'text' : 'password'}
								autoComplete="current-password"
								value={form.password}
								onChange={(e) => setForm({...form, password: e.target.value})}
								errors={fields.password}
								endAdornment={
									<InputAdornment position="end">
										<IconButton size="small" aria-label={show_password ? 'hide password' : 'show password'} onClick={() => setShowPassword(!show_password)} edge="end">
											{show_password ? <VisibilityOffOutlined fontSize="small" /> : <VisibilityOutlined fontSize="small" />}
										</IconButton>
									</InputAdornment>
								}
							/>
						</Box>

						<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75}}>
							<Button type="submit" variant="contained" fullWidth disabled={submitting}>
								{submitting ? <CircularProgress size={20} color="inherit" /> : 'Sign in'}
							</Button>
							<Link component={RouterLink} to="/password/forgot/" underline="hover" sx={{alignSelf: 'flex-end', fontSize: 13, fontWeight: 500}}>Forgot password?</Link>
						</Box>

						{/* maintenance information, globals backend.maintenance.message (plain text) */}
						{config?.maintenance_message && <Alert severity="warning" sx={{whiteSpace: 'pre-line'}}>{config.maintenance_message}</Alert>}
					</Box>
				</Box>
			</Box>

			{/* brand panel, hidden under 900px */}
			<Box sx={{flex: '1 1 0', minWidth: 0, display: {xs: 'none', md: 'flex'}, flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 7, p: 6, bgcolor: '#0e1328', color: '#fff', textAlign: 'center'}}>
				<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.25, maxWidth: 420}}>
					<Typography component="p" sx={{fontSize: 28, fontWeight: 600, letterSpacing: '-0.01em'}}>{config?.login.title ?? 'Welcome to Unee'}</Typography>
					<Typography sx={{fontSize: 15, lineHeight: 1.5, color: '#c4c8db'}}>{config?.login.subtitle ?? 'Sign in to manage your application.'}</Typography>
				</Box>
				{config?.login.image ? <Box component="img" src={config.login.image} alt="" sx={{maxWidth: '70%', maxHeight: 360}} /> : <LockIllustration />}
			</Box>

		</Box>
	)

}
