import { useEffect, useState, type FormEvent } from 'react'
import { Link as RouterLink, Navigate, useLocation, useNavigate } from 'react-router'
import { Alert, Box, Button, ButtonBase, Checkbox, CircularProgress, FormControlLabel, Link, Typography } from '@mui/material'
import QRCode from 'qrcode'
import { ApiException } from '../api'
import { useAuth, type TwoFactorStep } from '../auth'
import AuthLayout, { useLoginConfig } from '../components/AuthLayout'
import CodeInput from '../components/CodeInput'
import { Field, PasswordField } from '../components/Field'
import { t } from '../i18n'

export default function Login()
{

	const {user, login, verifyTwoFactor, resendTwoFactor} = useAuth()
	const navigate = useNavigate()
	const location = useLocation()
	const config = useLoginConfig()
	const [form, setForm] = useState({login: '', password: ''})
	const [error, setError] = useState('')
	const [fields, setFields] = useState<Record<string, string[]>>({})
	const [submitting, setSubmitting] = useState(false)

	// second factor (\Core\TwoFactor): code of the authenticator app (or app to set up first), of a sms or of an email
	const [step, setStep] = useState<TwoFactorStep | null>(null)
	const [code, setCode] = useState('')
	const [trust, setTrust] = useState(true)
	const [qr, setQr] = useState('')
	const [copied, setCopied] = useState(false)
	const [resendIn, setResendIn] = useState(0)
	const [resent, setResent] = useState(false)

	// countdown before a new code can be asked (sms, email)
	useEffect(() => {

		if(resendIn <= 0)return
		const timer = window.setTimeout(() => setResendIn(resendIn - 1), 1000)
		return () => window.clearTimeout(timer)

	}, [resendIn])

	useEffect(() => {

		setQr('')
		if(step?.uri)QRCode.toDataURL(step.uri, {margin: 1, width: 444}).then(setQr).catch(() => setQr(''))

	}, [step?.uri])

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

			const next = await login(form.login, form.password)
			if(next)
			{

				setStep(next)
				setCode('')
				setResent(false)
				setResendIn(next.resend_in ?? 0)
				return

			}

			navigate('/', {replace: true})

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			setError(api_error?.message ?? t('network error, please retry'))
			setFields(api_error?.fields ?? {})
			if(api_error?.fields)setError('')

		}
		finally
		{

			setSubmitting(false)

		}

	}

	const submitCode = async (value: string) => {

		if(value.length !== 6 || submitting)return
		setSubmitting(true)
		setError('')
		setResent(false)

		try
		{

			await verifyTwoFactor(value, trust)
			navigate('/', {replace: true})

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			setError(api_error?.message ?? t('network error, please retry'))
			setCode('')

			// pending logon expired or too many wrong codes: password again
			if(api_error?.code === 440)
			{

				setStep(null)
				setForm({...form, password: ''})

			}

		}
		finally
		{

			setSubmitting(false)

		}

	}

	const backToSignIn = () => {

		setStep(null)
		setError('')
		setForm({...form, password: ''})

	}

	// new code by sms or email: the previous one is no longer valid
	const resendCode = async () => {

		if(submitting || resendIn > 0)return
		setSubmitting(true)
		setError('')
		setResent(false)

		try
		{

			const next = await resendTwoFactor()
			setStep(next)
			setCode('')
			setResendIn(next.resend_in)
			setResent(true)

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			setError(api_error?.message ?? t('network error, please retry'))

			// expired, throttled or code not sent: password again
			if(api_error?.code === 440)
			{

				setStep(null)
				setForm({...form, password: ''})

			}
			else if(api_error?.code === 422 && step)setStep({...step, resends_left: 0})

		}
		finally
		{

			setSubmitting(false)

		}

	}

	const copyKey = () => {

		navigator.clipboard?.writeText(step?.secret ?? '').then(() => setCopied(true)).catch(() => null)

	}

	if(step)
	{

		const setup = (step.two_factor === 'setup')
		const by_code = (step.method === 'sms' || step.method === 'email')

		// masked destination in bold inside the translated sentence
		const sentTo = (text: string) => {

			const [before, after] = text.split('[DESTINATION]')
			return <>{before}<Box component="strong" sx={{color: 'text.primary', fontWeight: 600}}>{step.destination}</Box>{after}</>

		}

		const subtitle = (setup) ? t('It is required to access the back office. Scan this QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password...).')
			: (step.method === 'sms') ? sentTo(t('Enter the 6-digit code sent by SMS to [DESTINATION].'))
			: (step.method === 'email') ? sentTo(t('Enter the 6-digit code sent to [DESTINATION]. Check your spam folder.'))
			: t('Enter the 6-digit code shown in your authenticator app.')

		const countdown = `${Math.floor(resendIn / 60)}:${String(resendIn % 60).padStart(2, '0')}`
		const resend = (by_code && step.resends_left > 0) ? (
			<ButtonBase onClick={resendCode} disabled={resendIn > 0 || submitting} sx={{fontFamily: 'inherit', fontSize: 13, fontWeight: 500, color: (resendIn > 0) ? 'var(--u-faint)' : 'primary.main', '&:hover': {textDecoration: (resendIn > 0) ? 'none' : 'underline'}}}>
				{(resendIn > 0) ? t('Resend code in [TIME]', {TIME: countdown}) : t('Resend code')}
			</ButtonBase>
		) : null
		const lost = (!setup && step.method !== 'email') ? <Typography sx={{fontSize: 12.5, color: 'var(--u-muted)'}}>{t('Lost your phone? Contact your administrator.')}</Typography> : null

		return (
			<AuthLayout config={config} title={setup ? t('Set up two-step verification') : t('Two-step verification')} documentTitle={t('Two-step verification')} subtitle={subtitle}>
				<Box component="form" onSubmit={(e: FormEvent) => { e.preventDefault(); submitCode(code) }} noValidate sx={{display: 'flex', flexDirection: 'column', gap: {xs: 3, md: 3.5}}}>
					{error && <Alert severity="error">{error}</Alert>}
					{!error && resent && <Alert severity="success">{t('A new code has been sent.')}</Alert>}

					{setup && (
						<Box sx={{display: 'flex', flexDirection: 'column', gap: 2, alignItems: 'center', textAlign: 'center', p: 2.25, bgcolor: 'var(--u-widget)', borderRadius: '12px'}}>
							<Box sx={{width: 222, height: 222, flexShrink: 0, p: 0.5, boxSizing: 'border-box', bgcolor: '#fff', borderRadius: '8px', display: 'flex', alignItems: 'center', justifyContent: 'center'}}>
								{qr ? <Box component="img" src={qr} alt="QR code" sx={{width: '100%', height: '100%'}} /> : <CircularProgress size={22} />}
							</Box>
							<Box sx={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 1, minWidth: 0, maxWidth: '100%'}}>
								<Typography sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Cannot scan? Enter this key:')}</Typography>
								<Box component="code" sx={{fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 13, lineHeight: 1.6, wordBreak: 'break-all'}}>{(step.secret ?? '').replace(/(.{4})/g, '$1 ').trim()}</Box>
								<Button type="button" size="small" variant="outlined" onClick={copyKey} sx={{height: 30, fontSize: 12.5, bgcolor: 'var(--u-paper)'}}>{copied ? t('Key copied') : t('Copy the key')}</Button>
							</Box>
						</Box>
					)}

					<Box sx={{display: 'flex', flexDirection: 'column', gap: 1}}>
						<Typography id="two-factor-label" sx={{fontSize: 13, fontWeight: 500, color: 'var(--u-text2)'}}>{setup ? t('Code shown by the app') : t('Code')}</Typography>
						<CodeInput value={code} onChange={setCode} onComplete={submitCode} disabled={submitting} autoFocus={!setup} labelledBy="two-factor-label" />
					</Box>

					{step.trust_days > 0 && (
						<FormControlLabel control={<Checkbox size="small" checked={trust} onChange={(e) => setTrust(e.target.checked)} />} label={t('Trust this device for [DAYS] days', {DAYS: step.trust_days})} sx={{m: 0, gap: 0.75, '& .MuiFormControlLabel-label': {fontSize: 13.5}}} />
					)}

					<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75}}>
						<Button type="submit" variant="contained" fullWidth disabled={submitting || code.length !== 6}>
							{submitting ? <CircularProgress size={20} color="inherit" /> : (setup ? t('Activate') : t('Verify'))}
						</Button>
						<Box sx={{display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', alignItems: 'center', gap: 1}}>
							{(by_code) ? resend : lost}
							<ButtonBase onClick={backToSignIn} sx={{ml: 'auto', fontFamily: 'inherit', fontSize: 13, fontWeight: 500, color: 'var(--u-text3)', '&:hover': {textDecoration: 'underline'}}}>{t('Back to sign in')}</ButtonBase>
						</Box>
						{by_code && lost}
					</Box>
				</Box>
			</AuthLayout>
		)

	}

	const is_login_key = (config?.login_key === 'login')

	return (
		<AuthLayout config={config} title={t('Sign in')} documentTitle={t('Sign In')} subtitle={t('Enter your credentials to access the back office.')}>
			<Box component="form" onSubmit={onSubmit} noValidate sx={{display: 'flex', flexDirection: 'column', gap: {xs: 3, md: 3.5}}}>
				{error && <Alert severity="error">{error}</Alert>}
				{!error && notice && <Alert severity="success">{notice}</Alert>}

				<Box sx={{display: 'flex', flexDirection: 'column', gap: 2.25}}>
					<Field
						id="login"
						label={is_login_key ? t('Login') : t('Email address')}
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
						label={t('Password')}
						autoComplete="current-password"
						value={form.password}
						onChange={(e) => setForm({...form, password: e.target.value})}
						errors={fields.password}
					/>
				</Box>

				<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75}}>
					<Button type="submit" variant="contained" fullWidth disabled={submitting}>
						{submitting ? <CircularProgress size={20} color="inherit" /> : t('Sign in')}
					</Button>
					<Link component={RouterLink} to="/password/forgot/" underline="hover" sx={{alignSelf: 'flex-end', fontSize: 13, fontWeight: 500}}>{t('Forgot password?')}</Link>
				</Box>
			</Box>
		</AuthLayout>
	)

}
