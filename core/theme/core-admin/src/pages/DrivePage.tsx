import { Box, Typography } from '@mui/material'
import { t } from '../i18n'
import BookmarkButton from '../components/BookmarkButton'
import { MenuIcon } from '../components/icons'
import FileManager from '../components/drive/FileManager'

/**
 * module drive: title of the module and the File Manager on the height of the screen
 */
export default function DrivePage({icon, name}: {icon: string, name: string})
{

	return (
		<>
			{/* half the gap of the page below the title */}
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, mb: {xs: -1, md: -1.25}}}>
				<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: 'var(--u-accent-bg)', color: 'primary.main'}}><MenuIcon name={icon} /></Box>
				<Typography component="h1" sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>{name || t('Drive')}</Typography>
				<BookmarkButton module="drive" />
			</Box>
			<Box sx={{height: {xs: 'calc(100vh - 150px)', md: 'calc(100vh - 175px)'}, minHeight: 480}}>
				<FileManager />
			</Box>
		</>
	)

}
