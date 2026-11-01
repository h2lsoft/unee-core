import { useEffect, useState } from 'react'
import { Box, Button, ButtonBase, Checkbox, IconButton, Tooltip, Typography } from '@mui/material'
import { AddOutlined, CheckOutlined, DeleteOutlineOutlined, EditOutlined, StickyNote2Outlined } from '@mui/icons-material'
import { api, ApiException } from '../api'
import { t } from '../i18n'
import { relativeDay } from './time'
import { useToast } from './Toast'
import { LABEL_SX, type WidgetInfo } from './Widgets'

type Note = {id: number, text: string, color: string, date: string}

// colors of DashboardController::NOTE_COLORS, background and border of each theme in colors.css
const COLORS: Record<string, {bg: string, border: string}> = {
	yellow: {bg: 'var(--u-yellow-bg)', border: 'var(--u-yellow)'},
	green: {bg: 'var(--u-green-bg)', border: 'var(--u-green)'},
	// primary follows the theme primary color
	primary: {bg: 'var(--u-accent-bg)', border: 'var(--mui-palette-primary-main)'},
}

// checkbox at the start of a line: [] or [ ] unchecked, [x] or [X] checked
const CHECK = /^\[([ xX]?)\] ?/

// last lines empty or with a checkbox alone are removed on save
function tidy(text: string): string
{

	const lines = text.split('\n')
	while(lines.length && !lines[lines.length - 1].replace(CHECK, '').trim())lines.pop()
	return lines.join('\n')

}

// compact notes in a single scrolling row, the editor needs more room (the row grows while editing)
const NOTE_HEIGHT = 80
const EDITOR_HEIGHT = 120

function Editor({note, onSave, onCancel}: {note: {text: string, color: string}, onSave: (text: string, color: string) => void, onCancel: () => void})
{

	const [text, setText] = useState(note.text)
	// a color no longer offered (old blue or pink note) falls back to yellow, like the api
	const [color, setColor] = useState(COLORS[note.color] ? note.color : 'yellow')
	// only checkbox markers: nothing to save
	const empty = !text.replace(new RegExp(CHECK.source, 'gm'), '').trim()

	// enter on a checkbox line starts the next line with a new checkbox
	const keyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {

		if(e.key === 'Escape')onCancel()
		if(e.key !== 'Enter')return
		if(e.ctrlKey || e.metaKey)
		{

			if(!empty)onSave(tidy(text), color)
			return

		}
		if(e.shiftKey)return

		const el = e.currentTarget
		const pos = el.selectionStart
		if(!CHECK.test(text.slice(text.lastIndexOf('\n', pos - 1) + 1, pos)))return

		e.preventDefault()
		setText(text.slice(0, pos) + '\n[] ' + text.slice(el.selectionEnd))
		requestAnimationFrame(() => el.setSelectionRange(pos + 4, pos + 4))

	}

	return (
		<Box sx={{flex: '0 0 240px', height: EDITOR_HEIGHT, p: 1.25, display: 'flex', flexDirection: 'column', gap: 1, bgcolor: COLORS[color]?.bg, border: '1px solid', borderColor: COLORS[color]?.border, borderRadius: '5px', boxShadow: '0 0 0 2px var(--u-accent-border)'}}>
			<Box component="textarea" autoFocus aria-label={t('Note')} value={text} maxLength={2000} onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setText(e.target.value)} onKeyDown={keyDown}
				onFocus={(e: React.FocusEvent<HTMLTextAreaElement>) => e.currentTarget.setSelectionRange(text.length, text.length)}
				sx={{width: '100%', flexGrow: 1, minHeight: 0, border: 0, bgcolor: 'transparent', resize: 'none', scrollbarWidth: 'thin', font: 'inherit', fontSize: 13.5, lineHeight: 1.45, color: 'var(--u-text)', outline: 'none'}} />
			<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75}}>
				{Object.entries(COLORS).map(([key, c]) => (
					<ButtonBase key={key} aria-label={t(key)} aria-pressed={key === color} onClick={() => setColor(key)} sx={{flexShrink: 0, width: 20, height: 20, borderRadius: '50%', bgcolor: c.border, color: '#fff'}}>
						{key === color && <CheckOutlined sx={{fontSize: 14}} />}
					</ButtonBase>
				))}
				<Box sx={{flexGrow: 1}} />
				<Button size="small" onClick={onCancel} sx={{height: 28, minWidth: 0, px: 1, fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Cancel')}</Button>
				<Button size="small" variant="contained" disabled={empty} onClick={() => onSave(tidy(text), color)} sx={{height: 28, px: 1.5, fontSize: 12.5}}>{t('Save')}</Button>
			</Box>
		</Box>
	)

}

/**
 * personal notes of the logged user (post-it), added and changed in place, placed by its widget (\Core\Widget::NOTES)
 */
