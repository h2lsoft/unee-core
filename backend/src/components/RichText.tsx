import { lazy, Suspense, type ComponentProps } from 'react'
import { Box } from '@mui/material'

// tiptap is loaded with the first form that holds an html field (kept out of the main bundle)
const Editor = lazy(() => import('./RichEditor'))

export default function RichText(props: ComponentProps<typeof Editor>)
{

	return (
		<Suspense fallback={<Box sx={{height: 264, border: '1px solid #d5d8e3', borderRadius: 2, bgcolor: '#fff'}} />}>
			<Editor {...props} />
		</Suspense>
	)

}
