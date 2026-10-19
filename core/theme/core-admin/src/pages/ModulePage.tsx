import { useState } from 'react'
import { useParams, useSearchParams } from 'react-router'
import { Box, CircularProgress } from '@mui/material'
import { dataChanged, type Row } from '../api'
import { useMenu } from '../components/BackendLayout'
import DataGrid from '../components/DataGrid'
import FormDialog from '../components/FormDialog'
import { useDocumentTitle } from '../components/AuthLayout'
import NotFound from './NotFound'
import RightsPage from './RightsPage'
import SystemInfoPage from './SystemInfoPage'
import CalendarPage from './CalendarPage'
import ProjectBoard from '../components/ProjectBoard'

/**
 * generic page of a crud module (\Core\CrudController): /backend/<module>/
 */
export default function ModulePage()
{

	const {module = ''} = useParams()
	const {menu, find} = useMenu()
	const [params] = useSearchParams()
	const item = find(module)
	const [form, setForm] = useState<{open: boolean, id: number}>({open: false, id: 0})
	const [version, setVersion] = useState(0)
	const [label, setLabel] = useState('')

	useDocumentTitle(item?.name ?? '')

	// a new record takes the equal filters of the url, ex: gallery-card/?search[]=xcore_gallery_id||3
	const defaults = Object.fromEntries(params.getAll('search[]').map((raw) => raw.split('||')).filter((p) => p.length === 2 && p[0] !== 'q' && p[1] !== ''))

	if(!menu)return <Box sx={{display: 'flex', justifyContent: 'center', py: 8}}><CircularProgress /></Box>
	if(!item || item.url)return <NotFound />

	// modules with their own page
	if(module === 'right')return <RightsPage icon={item.icon} />
	if(module === 'system-info')return <SystemInfoPage icon={item.icon} />
	if(module === 'calendar')return <CalendarPage icon={item.icon} />
	if(module === 'project' && (params.get('board') || params.get('gantt')))return <ProjectBoard id={Number(params.get('board') || params.get('gantt'))} view={params.get('gantt') ? 'gantt' : 'board'} />

	return (
		<>
			<DataGrid
				key={module}
				module={module}
				icon={item.icon}
				version={version}
				onTitle={(_, record_label) => setLabel(record_label)}
				onAdd={() => setForm({open: true, id: 0})}
				onEdit={(row: Row) => setForm({open: true, id: row.id})}
			/>
			<FormDialog
				module={module}
				id={form.id}
				label={label}
				open={form.open}
				defaults={defaults}
				onClose={() => setForm({...form, open: false})}
				onSaved={() => { setForm({...form, open: false}); dataChanged(module); setVersion((v) => v + 1) }}
			/>
		</>
	)

}
