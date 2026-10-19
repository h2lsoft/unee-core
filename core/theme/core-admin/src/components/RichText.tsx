import { lazy, Suspense, type ComponentProps } from 'react'
import { Box } from '@mui/material'

// tiptap is loaded with the first form that holds an html field (kept out of the main bundle)
const Editor = lazy(() => import('./RichEditor'))

export default function RichText(props: ComponentProps<typeof Editor>)
{

	return (
		<Suspense fallback={<Box sx={{height: 264, border: '1px solid var(--u-border-strong)', borderRadius: 2, bgcolor: 'var(--u-paper)'}} />}>
			<Editor {...props} />
		</Suspense>
	)

}
