import { useEffect, useState, type ReactNode } from 'react'
import { Link as RouterLink } from 'react-router'
import { Alert, Box, Link, Typography } from '@mui/material'
import { ArrowBack } from '@mui/icons-material'
import { api, type LoginConfig } from '../api'
import LockIllustration from './LockIllustration'
import { t } from '../i18n'

const DEFAULT_LOGO = `${import.meta.env.BASE_URL}logo.svg`

/**
 * public settings of the auth pages (logo, texts, maintenance message), null until loaded or when the api fails
 */
export function useLoginConfig(): LoginConfig | null
{

	const [config, setConfig] = useState<LoginConfig | null>(null)

	useEffect(() => {

		api<LoginConfig>('auth/config/').then(setConfig).catch(() => setConfig(null))

	}, [])

	return config

}

// link back to the login page, under the submit button
export function BackToSignIn()
{

	return (
		<Link component={RouterLink} to="/login/" underline="hover" sx={{alignSelf: 'flex-start', display: 'inline-flex', alignItems: 'center', gap: 0.75, fontSize: 13, fontWeight: 500}}>
			<ArrowBack sx={{fontSize: 14}} />{t('Back to sign in')}
		</Link>
	)

}

/**
 * browser title: "<application name> - <page>", the name comes from config `name`
 */
export function useDocumentTitle(page: string, name?: string | null)
{

	useEffect(() => {

		document.title = `${name || 'Unee'} - ${page}`

	}, [page, name])

}

/**
 * layout of the auth pages: form column (logo, title, content, maintenance message) and brand panel (hidden under 900px)
 */
export default function AuthLayout({config, title, subtitle, documentTitle, children}: {config: LoginConfig | null, title: string, subtitle: string, documentTitle: string, children: ReactNode})
{

	const logo = config?.logo || DEFAULT_LOGO
	useDocumentTitle(documentTitle, config?.name)

	return (
		<Box sx={{minHeight: '100vh', display: 'flex', bgcolor: '#fff', color: 'text.primary'}}>

			<Box sx={{flex: '1 1 0', minWidth: 0, display: 'flex', flexDirection: 'column', px: {xs: 4, md: 3}, py: {xs: 3, md: 2.5}}}>
				{/* desktop: logo in the corner */}
				<Box component="img" src={logo} alt="logo" sx={{display: {xs: 'none', md: 'block'}, height: 34, width: 'auto', alignSelf: 'flex-start'}} />

				<Box sx={{flexGrow: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', py: {md: 6}}}>
					<Box sx={{width: '100%', maxWidth: 360, display: 'flex', flexDirection: 'column', gap: {xs: 3, md: 3.5}}}>
						{/* mobile: logo centered above the title */}
						<Box sx={{display: {xs: 'flex', md: 'none'}, justifyContent: 'center', pb: 1}}>
							<Box component="img" src={logo} alt="logo" sx={{height: 40, width: 'auto'}} />
						</Box>

						<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}>
							<Typography component="h1" sx={{fontSize: {xs: 24, md: 26}, fontWeight: 600, letterSpacing: '-0.01em'}}>{title}</Typography>
							<Typography sx={{fontSize: 14, color: 'text.secondary'}}>{subtitle}</Typography>
						</Box>

						{children}

						{/* maintenance information, globals backend.maintenance.message (plain text) */}
						{config?.maintenance_message && <Alert severity="warning" sx={{whiteSpace: 'pre-line'}}>{config.maintenance_message}</Alert>}
					</Box>
				</Box>
			</Box>

			<Box sx={{flex: '1 1 0', minWidth: 0, display: {xs: 'none', md: 'flex'}, flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 7, p: 6, bgcolor: '#0e1328', color: '#fff', textAlign: 'center'}}>
				<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.25, maxWidth: 420}}>
					<Typography component="p" sx={{fontSize: 28, fontWeight: 600, letterSpacing: '-0.01em'}}>{config?.login.title ?? t('Welcome to Unee')}</Typography>
					<Typography sx={{fontSize: 15, lineHeight: 1.5, color: '#c4c8db'}}>{config?.login.subtitle ?? t('Sign in to manage your application.')}</Typography>
				</Box>
				{config?.login.image ? <Box component="img" src={config.login.image} alt="" sx={{maxWidth: '70%', maxHeight: 360}} /> : <LockIllustration />}
			</Box>

		</Box>
	)

}
