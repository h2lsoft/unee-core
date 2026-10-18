import { useState } from 'react'
import { useParams } from 'react-router'
import { Box, CircularProgress } from '@mui/material'
import { dataChanged, type Row } from '../api'
import { useMenu } from '../components/BackendLayout'
import DataGrid from '../components/DataGrid'
import FormDialog from '../components/FormDialog'
import { useDocumentTitle } from '../components/AuthLayout'
import NotFound from './NotFound'

/**
 * generic page of a crud module (\Core\CrudController): /backend/<module>/
 */
export default function ModulePage()
{

	const {module = ''} = useParams()
	const {menu, find} = useMenu()
	const item = find(module)
	const [form, setForm] = useState<{open: boolean, id: number}>({open: false, id: 0})
	const [version, setVersion] = useState(0)
	const [label, setLabel] = useState('')

	useDocumentTitle(item?.name ?? '')

	if(!menu)return <Box sx={{display: 'flex', justifyContent: 'center', py: 8}}><CircularProgress /></Box>
	if(!item || item.url)return <NotFound />

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
				onClose={() => setForm({...form, open: false})}
				onSaved={() => { setForm({...form, open: false}); dataChanged(module); setVersion((v) => v + 1) }}
			/>
		</>
	)

}
