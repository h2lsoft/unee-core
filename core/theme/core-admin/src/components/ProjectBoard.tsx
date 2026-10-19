import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { Box, Button, ButtonBase, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, ListItemIcon, Menu, MenuItem, OutlinedInput, Switch, Typography } from '@mui/material'
import { AddOutlined, ArrowBackOutlined, AttachFileOutlined, CloseOutlined, DeleteOutlineOutlined, DragIndicatorOutlined, EditOutlined, MoreHorizOutlined, ViewKanbanOutlined, ViewTimelineOutlined, WestOutlined, EastOutlined } from '@mui/icons-material'
import { api, ApiException, dataChanged } from '../api'
import { getLanguage, t } from '../i18n'
import { Badge, BADGES } from './DataGrid'
import FormDialog from './FormDialog'
import TaskDialog, { type Step, type Task } from './TaskDialog'
import { useToast } from './Toast'
import UserAvatar, { type AvatarData } from './UserAvatar'

type Board = {
	project: {id: number, name: string, color: string, status: string, status_label: string, status_color: string, progress: number, date_start: string | null, date_end: string | null, responsible: {name: string, avatar: AvatarData} | null}
	steps: Step[]
	tasks: Task[]
	users: {id: number, name: string}[]
	categories: string[]
	rights: string[]
}

const STEP_COLORS = ['grey', 'blue', 'orange', 'green', 'red', 'purple', 'yellow']
const DAY = 86400000
const DAY_W = 44

// Y-m-d read and written in local time
const parse = (v: string) => { const [y, m, d] = v.split('-').map(Number); return new Date(y, m - 1, d) }
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const days = (a: Date, b: Date) => Math.round((b.getTime() - a.getTime()) / DAY)
const short = (v: string, year = false) => parse(v).toLocaleDateString(getLanguage(), {day: 'numeric', month: 'short', ...(year ? {year: 'numeric'} : {})})
const range = (start: string | null, end: string | null, year = false) => (start && end) ? `${short(start)} - ${short(end, year)}` : (start ? short(start, year) : (end ? short(end, year) : ''))

const message = (e: unknown) => e instanceof ApiException ? e.error.message : t('network error, please retry')

// add (id = 0) or edit a step: name, color, final (tasks dropped there are done)
function StepDialog({step, onClose, onSave}: {step: Partial<Step>, onClose: () => void, onSave: (row: {name: string, color: string, final: boolean}) => void})
{

	const [name, setName] = useState(step.name ?? '')
	const [color, setColor] = useState(step.color ?? 'grey')
	const [final, setFinal] = useState(!!step.final)

	return (
		<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && onClose()} maxWidth="xs" fullWidth slotProps={{paper: {component: 'form', onSubmit: (e: React.FormEvent) => { e.preventDefault(); if(name.trim())onSave({name: name.trim(), color, final}) }} as object}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', pr: 1.5, fontSize: 18, fontWeight: 600}}>
				<Box component="span" sx={{flexGrow: 1}}>{step.id ? t('Edit step') : t('Add step')}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{display: 'flex', flexDirection: 'column', gap: 2, pt: '8px !important'}}>
				<OutlinedInput autoFocus value={name} onChange={(e) => setName(e.target.value)} inputProps={{maxLength: 100, 'aria-label': t('Name')}} placeholder={t('Name')} sx={{height: 34, fontSize: 14}} />
				<Box role="radiogroup" aria-label={t('Color')} sx={{display: 'flex', gap: 1}}>
					{STEP_COLORS.map((c) => <ButtonBase key={c} role="radio" aria-checked={c === color} aria-label={t(c)} title={t(c)} onClick={() => setColor(c)} sx={{width: 22, height: 22, borderRadius: '50%', bgcolor: BADGES[c].fg, boxShadow: c === color ? '0 0 0 2px var(--u-paper), 0 0 0 4px var(--mui-palette-primary-main)' : 'none'}} />)}
				</Box>
				<Box component="label" sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 14}}>
					<Switch checked={final} onChange={(e) => setFinal(e.target.checked)} sx={{ml: -1}} />{t('Final step: the tasks dropped here are done')}
				</Box>
			</DialogContent>
			<DialogActions sx={{px: 3, pb: 2.5}}><Button type="submit" variant="contained" disabled={!name.trim()}>{step.id ? t('Save') : t('Create')}</Button></DialogActions>
		</Dialog>
	)

}

