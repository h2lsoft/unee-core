import { useEffect, useState, type ReactNode } from 'react'
import { Link as RouterLink } from 'react-router'
import { Alert, Box, Link, Typography } from '@mui/material'
import { useColorScheme } from '@mui/material/styles'
import { ArrowBack } from '@mui/icons-material'
import { api, type LoginConfig } from '../api'
import LockIllustration from './LockIllustration'
import { t } from '../i18n'
import { BASE } from '../base'

/**
 * logo of the global backend.logo, otherwise the unee logo (white text in dark mode)
 */
export function useLogo(config: LoginConfig | null):string
{

	const {colorScheme} = useColorScheme()
	return config?.logo || `${BASE}${(colorScheme === 'dark') ? 'logo-dark.svg' : 'logo.svg'}`

}

/**
 * public settings of the auth pages (logo, texts, maintenance message), null until loaded or when the api fails
 */
// asked once per page load: every page title uses it
let config_request: Promise<LoginConfig | null> | null = null

export function useLoginConfig(): LoginConfig | null
{

	const [config, setConfig] = useState<LoginConfig | null>(null)

	useEffect(() => {

		config_request ??= api<LoginConfig>('auth/config/').then((c) => {

			// globals backend.stylesheets: loaded once, at the end of the head to override the backend
			for(const href of c.stylesheets ?? [])
			{

				const link = document.createElement('link')
				link.rel = 'stylesheet'
				link.href = href
				document.head.appendChild(link)

			}
			return c

		}).catch(() => null)
		config_request.then(setConfig)

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

	const config = useLoginConfig()
	const site = name || config?.name || 'Unee'

	useEffect(() => {

		document.title = page ? `${page} - ${site}` : site

	}, [page, site])

}

/**
 * layout of the auth pages: form column (logo, title, content, maintenance message) and brand panel (hidden under 900px)
 */
export default function AuthLayout({config, title, subtitle, documentTitle, children}: {config: LoginConfig | null, title: string, subtitle: string, documentTitle: string, children: ReactNode})
{

	const logo = useLogo(config)
	useDocumentTitle(documentTitle, config?.name)

	return (
		<Box sx={{minHeight: '100vh', display: 'flex', bgcolor: 'var(--u-paper)', color: 'text.primary'}}>

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
