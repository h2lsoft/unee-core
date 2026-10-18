import { IconButton } from '@mui/material'
import { BookmarkBorderOutlined, BookmarkOutlined } from '@mui/icons-material'
import { ApiException } from '../api'
import { t } from '../i18n'
import { useMenu } from './BackendLayout'
import { useToast } from './Toast'

/**
 * icon next to the title of a module: adds the module to the bookmarks of the user or removes it
 */
export default function BookmarkButton({module}: {module: string})
{

	const toast = useToast()
	const {bookmarks, toggleBookmark} = useMenu()
	const bookmarked = bookmarks.some((b) => b.route === module)
	const label = bookmarked ? t('Remove from bookmarks') : t('Add to bookmarks')

	const toggle = () => toggleBookmark(module)
		.then(() => toast(bookmarked ? t('Removed from bookmarks') : t('Added to bookmarks')))
		.catch((e) => toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error'))

	return (
		<IconButton aria-label={label} title={label} aria-pressed={bookmarked} onClick={toggle} sx={{width: 34, height: 34, color: 'primary.main'}}>
			{bookmarked ? <BookmarkOutlined sx={{fontSize: 22}} /> : <BookmarkBorderOutlined sx={{fontSize: 22}} />}
		</IconButton>
	)

}
