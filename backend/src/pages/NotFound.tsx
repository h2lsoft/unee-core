import { Link as RouterLink } from 'react-router'
import { Box, Button, Typography } from '@mui/material'
import { useDocumentTitle } from '../components/AuthLayout'
import { t } from '../i18n'

export default function NotFound()
{

	useDocumentTitle(t('Page not found'))

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', alignItems: 'center', textAlign: 'center', gap: 1.5, py: {xs: 8, md: 12}}}>
			<Typography sx={{fontSize: 56, fontWeight: 600, color: 'var(--u-accent-soft)', lineHeight: 1}}>404</Typography>
			<Typography component="h1" sx={{fontSize: 20, fontWeight: 600}}>{t('Page not found')}</Typography>
			<Typography sx={{fontSize: 14, color: 'text.secondary', mb: 1}}>{t('This page does not exist or you do not have access to it.')}</Typography>
			<Button component={RouterLink} to="/" variant="contained">{t('Back to dashboard')}</Button>
		</Box>
	)

}