export default function Notes({widget}: {widget: WidgetInfo})
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

	// check or uncheck a line from the view mode, saved at once
	const toggle = async (note: Note, index: number) => {

		const lines = note.text.split('\n')
		lines[index] = lines[index].replace(CHECK, (_, mark: string) => mark.trim() ? '[] ' : '[X] ')
		const text = lines.join('\n')
		setNotes((list) => list?.map((n) => n.id === note.id ? {...n, text} : n) ?? null)

		try
		{

			await api(`dashboard/notes/${note.id}/`, {method: 'PUT', body: {text, color: note.color}})

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.message : t('Error'), 'error')
			load()

		}

	}

	const remove = async (id: number) => {

		setConfirm(null)
		await api(`dashboard/notes/${id}/`, {method: 'DELETE'}).catch(() => null)
		load()

	}

	return (
		<Box component="section" aria-label={widget.name} sx={{gridColumn: {xs: 'span 2', md: widget.size === 'half' ? 'span 1' : 'span 2'}, minWidth: 0, display: 'flex', flexDirection: 'column', bgcolor: 'var(--u-widget)', borderRadius: '12px'}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, px: 1.75, py: 1.25}}>
				<StickyNote2Outlined sx={{fontSize: 18, color: 'var(--u-muted)'}} />
				<Typography component="h2" sx={{...LABEL_SX, flexGrow: 1}}>{widget.name}</Typography>
				<ButtonBase onClick={() => setEditing('new')} sx={{display: 'flex', alignItems: 'center', gap: 0.25, fontFamily: 'inherit', fontSize: 12.5, fontWeight: 500, lineHeight: 'inherit', color: 'var(--u-accent-text)', '&:hover': {textDecoration: 'underline'}}}><AddOutlined sx={{fontSize: 16}} />{t('Add note')}</ButtonBase>
			</Box>
			<Box sx={{px: 1.75, py: 0.75, flexGrow: 1}}>
				{notes?.length === 0 && editing !== 'new'
					? <Typography sx={{py: 2, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{t('No note')}</Typography>
					: (
					<Box sx={{py: 0.5, display: 'flex', gap: 1.5, overflowX: 'auto', scrollbarWidth: 'thin'}}>
						{editing === 'new' && <Editor note={{text: '[] ', color: 'yellow'}} onSave={(text, color) => save('new', text, color)} onCancel={() => setEditing(null)} />}
						{notes?.map((note) => editing === note.id
							? <Editor key={note.id} note={note} onSave={(text, color) => save(note.id, text, color)} onCancel={() => setEditing(null)} />
							: (
								<Box key={note.id} sx={{flex: '0 0 240px', height: NOTE_HEIGHT, p: 1, pb: 0.5, display: 'flex', flexDirection: 'column', gap: 0.5, bgcolor: COLORS[note.color]?.bg ?? COLORS.yellow.bg, border: '1px solid', borderColor: COLORS[note.color]?.border ?? COLORS.yellow.border, borderRadius: '5px'}}>
									<Box onDoubleClick={() => setEditing(note.id)} sx={{flexGrow: 1, minHeight: 0, overflowY: 'auto', scrollbarWidth: 'thin', fontSize: 13.5, lineHeight: 1.45, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', color: 'var(--u-text)'}}>
										{note.text.split('\n').map((line, i) => {

											const mark = line.match(CHECK)
											if(!mark)return <Box key={i} sx={{minHeight: '1.45em'}}>{line}</Box>

											const checked = mark[1].trim() !== ''
											const label = line.slice(mark[0].length)

											return (
												<Box key={i} component="label" onDoubleClick={(e: React.MouseEvent) => e.stopPropagation()} sx={{display: 'flex', alignItems: 'flex-start', gap: 0.75, cursor: 'pointer'}}>
													<Checkbox size="small" checked={checked} onChange={() => toggle(note, i)} sx={{p: 0, mt: '1px', color: 'var(--u-text3)', '& svg': {fontSize: 18}}} />
													<Box component="span" sx={checked ? {textDecoration: 'line-through', color: 'var(--u-muted)'} : undefined}>{label}</Box>
												</Box>
											)

										})}
									</Box>
									<Box sx={{display: 'flex', alignItems: 'center', gap: 0.25, fontSize: 11, color: 'var(--u-text3)'}}>
										<Box component="span" sx={{flexGrow: 1}}>{relativeDay(note.date)}</Box>
										{confirm === note.id
											? <><Button size="small" color="error" onClick={() => remove(note.id)} sx={{height: 20, minWidth: 0, px: 1, fontSize: 12}}>{t('Delete')}</Button><Button size="small" onClick={() => setConfirm(null)} sx={{height: 20, minWidth: 0, px: 1, fontSize: 12, color: 'var(--u-text3)'}}>{t('Cancel')}</Button></>
											: <>
												<Tooltip title={t('Edit')}><IconButton size="small" aria-label={t('Edit')} onClick={() => setEditing(note.id)} sx={{p: 0.25, color: 'var(--u-text3)'}}><EditOutlined sx={{fontSize: 16}} /></IconButton></Tooltip>
												<Tooltip title={t('Delete')}><IconButton size="small" aria-label={t('Delete')} onClick={() => setConfirm(note.id)} sx={{p: 0.25, color: 'var(--u-text3)'}}><DeleteOutlineOutlined sx={{fontSize: 16}} /></IconButton></Tooltip>
											</>}
									</Box>
								</Box>
							))}
					</Box>
				)}
			</Box>
		</Box>
	)

}
