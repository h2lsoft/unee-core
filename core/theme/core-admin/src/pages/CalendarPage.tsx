import { lazy, Suspense } from 'react'
import { Box, CircularProgress } from '@mui/material'

// fullcalendar is loaded with the page (kept out of the main bundle)
const CalendarView = lazy(() => import('../components/CalendarView'))

export default function CalendarPage({icon}: {icon: string})
{

	return (
		<Suspense fallback={<Box sx={{display: 'flex', justifyContent: 'center', py: 8}}><CircularProgress /></Box>}>
			<CalendarView icon={icon} />
		</Suspense>
	)

}
