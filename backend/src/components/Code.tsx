import { lazy, Suspense, type ComponentProps } from 'react'
import { Box } from '@mui/material'

// codemirror is loaded with the first form that holds code (about 600 kB, kept out of the main bundle)
const Editor = lazy(() => import('./CodeEditor'))

export default function Code(props: ComponentProps<typeof Editor>)
{

	return (
		<Suspense fallback={<Box sx={{height: props.height ?? 260, border: '1px solid #d5d8e3', borderRadius: 2, bgcolor: '#fbfbfd'}} />}>
			<Editor {...props} />
		</Suspense>
	)

}
