import { useEffect, useState } from 'react'
import { Box, Button, ButtonBase, IconButton, Tooltip, Typography } from '@mui/material'
import { AddOutlined, DeleteOutlineOutlined, EditOutlined } from '@mui/icons-material'
import { api, ApiException } from '../api'
import { t } from '../i18n'
import { relativeDay } from './time'
import { useToast } from './Toast'

type Note = {id: number, text: string, color: string, date: string}

// colors of DashboardController::NOTE_COLORS, background and border of each theme in colors.css
const COLORS: Record<string, {bg: string, border: string}> = {
	yellow: {bg: 'var(--u-yellow-bg)', border: 'var(--u-yellow)'},
	green: {bg: 'var(--u-green-bg)', border: 'var(--u-green)'},
	blue: {bg: 'var(--u-blue-bg)', border: 'var(--u-blue)'},
	pink: {bg: 'var(--u-red-bg)', border: 'var(--u-red)'},
	purple: {bg: 'var(--u-accent-bg)', border: 'var(--u-accent-text)'},
}

function Editor({note, onSave, onCancel}: {note: {text: string, color: string}, onSave: (text: string, color: string) => void, onCancel: () => void})
{

	const [text, setText] = useState(note.text)
	const [color, setColor] = useState(note.color)

	return (
		<Box sx={{p: 1.25, display: 'flex', flexDirection: 'column', gap: 1, bgcolor: COLORS[color]?.bg, border: '1px solid', borderColor: COLORS[color]?.border, borderRadius: '5px', boxShadow: '0 0 0 2px var(--u-accent-border)'}}>
			<Box component="textarea" autoFocus aria-label={t('Note')} value={text} maxLength={2000} onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setText(e.target.value)}
				onKeyDown={(e: React.KeyboardEvent) => { if(e.key === 'Escape')onCancel(); if(e.key === 'Enter' && (e.ctrlKey || e.metaKey) && text.trim())onSave(text, color) }}
				sx={{width: '100%', minHeight: 96, border: 0, bgcolor: 'transparent', resize: 'vertical', font: 'inherit', fontSize: 13.5, lineHeight: 1.45, color: 'var(--u-text)', outline: 'none'}} />
			<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75}}>
				{Object.entries(COLORS).map(([key, c]) => (
					<ButtonBase key={key} aria-label={t(key)} aria-pressed={key === color} onClick={() => setColor(key)} sx={{width: 20, height: 20, borderRadius: '50%', bgcolor: c.bg, border: '2px solid', borderColor: key === color ? 'primary.main' : c.border}} />
				))}
				<Box sx={{flexGrow: 1}} />
				<Button size="small" onClick={onCancel} sx={{height: 28, minWidth: 0, px: 1, fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Cancel')}</Button>
				<Button size="small" variant="contained" disabled={!text.trim()} onClick={() => onSave(text, color)} sx={{height: 28, px: 1.5, fontSize: 12.5}}>{t('Save')}</Button>
			</Box>
		</Box>
	)

}

/**
 * personal notes of the logged user (post-it), added and changed in place
 */
export default function Notes()
{

	const toast = useToast()
	const [notes, setNotes] = useState<Note[] | null>(null)
	const [editing, setEditing] = useState<number | 'new' | null>(null)
	const [confirm, setConfirm] = useState<number | null>(null)

	const load = () => api<{notes: Note[]}>('dashboard/notes/').then((d) => setNotes(d.notes)).catch(() => setNotes([]))
	useEffect(() => { load() }, [])

	const save = async (id: number | 'new', text: string, color: string) => {

		try
		{

			await api(id === 'new' ? 'dashboard/notes/' : `dashboard/notes/${id}/`, {method: id === 'new' ? 'POST' : 'PUT', body: {text, color}})
			setEditing(null)
			load()

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.message : t('Error'), 'error')

		}

	}

	const remove = async (id: number) => {

		setConfirm(null)
		await api(`dashboard/notes/${id}/`, {method: 'DELETE'}).catch(() => null)
		load()

	}

	return (
		<Box component="section" aria-label={t('My notes')} sx={{gridColumn: 'span 2', display: 'flex', flexDirection: 'column', gap: 1.25}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
				<Typography component="h2" sx={{fontSize: 13, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--u-muted)', flexGrow: 1}}>{t('My notes')}</Typography>
				<Button variant="outlined" size="small" startIcon={<AddOutlined />} disabled={editing === 'new'} onClick={() => setEditing('new')} sx={{height: 32, bgcolor: 'var(--u-paper)'}}>{t('Add note')}</Button>
			</Box>
			<Box sx={{display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 2}}>
				{editing === 'new' && <Editor note={{text: '', color: 'yellow'}} onSave={(text, color) => save('new', text, color)} onCancel={() => setEditing(null)} />}
				{notes?.map((note) => editing === note.id
					? <Editor key={note.id} note={note} onSave={(text, color) => save(note.id, text, color)} onCancel={() => setEditing(null)} />
					: (
						<Box key={note.id} sx={{minHeight: 120, p: 1.25, pb: 0.75, display: 'flex', flexDirection: 'column', gap: 0.75, bgcolor: COLORS[note.color]?.bg ?? COLORS.yellow.bg, border: '1px solid', borderColor: COLORS[note.color]?.border ?? COLORS.yellow.border, borderRadius: '5px'}}>
							<Typography onDoubleClick={() => setEditing(note.id)} sx={{flexGrow: 1, fontSize: 13.5, lineHeight: 1.45, whiteSpace: 'pre-line', overflowWrap: 'anywhere', color: 'var(--u-text)'}}>{note.text}</Typography>
							<Box sx={{display: 'flex', alignItems: 'center', gap: 0.25, fontSize: 11, color: 'var(--u-text3)'}}>
								<Box component="span" sx={{flexGrow: 1}}>{relativeDay(note.date)}</Box>
								{confirm === note.id
									? <><Button size="small" color="error" onClick={() => remove(note.id)} sx={{height: 24, minWidth: 0, px: 1, fontSize: 12}}>{t('Delete')}</Button><Button size="small" onClick={() => setConfirm(null)} sx={{height: 24, minWidth: 0, px: 1, fontSize: 12, color: 'var(--u-text3)'}}>{t('Cancel')}</Button></>
									: <>
										<Tooltip title={t('Edit')}><IconButton size="small" aria-label={t('Edit')} onClick={() => setEditing(note.id)} sx={{color: 'var(--u-text3)'}}><EditOutlined sx={{fontSize: 16}} /></IconButton></Tooltip>
										<Tooltip title={t('Delete')}><IconButton size="small" aria-label={t('Delete')} onClick={() => setConfirm(note.id)} sx={{color: 'var(--u-text3)'}}><DeleteOutlineOutlined sx={{fontSize: 16}} /></IconButton></Tooltip>
									</>}
							</Box>
						</Box>
					))}
				{notes?.length === 0 && editing !== 'new' && <Typography sx={{fontSize: 13, color: 'var(--u-muted)'}}>{t('No note yet: add one to keep an idea or a reminder.')}</Typography>}
			</Box>
		</Box>
	)

}
