import { lazy, Suspense, type ComponentProps } from 'react'
import { Box } from '@mui/material'

// codemirror is loaded with the first form that holds code (about 600 kB, kept out of the main bundle)
const Editor = lazy(() => import('./CodeEditor'))

export default function Code(props: ComponentProps<typeof Editor>)
{

	return (
		<Suspense fallback={<Box sx={{height: props.height ?? 260, border: '1px solid var(--u-border-strong)', borderRadius: 2, bgcolor: 'var(--u-head)'}} />}>
			<Editor {...props} />
		</Suspense>
	)

}
