import { useEffect, useState } from 'react'
import { useParams, useSearchParams } from 'react-router'
import { Box, CircularProgress } from '@mui/material'
import { dataChanged, type GridAction, type Row } from '../api'
import { useMenu } from '../components/BackendLayout'
import DataGrid from '../components/DataGrid'
import FormDialog from '../components/FormDialog'
import { useDocumentTitle } from '../components/AuthLayout'
import { useAuth } from '../auth'
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
	const [params, setParams] = useSearchParams()
	const item = find(module)
	const [form, setForm] = useState<{open: boolean, id: number, copy?: number}>({open: false, id: 0})
	const [version, setVersion] = useState(0)
	const [label, setLabel] = useState('')
	// button of the grid header with a form of its own (ex: settings of the module)
	const [action_form, setActionForm] = useState<GridAction | null>(null)
	const {user, reload} = useAuth()

	useDocumentTitle(item?.name ?? '')

	// ?add=1 (action of the global search): the add form opens, the parameter leaves the url
	useEffect(() => {

		if(params.get('add') !== '1')return
		setForm({open: true, id: 0})
		const next = new URLSearchParams(params)
		next.delete('add')
		setParams(next, {replace: true})

	}, [params, setParams])

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
				onDuplicate={(row: Row) => setForm({open: true, id: 0, copy: row.id})}
				onForm={setActionForm}
			/>
			<FormDialog
				module={module}
				id={form.id}
				copy={form.copy}
				label={label}
				open={form.open}
				defaults={defaults}
				onClose={() => setForm({...form, open: false})}
				onSaved={() => { setForm({...form, open: false}); dataChanged(module); setVersion((v) => v + 1); if(module === 'user' && form.id === user?.id)reload() }}
				onRestored={() => setVersion((v) => v + 1)}
				onChanged={() => setVersion((v) => v + 1)}
			/>
			{action_form && <FormDialog module={module} id={0} label="" url={action_form.form} title={`${item.name} - ${action_form.label}`} open onClose={() => setActionForm(null)} onSaved={() => { setActionForm(null); dataChanged(module); setVersion((v) => v + 1) }} />}
		</>
	)

}
