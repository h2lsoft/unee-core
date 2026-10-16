import { Typography } from '@mui/material'
import { useAuth } from '../auth'
import { PageHeader } from '../components/BackendLayout'
import { useDocumentTitle } from '../components/AuthLayout'
import { t } from '../i18n'

// placeholder until the widgets
export default function Dashboard()
{

	const {user} = useAuth()
	useDocumentTitle(t('Dashboard'))

	return (
		<>
			<PageHeader title={t('Welcome, [NAME]', {NAME: user?.firstname || user?.login || ''})} />
			<Typography sx={{fontSize: 14, color: 'text.secondary'}}>{t('Your modules are in the menu.')}</Typography>
		</>
	)

}
