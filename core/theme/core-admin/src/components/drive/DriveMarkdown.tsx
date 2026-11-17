import { useMemo } from 'react'
import { Box } from '@mui/material'
import { markdown } from './markdown'

/**
 * markdown of a .md file shown as a page (preview and editor); the html comes from markdown(): source escaped, links filtered
 */
export default function DriveMarkdown({text}: {text: string})
{

	const html = useMemo(() => markdown(text), [text])

	return (
		<Box className="drive-markdown" dangerouslySetInnerHTML={{__html: html}}
			sx={{maxWidth: 860, mx: 'auto', px: 4, py: 3, fontSize: 15, lineHeight: 1.65, color: 'var(--u-text)', overflowWrap: 'anywhere',
				'& h1, & h2, & h3, & h4, & h5, & h6': {lineHeight: 1.3, mt: 3, mb: 1.25, fontWeight: 600},
				'& h1': {fontSize: 26, pb: 1, borderBottom: '1px solid var(--u-border)'}, '& h2': {fontSize: 21, pb: 0.75, borderBottom: '1px solid var(--u-border-soft)'}, '& h3': {fontSize: 17.5}, '& h4, & h5, & h6': {fontSize: 15},
				'& p, & ul, & ol, & blockquote, & pre, & table': {mt: 0, mb: 1.75},
				'& ul, & ol': {pl: 3}, '& li': {mb: 0.5}, '& li input': {mr: 0.75},
				'& a': {color: 'var(--u-accent-text)'},
				'& code': {px: 0.6, py: 0.2, borderRadius: 1, bgcolor: 'var(--u-head)', fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: '0.88em'},
				'& pre': {p: 1.75, borderRadius: 2, bgcolor: 'var(--u-head)', border: '1px solid var(--u-border)', overflowX: 'auto'},
				'& pre code': {p: 0, bgcolor: 'transparent', fontSize: 13},
				'& blockquote': {mx: 0, pl: 2, borderLeft: '3px solid var(--u-border-strong)', color: 'var(--u-text3)'},
				'& hr': {border: 0, borderTop: '1px solid var(--u-border)', my: 3},
				'& table': {borderCollapse: 'collapse', display: 'block', overflowX: 'auto'},
				'& th, & td': {border: '1px solid var(--u-border)', px: 1.25, py: 0.6, textAlign: 'left'}, '& th': {bgcolor: 'var(--u-head)', fontWeight: 600},
				'& img': {maxWidth: '100%'}}} />
	)

}