function Progress({value, color, height = 5}: {value: number, color: string, height?: number})
{

	return <Box component="span" sx={{display: 'block', flexGrow: 1, height, borderRadius: 3, bgcolor: 'var(--u-border-soft)', overflow: 'hidden'}}><Box component="span" sx={{display: 'block', height: '100%', width: `${Math.max(0, Math.min(100, value))}%`, bgcolor: color}} /></Box>

}

function Card({task, final, can_drag, dragging, onOpen, onDragStart, onDragEnd}: {task: Task, final: boolean, can_drag: boolean, dragging: boolean, onOpen: () => void, onDragStart: (e: React.DragEvent) => void, onDragEnd: () => void})
{

	const urgent = task.priority === 'high' || task.priority === 'urgent'

	return (
		<Box data-task={task.id} role="button" tabIndex={0} aria-label={task.title} draggable={can_drag} onDragStart={onDragStart} onDragEnd={onDragEnd} onClick={onOpen} onKeyDown={(e) => { if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); onOpen() } }}
			sx={{bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '5px', p: '9px 10px', display: 'flex', flexDirection: 'column', gap: 0.625, boxShadow: '0 1px 2px rgba(16, 20, 40, .04)', opacity: dragging ? 0.4 : 1, cursor: can_drag ? 'grab' : 'pointer', '&:hover': {borderColor: 'var(--u-border-strong)'}, '&:focus-visible': {outline: '2px solid var(--mui-palette-primary-main)'}}}>
			{task.image && <Box component="img" src={`/api/${task.image}`} alt="" loading="lazy" draggable={false} sx={{height: 70, width: '100%', objectFit: 'cover', borderRadius: '4px', mb: 0.5}} />}
			{(task.category || urgent || can_drag) && (
				<Box sx={{display: 'flex', gap: 0.75, alignItems: 'center'}}>
					{task.category && <Badge value={task.category} />}
					{urgent && <Badge value={t(task.priority)} color={task.priority === 'urgent' ? 'red' : 'orange'} />}
					<Box sx={{flexGrow: 1}} />
					{can_drag && <DragIndicatorOutlined sx={{fontSize: 16, color: 'var(--u-faint)'}} />}
				</Box>
			)}
			<Box sx={{fontSize: 13.5, fontWeight: 500, lineHeight: 1.35, overflowWrap: 'anywhere'}}>{task.title}</Box>
			{task.excerpt && <Box sx={{fontSize: 12, color: 'var(--u-muted)', lineHeight: 1.4}}>{task.excerpt}</Box>}
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, mt: 0.25}}>
				<Progress value={task.progress} color={final ? 'var(--u-green)' : 'var(--mui-palette-primary-main)'} />
				<Box component="span" sx={{fontSize: 11, color: 'var(--u-muted)', fontVariantNumeric: 'tabular-nums'}}>{task.progress}%</Box>
			</Box>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 11.5, color: 'var(--u-muted)', minHeight: 22}}>
				<Box component="span" sx={{flexGrow: 1}}>{range(task.date_start, task.date_end)}</Box>
				{task.attachments > 0 && <Box component="span" title={t('Attachments')} sx={{display: 'flex', alignItems: 'center', gap: 0.25}}><AttachFileOutlined sx={{fontSize: 14}} />{task.attachments}</Box>}
				{task.user && <UserAvatar avatar={task.user.avatar} title={task.user.name} size={22} />}
			</Box>
		</Box>
	)

}

/**
 * kanban of a project: a column per step, cards dragged between and inside the columns
 */
function KanbanView({board, tasks, onOpen, onAdd, onReload}: {board: Board, tasks: Task[], onOpen: (task: Task) => void, onAdd: (step: Step) => void, onReload: () => void})
{

	const toast = useToast()
	const can_edit = board.rights.includes('edit')
	const [dragged, setDragged] = useState<number | null>(null)
	const [target, setTarget] = useState<{step: number, index: number} | null>(null)
	const [menu, setMenu] = useState<{anchor: HTMLElement, step: Step} | null>(null)
	const [editing, setEditing] = useState<Partial<Step> | null>(null)

	// place in the column from the pointer: before the first card whose middle is under it (the dragged card stays in the dom, a removed source ends the drag)
	const over = (e: React.DragEvent, step: number) => {

		if(dragged === null)return
		e.preventDefault()
		const cards = Array.from((e.currentTarget as HTMLElement).querySelectorAll<HTMLElement>('[data-task]')).filter((el) => Number(el.dataset.task) !== dragged)
		let index = cards.findIndex((el) => { const r = el.getBoundingClientRect(); return e.clientY < r.top + r.height / 2 })
		if(index < 0)index = cards.length
		if(target?.step !== step || target.index !== index)setTarget({step, index})

	}

	const drop = async (e: React.DragEvent) => {

		e.preventDefault()
		const id = dragged, to = target
		setDragged(null)
		setTarget(null)
		if(id === null || !to)return

		try
		{

			await api(`project/tasks/${id}/move/`, {method: 'POST', body: {step_id: to.step, index: to.index}})
			dataChanged('project')

		}
		catch(err)
		{

			toast(message(err), 'error')

		}
		onReload()

	}

	const saveStep = async (row: {name: string, color: string, final: boolean}) => {

		try
		{

			await api(editing?.id ? `project/steps/${editing.id}/` : `project/${board.project.id}/steps/`, {method: editing?.id ? 'PUT' : 'POST', body: row})
			setEditing(null)
			onReload()

		}
		catch(err)
		{

			toast(message(err), 'error')

		}

	}

	const deleteStep = async (step: Step) => {

		setMenu(null)
		try
		{

			await api(`project/steps/${step.id}/`, {method: 'DELETE'})
			onReload()

		}
		catch(err)
		{

			toast(message(err), 'error')

		}

	}

	const moveStep = async (step: Step, delta: number) => {

		setMenu(null)
		const ids = board.steps.map((s) => s.id)
		const i = ids.indexOf(step.id)
		if(i + delta < 0 || i + delta >= ids.length)return
		ids.splice(i, 1)
		ids.splice(i + delta, 0, step.id)
		await api(`project/${board.project.id}/steps/order/`, {method: 'POST', body: {ids}}).catch((err) => toast(message(err), 'error'))
		onReload()

	}

	return (
		<Box sx={{display: 'flex', gap: 1.5, alignItems: 'flex-start', overflowX: 'auto', pb: 1}}>
			{board.steps.map((step) => {

				const list = tasks.filter((tk) => tk.step_id === step.id)
				const shown = list.filter((tk) => tk.id !== dragged)
				const color = BADGES[step.color]?.fg ?? BADGES.grey.fg
				const placeholder = <Box key="placeholder" aria-hidden="true" sx={{height: 4, borderRadius: 2, bgcolor: 'primary.main'}} />

				return (
					<Box key={step.id} component="section" aria-label={step.name} onDragOver={(e) => over(e, step.id)} onDrop={drop}
						sx={{width: 268, flexShrink: 0, bgcolor: 'var(--u-head)', border: '1px solid var(--u-border-soft)', borderRadius: '6px', display: 'flex', flexDirection: 'column', outline: target?.step === step.id ? '2px solid var(--u-accent-border)' : 'none'}}>
						<Box sx={{display: 'flex', alignItems: 'center', gap: 1, p: '8px 8px 8px 12px', borderTop: '3px solid', borderTopColor: color, borderRadius: '6px 6px 0 0'}}>
							<Typography component="h2" sx={{fontSize: 13, fontWeight: 600}}>{step.name}</Typography>
							<Box component="span" sx={{minWidth: 18, px: 0.75, borderRadius: '9px', bgcolor: 'var(--u-paper)', fontSize: 11, fontWeight: 600, color: 'var(--u-text3)', textAlign: 'center'}}>{list.length}</Box>
							<Box sx={{flexGrow: 1}} />
							{can_edit && <IconButton size="small" aria-label={`${t('Options')} ${step.name}`} onClick={(e) => setMenu({anchor: e.currentTarget, step})} sx={{color: 'var(--u-text3)'}}><MoreHorizOutlined sx={{fontSize: 18}} /></IconButton>}
						</Box>
						<Box sx={{px: 1, pb: 1, display: 'flex', flexDirection: 'column', gap: 1, minHeight: 40}}>
							{list.map((task) => [
								target?.step === step.id && task.id !== dragged && target.index === shown.indexOf(task) ? placeholder : null,
								<Card key={task.id} task={task} final={step.final} can_drag={can_edit} dragging={task.id === dragged} onOpen={() => onOpen(task)}
									onDragStart={(e) => { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(task.id)); setDragged(task.id) }}
									onDragEnd={() => { setDragged(null); setTarget(null) }} />,
							])}
							{target?.step === step.id && target.index >= shown.length && placeholder}
							{board.rights.includes('add') && (
								<ButtonBase onClick={() => onAdd(step)} sx={{height: 32, gap: 0.5, border: '1px dashed var(--u-border-strong)', borderRadius: '5px', color: 'var(--u-text3)', fontSize: 12.5, '&:hover': {bgcolor: 'var(--u-paper)'}}}><AddOutlined sx={{fontSize: 16}} />{t('Add task')}</ButtonBase>
							)}
						</Box>
					</Box>
				)

			})}

			{can_edit && (
				<ButtonBase onClick={() => setEditing({})} sx={{width: 200, flexShrink: 0, height: 44, gap: 0.5, border: '1px dashed var(--u-border-strong)', borderRadius: '6px', color: 'var(--u-text3)', fontSize: 13, '&:hover': {bgcolor: 'var(--u-paper)'}}}><AddOutlined sx={{fontSize: 18}} />{t('Add step')}</ButtonBase>
			)}

			<Menu anchorEl={menu?.anchor} open={!!menu} onClose={() => setMenu(null)}>
				<MenuItem onClick={() => { setEditing(menu!.step); setMenu(null) }} sx={{fontSize: 13.5}}><ListItemIcon><EditOutlined fontSize="small" /></ListItemIcon>{t('Edit')}</MenuItem>
				<MenuItem disabled={menu?.step.id === board.steps[0]?.id} onClick={() => moveStep(menu!.step, -1)} sx={{fontSize: 13.5}}><ListItemIcon><WestOutlined fontSize="small" /></ListItemIcon>{t('Move left')}</MenuItem>
				<MenuItem disabled={menu?.step.id === board.steps.at(-1)?.id} onClick={() => moveStep(menu!.step, 1)} sx={{fontSize: 13.5}}><ListItemIcon><EastOutlined fontSize="small" /></ListItemIcon>{t('Move right')}</MenuItem>
				<MenuItem onClick={() => deleteStep(menu!.step)} sx={{fontSize: 13.5, color: 'error.main'}}><ListItemIcon><DeleteOutlineOutlined fontSize="small" color="error" /></ListItemIcon>{t('Delete')}</MenuItem>
			</Menu>

			{editing && <StepDialog step={editing} onClose={() => setEditing(null)} onSave={saveStep} />}
		</Box>
	)

}

/**
 * gantt of a project: a row per task grouped by step, a bar dragged to move the task, its edges to change its dates
 */
function GanttView({board, tasks, onOpen, onReload}: {board: Board, tasks: Task[], onOpen: (task: Task) => void, onReload: () => void})
{

	const toast = useToast()
	const can_edit = board.rights.includes('edit')
	const [drag, setDrag] = useState<{id: number, mode: 'move' | 'start' | 'end', x: number, delta: number} | null>(null)
	const moved = useRef(false)
	const scroller = useRef<HTMLDivElement>(null)

	// visible days: the project and its tasks, 2 days of margin, 4 weeks at least
	const today = parse(ymd(new Date()))
	const dates = [board.project.date_start, board.project.date_end, ...tasks.flatMap((tk) => [tk.date_start, tk.date_end])].filter((d): d is string => !!d).map(parse)
	const first = new Date(Math.min(today.getTime(), ...dates.map((d) => d.getTime())) - 2 * DAY)
	const last = new Date(Math.max(today.getTime() + 27 * DAY, ...dates.map((d) => d.getTime())) + 2 * DAY)
	const count = days(first, last) + 1
	const list = Array.from({length: count}, (_, i) => new Date(first.getFullYear(), first.getMonth(), first.getDate() + i))
	const months = list.reduce<{label: string, span: number}[]>((acc, d) => {

		const label = d.toLocaleDateString(getLanguage(), {month: 'long', year: 'numeric'})
		if(acc.at(-1)?.label === label)acc.at(-1)!.span++
		else acc.push({label, span: 1})
		return acc

	}, [])

	const width = count * DAY_W
	const grid = `repeating-linear-gradient(90deg, transparent 0 ${DAY_W - 1}px, var(--u-border-soft) ${DAY_W - 1}px ${DAY_W}px)`
	const today_left = days(first, today) * DAY_W + DAY_W / 2

	// opened on today (3 days before it)
	useEffect(() => { if(scroller.current)scroller.current.scrollLeft = Math.max(0, today_left - 3.5 * DAY_W) }, [])

	// dates of a task with the drag in progress
	const shifted = (task: Task) => {

		if(!task.date_start || !task.date_end)return null
		let start = parse(task.date_start), end = parse(task.date_end)
		if(drag?.id === task.id)
		{

			if(drag.mode !== 'end')start = new Date(start.getFullYear(), start.getMonth(), start.getDate() + drag.delta)
			if(drag.mode !== 'start')end = new Date(end.getFullYear(), end.getMonth(), end.getDate() + drag.delta)
			if(end < start)(drag.mode === 'start') ? (start = end) : (end = start)

		}
		return {start, end}

	}

	const down = (e: React.PointerEvent, task: Task) => {

		if(!can_edit || e.button !== 0)return
		const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
		const mode = (e.clientX - r.left < 8) ? 'start' : ((r.right - e.clientX < 8) ? 'end' : 'move')
		;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
		moved.current = false
		setDrag({id: task.id, mode, x: e.clientX, delta: 0})

	}

	const move = (e: React.PointerEvent) => {

		if(!drag)return
		const delta = Math.round((e.clientX - drag.x) / DAY_W)
		if(delta !== drag.delta){ moved.current = true; setDrag({...drag, delta}) }

	}

	const up = async (task: Task) => {

		if(!drag)return
		const d = shifted(task)
		setDrag(null)
		if(!moved.current || !drag.delta || !d)return

		try
		{

			await api(`project/tasks/${task.id}/dates/`, {method: 'POST', body: {date_start: ymd(d.start), date_end: ymd(d.end)}})
			dataChanged('project')

		}
		catch(err)
		{

			toast(message(err), 'error')

		}
		onReload()

	}

	return (
		<>
			<Box ref={scroller} sx={{bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '5px', overflow: 'auto', userSelect: drag ? 'none' : 'auto'}}>
				<Box sx={{width: 240 + width, minWidth: '100%'}}>
					<Box sx={{display: 'flex', position: 'sticky', top: 0, zIndex: 2, bgcolor: 'var(--u-paper)', borderBottom: '1px solid var(--u-border)'}}>
						<Box sx={{width: 240, flexShrink: 0, position: 'sticky', left: 0, zIndex: 1, bgcolor: 'var(--u-paper)', borderRight: '1px solid var(--u-border-soft)'}} />
						<Box>
							<Box sx={{display: 'flex'}}>{months.map((m) => <Box key={m.label} sx={{width: m.span * DAY_W, flexShrink: 0, px: 1, pt: 0.75, fontSize: 11.5, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--u-text3)', whiteSpace: 'nowrap', overflow: 'hidden', borderLeft: '1px solid var(--u-border-soft)'}}>{m.span > 2 ? m.label : ''}</Box>)}</Box>
							<Box sx={{display: 'flex'}}>
								{list.map((d) => {

									const weekend = d.getDay() === 0 || d.getDay() === 6
									const is_today = ymd(d) === ymd(today)
									return <Box key={d.getTime()} sx={{width: DAY_W, flexShrink: 0, textAlign: 'center', py: 0.5, fontSize: 11, lineHeight: 1.3, color: is_today ? 'var(--u-accent-text)' : (weekend ? 'var(--u-faint)' : 'var(--u-muted)'), fontWeight: is_today ? 600 : 400, borderLeft: '1px solid var(--u-border-soft)'}}>{d.toLocaleDateString(getLanguage(), {weekday: 'short'}).slice(0, 2)}<br />{d.getDate()}</Box>

								})}
							</Box>
						</Box>
					</Box>

					{board.steps.map((step) => {

						const rows = tasks.filter((tk) => tk.step_id === step.id)
						if(!rows.length)return null
						const color = BADGES[step.color]?.fg ?? BADGES.grey.fg

						return (
							<Box key={step.id}>
								<Box sx={{display: 'flex', bgcolor: 'var(--u-head)', borderTop: '1px solid var(--u-border-soft)'}}>
									<Box sx={{width: 240, flexShrink: 0, position: 'sticky', left: 0, bgcolor: 'var(--u-head)', display: 'flex', alignItems: 'center', gap: 1, px: 1.5, height: 30, fontSize: 12, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--u-text3)'}}><Box component="span" sx={{width: 8, height: 8, borderRadius: '50%', bgcolor: color}} />{step.name}</Box>
									<Box sx={{width}} />
								</Box>
								{rows.map((task) => {

									const d = shifted(task)
									const left = d ? days(first, d.start) * DAY_W + 3 : 0
									const bar = d ? (days(d.start, d.end) + 1) * DAY_W - 6 : 0

									return (
										<Box key={task.id} sx={{display: 'flex', alignItems: 'center', height: 38, borderTop: '1px solid var(--u-border-soft)'}}>
											<ButtonBase onClick={() => onOpen(task)} sx={{width: 240, height: '100%', flexShrink: 0, position: 'sticky', left: 0, zIndex: 1, bgcolor: 'var(--u-paper)', justifyContent: 'flex-start', gap: 1, px: 1.5, fontSize: 13, borderRight: '1px solid var(--u-border-soft)', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
												<Box component="span" sx={{flexGrow: 1, textAlign: 'left', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{task.title}</Box>
												{task.user && <UserAvatar avatar={task.user.avatar} title={task.user.name} size={20} />}
											</ButtonBase>
											<Box sx={{position: 'relative', height: '100%', width, flexShrink: 0, background: grid}}>
												{today_left >= 0 && today_left <= width && <Box sx={{position: 'absolute', left: today_left, top: 0, bottom: 0, width: 2, bgcolor: 'var(--u-accent-border)'}} />}
												{d
													? (
														<Box role="button" tabIndex={0} aria-label={`${task.title}, ${range(ymd(d.start), ymd(d.end))}`} title={`${task.title} - ${range(ymd(d.start), ymd(d.end))} - ${task.progress}%`}
															onPointerDown={(e) => down(e, task)} onPointerMove={move} onPointerUp={() => up(task)} onClick={() => { if(!moved.current)onOpen(task) }} onKeyDown={(e) => { if(e.key === 'Enter')onOpen(task) }}
															sx={{position: 'absolute', left, top: 9, height: 20, width: bar, borderRadius: '4px', border: '1px solid', borderColor: color, bgcolor: `color-mix(in srgb, ${color} 20%, transparent)`, overflow: 'hidden', cursor: can_edit ? (drag?.id === task.id ? 'grabbing' : 'grab') : 'pointer', touchAction: 'none',
																'&::before, &::after': can_edit ? {content: '""', position: 'absolute', top: 0, bottom: 0, width: 8, cursor: 'ew-resize'} : {}, '&::before': {left: 0}, '&::after': {right: 0}}}>
															<Box sx={{height: '100%', width: `${task.progress}%`, bgcolor: `color-mix(in srgb, ${color} 55%, transparent)`}} />
														</Box>
													)
													: <Box component="span" sx={{position: 'absolute', left: Math.max(0, today_left + 8), top: 11, fontSize: 11.5, color: 'var(--u-faint)', whiteSpace: 'nowrap'}}>{t('no dates')}</Box>}
											</Box>
										</Box>
									)

								})}
							</Box>
						)

					})}
					{!tasks.length && <Typography sx={{p: 3, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{t('No task yet')}</Typography>}
				</Box>
			</Box>
			{can_edit && <Typography sx={{fontSize: 12.5, color: 'var(--u-muted)'}}>{t('Drag a bar to move a task, its edges to change the dates; dependent tasks follow. The vertical line is today.')}</Typography>}
		</>
	)

}

/**
 * board of a project (/backend/project/?board=<id> or ?gantt=<id>): header, kanban or gantt, task dialog
 */
export default function ProjectBoard({id, view}: {id: number, view: 'board' | 'gantt'})
{

	const navigate = useNavigate()
	const [, setParams] = useSearchParams()
	const [board, setBoard] = useState<Board | null>(null)
	const [error, setError] = useState('')
	const [category, setCategory] = useState('')
	const [task, setTask] = useState<{id: number, defaults?: Record<string, string>} | null>(null)
	const [editing, setEditing] = useState(false)

	const load = () => api<Board>(`project/${id}/board/`).then(setBoard).catch((e) => setError(message(e)))
	useEffect(() => { load() }, [id])

	if(error)return <Box role="alert" sx={{px: 2, py: 1.5, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 14}}>{error}</Box>
	if(!board)return <Box sx={{display: 'flex', justifyContent: 'center', py: 8}}><CircularProgress /></Box>

	const p = board.project
	const tasks = category ? board.tasks.filter((tk) => tk.category === category) : board.tasks
	const chip = (value: string, label: string) => {

		const on = (value === category)
		return <ButtonBase key={value || 'all'} aria-pressed={on} onClick={() => setCategory(value)} sx={{height: 26, px: 1.25, borderRadius: '13px', fontSize: 12, fontWeight: 500, border: '1px solid', borderColor: on ? 'var(--u-accent-border)' : 'var(--u-border)', bgcolor: on ? 'var(--u-accent-bg)' : 'var(--u-paper)', color: on ? 'var(--u-accent-text)' : 'var(--u-text2)'}}>{label}</ButtonBase>

	}

	return (
		<>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, flexWrap: 'wrap'}}>
				<IconButton aria-label={t('Back')} onClick={() => navigate('/project/')} sx={{border: '1px solid var(--u-border)', bgcolor: 'var(--u-paper)', width: 34, height: 34}}><ArrowBackOutlined fontSize="small" /></IconButton>
				<Box component="span" sx={{width: 12, height: 12, borderRadius: '50%', bgcolor: p.color, flexShrink: 0}} />
				<Typography component="h1" sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>{p.name}</Typography>
				<Badge value={p.status_label} color={p.status_color} />
				<Box sx={{flexGrow: 1}} />
				<Box role="group" aria-label={t('View')} sx={{display: 'flex', border: '1px solid var(--u-border-strong)', borderRadius: 2, overflow: 'hidden'}}>
					{([['board', 'Board', <ViewKanbanOutlined key="b" sx={{fontSize: 18}} />], ['gantt', 'Gantt', <ViewTimelineOutlined key="g" sx={{fontSize: 18}} />]] as const).map(([key, label, icon], i) => (
						<ButtonBase key={key} aria-pressed={view === key} onClick={() => setParams({[key]: String(id)})} sx={{height: 32, gap: 0.75, px: 1.75, borderLeft: i ? '1px solid var(--u-border-strong)' : 0, bgcolor: view === key ? 'var(--u-accent-bg)' : 'var(--u-paper)', color: view === key ? 'var(--u-accent-text)' : 'var(--u-text2)', fontSize: 13, fontWeight: 600}}>{icon}{t(label)}</ButtonBase>
					))}
				</Box>
				{board.rights.includes('edit') && <Button variant="outlined" color="inherit" startIcon={<EditOutlined />} onClick={() => setEditing(true)} sx={{height: 34, borderColor: 'var(--u-border-strong)', bgcolor: 'var(--u-paper)', color: 'var(--u-text2)', fontWeight: 600}}>{t('Edit project')}</Button>}
			</Box>

			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap', fontSize: 12.5, color: 'var(--u-muted)'}}>
				{(p.date_start || p.date_end) && <span>{range(p.date_start, p.date_end, true)}</span>}
				{p.responsible && <Box component="span" sx={{display: 'flex', alignItems: 'center', gap: 0.75}}>{t('Responsible')} <UserAvatar avatar={p.responsible.avatar} size={20} /> {p.responsible.name}</Box>}
				<Box sx={{flexGrow: 1, maxWidth: 420, minWidth: 120, display: 'flex'}}><Progress value={p.progress} color="var(--u-green)" height={6} /></Box>
				<Box component="span" sx={{fontWeight: 600, color: 'var(--u-green)'}}>{p.progress}%</Box>
				{board.categories.length > 0 && <Box sx={{ml: 'auto', display: 'flex', gap: 0.75, flexWrap: 'wrap'}}>{chip('', t('All'))}{board.categories.map((c) => chip(c, c))}</Box>}
			</Box>

			{view === 'board'
				? <KanbanView board={board} tasks={tasks} onOpen={(tk) => setTask({id: tk.id})} onAdd={(step) => setTask({id: 0, defaults: {step_id: String(step.id), category, ...(step.final ? {progress: '100'} : {})}})} onReload={load} />
				: <GanttView board={board} tasks={tasks} onOpen={(tk) => setTask({id: tk.id})} onReload={load} />}

			{task && <TaskDialog project={p.id} id={task.id} steps={board.steps} tasks={board.tasks} users={board.users} categories={board.categories} rights={board.rights} defaults={task.defaults} onClose={() => setTask(null)} onSaved={() => { dataChanged('project'); load() }} />}

			<FormDialog module="project" id={p.id} label={t('project')} open={editing}
				onClose={() => setEditing(false)}
				onSaved={() => { setEditing(false); dataChanged('project'); load() }}
				onRestored={load}
				onDeleted={board.rights.includes('delete') ? () => { setEditing(false); dataChanged('project'); navigate('/project/') } : undefined} />
		</>
	)

}
