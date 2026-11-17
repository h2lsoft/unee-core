import { useEffect, useMemo, useState, type DragEvent, type KeyboardEvent } from 'react'
import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, InputAdornment, InputLabel, OutlinedInput, Tooltip, Typography } from '@mui/material'
import { AddCircleOutlineOutlined, CloseOutlined, DeleteOutlineOutlined, DragIndicatorOutlined, SearchOutlined, TuneOutlined } from '@mui/icons-material'
import { type FormField } from '../api'
import { t } from '../i18n'
import { FIELDS_SX, Input } from './FormDialog'
import { MenuIcon } from './icons'
import Notes from './Notes'
import { ClockWidget, GRID_SX, SPANS, UrlWidget, WidgetCard, widgetIcon, widgetSpan, type Placed, type WidgetField, type WidgetInfo, type WidgetSize } from './Widgets'

const SIZE_LABELS: Record<WidgetSize, string> = {quarter: '1 column', half: '2 columns', three_quarters: '3 columns', full: '4 columns'}
const COLUMNS = 4

// dashboard by rows (from the top), widgets of a row from the left, 4 columns at most by row
export type Rows = Placed[][]

// where a dragged widget goes: a new row above / below a widget, beside a widget in its row, at the end of a row, in a new last row
type Zone = {key: number, side: 'top' | 'bottom' | 'left' | 'right'} | {row: number, side: 'end'} | {side: 'last'}

const span = (w: Placed) => SPANS[w.size] ?? COLUMNS

// columns taken by a row, without the widget `skip` (the one moved)
const width = (row: Placed[], skip = 0) => row.reduce((n, w) => n + (w.key === skip ? 0 : span(w)), 0)

const fits = (row: Placed[], item: Placed) => width(row, item.key) + span(item) <= COLUMNS

// lower case without accents, for the search (meteo finds Météo)
const fold = (text: string) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

const sameRows = (a: Rows, b: Rows) => a.length === b.length && a.every((row, r) => row.length === b[r].length && row.every((w, i) => w.key === b[r][i].key && JSON.stringify(w.config) === JSON.stringify(b[r][i].config)))

/**
 * rows of the widgets of the api (field row)
 */
export function toRows(list: (Placed & {row?: number})[]): Rows
{

	const rows: Rows = []
	let current: number | undefined
	for(const w of list)
	{

		if(!rows.length || w.row !== current)rows.push([])
		current = w.row
		rows[rows.length - 1].push(w)

	}

	return rows

}

/**
 * a widget added: at the end of the last row when it has room, else in a new row
 */
export function addToRows(rows: Rows, item: Placed): Rows
{

	const last = rows[rows.length - 1]
	return (last && fits(last, item)) ? [...rows.slice(0, -1), [...last, item]] : [...rows, [item]]

}

// rows with `item` moved to `zone`: inserted first (copy), then the original removed and the empty rows dropped
function dropped(rows: Rows, item: Placed, zone: Zone): Rows
{

	const copy = {...item}
	const next = rows.map((row) => [...row])

	if(zone.side === 'last')next.push([copy])
	else if(zone.side === 'end')next[zone.row]?.push(copy)
	else
	{

		const r = next.findIndex((row) => row.some((w) => w.key === zone.key))
		if(r < 0)return rows

		const i = next[r].findIndex((w) => w.key === zone.key)
		if(zone.side === 'top')next.splice(r, 0, [copy])
		else if(zone.side === 'bottom')next.splice(r + 1, 0, [copy])
		else next[r].splice(zone.side === 'left' ? i : i + 1, 0, copy)

	}

	return next.map((row) => row.filter((w) => w !== item)).filter((row) => row.length > 0)

}

// line shown in the gap where the widget will go
const MARK: Record<string, object> = {
	top: {top: -10, left: 0, right: 0, height: 3},
	bottom: {bottom: -10, left: 0, right: 0, height: 3},
	left: {left: -10, top: 0, bottom: 0, width: 3},
	right: {right: -10, top: 0, bottom: 0, width: 3},
}

/**
 * button of the tab Dashboard (child of the Tabs, right of the tab): opens the dialog to add widgets
 * the props injected by the Tabs (value, selected, onChange...) are ignored; always rendered (hidden) so the tabs keep their dom index
 */
export function AddWidgetButton({onAdd, hidden}: {onAdd: () => void, hidden: boolean})
{

	return (
		<Tooltip title={t('Add widget')}>
			<IconButton aria-label={t('Add widget')} onClick={onAdd} tabIndex={hidden ? -1 : 0} sx={{alignSelf: 'center', ml: -1, mr: 0.5, p: 0.5, color: 'primary.main', visibility: hidden ? 'hidden' : 'visible'}}>
				<AddCircleOutlineOutlined sx={{fontSize: 20}} />
			</IconButton>
		</Tooltip>
	)

}

/**
 * dialog listing the widgets the user can add as cards on two columns (one on mobile): a widget already placed is left out unless multiple,
 * stays open after an add (several widgets in a row), search without case or accents, close cross only
 */
export function AddWidgetDialog({open, catalog, placed, onAdd, onClose}: {open: boolean, catalog: WidgetInfo[], placed: WidgetInfo[], onAdd: (widget: WidgetInfo) => void, onClose: () => void})
{

	const [search, setSearch] = useState('')
	const query = fold(search.trim())
	const used = new Set(placed.map((w) => w.id))
	const available = catalog.filter((w) => w.multiple || !used.has(w.id))
	const list = available.filter((w) => fold(w.name).includes(query))

	return (
		<Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth slotProps={{transition: {onExited: () => setSearch('')}}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 17, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{t('Add widget')}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{pb: 2.5}}>
				{available.length > 6 && (
					<OutlinedInput autoFocus fullWidth value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search a widget...')} inputProps={{'aria-label': t('Search a widget...')}}
						startAdornment={<InputAdornment position="start"><SearchOutlined sx={{fontSize: 20, color: 'var(--u-muted)'}} /></InputAdornment>}
						sx={{mb: 1, height: 34, fontSize: 14}} />
				)}
				{list.length === 0 && <Typography sx={{py: 3, fontSize: 13.5, color: 'var(--u-muted)', textAlign: 'center'}}>{available.length ? t('No widget found') : t('No widget available')}</Typography>}
				<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: 'repeat(2, minmax(0, 1fr))'}, gap: 1.5}}>
				{list.map((w) => (
					<Box key={w.id} sx={{minWidth: 0, p: 1.5, display: 'flex', flexDirection: 'column', gap: 1.25, border: '1px solid var(--u-border)', borderRadius: '10px', bgcolor: 'var(--u-paper)', transition: 'border-color .15s', '&:hover': {borderColor: 'var(--u-accent-border)'}}}>
						<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, minWidth: 0}}>
							<Box sx={{width: 34, height: 34, flexShrink: 0, display: 'grid', placeItems: 'center', borderRadius: '8px', bgcolor: 'var(--u-widget)'}}>
								<MenuIcon name={widgetIcon(w)} fontSize={18} color="var(--u-muted)" />
							</Box>
							<Box sx={{flexGrow: 1, minWidth: 0}}>
								<Typography noWrap title={w.name} sx={{fontSize: 14, fontWeight: 500}}>{w.name}</Typography>
								<Typography noWrap title={w.type === 'url' ? w.url : undefined} sx={{fontSize: 12, color: 'var(--u-muted)'}}>{w.type === 'url' ? w.url : (w.multiple ? t('Can be added several times') : t('Widget'))}</Typography>
							</Box>
						</Box>
						<Box sx={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1}}>
							<Typography sx={{fontSize: 12, color: 'var(--u-muted)'}}>{t(SIZE_LABELS[w.size] ?? '')}</Typography>
							<Button size="small" variant="outlined" onClick={() => onAdd(w)} sx={{height: 28, minWidth: 72, fontSize: 12.5}}>{t('Add')}</Button>
						</Box>
					</Box>
				))}
				</Box>
			</DialogContent>
		</Dialog>
	)

}

/**
 * settings of a placed widget (fields of the module Widgets): small dialog centered, icon and name of the widget in the title, laid out like the forms (label on the left, compact controls); Save, close cross only
 */
export function WidgetSettingsDialog({widget, onSave, onClose}: {widget: Placed | null, onSave: (config: Record<string, string>) => void, onClose: () => void})
{

	const [values, setValues] = useState<Record<string, string>>({})

	useEffect(() => {

		if(widget)setValues({...widget.config})

	}, [widget])

	// fields of the widget as form fields (same inputs as the forms)
	const fields: FormField[] = (widget?.fields ?? []).map((f: WidgetField) => ({name: f.name, label: f.label, type: f.type, required: true, width: 0, options: f.options, max_length: f.type === 'text' ? 255 : undefined}))
	const invalid = fields.some((f) => f.type === 'number' && (values[f.name] ?? '') !== '' && Number.isNaN(Number(values[f.name])))

	return (
		<Dialog open={widget !== null} onClose={(_, reason) => reason !== 'backdropClick' && onClose()} maxWidth="xs" fullWidth sx={{'& .MuiDialog-container': {alignItems: 'center'}}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 18, fontWeight: 600, pr: 1.5}}>
				{widget && <MenuIcon name={widgetIcon(widget)} fontSize={20} color="var(--u-muted)" />}
				<Box component="span" sx={{flexGrow: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{widget?.name}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<Box component="form" noValidate onSubmit={(e) => { e.preventDefault(); if(!invalid)onSave(values) }}>
				<DialogContent sx={{pt: 1}}>
					<Box sx={{display: 'flex', flexDirection: 'column', gap: '9px', py: 0.5, ...FIELDS_SX}}>
						{fields.map((f) => (
							<Box key={f.name} sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '90px 1fr'}, columnGap: 2, rowGap: 0.5, alignItems: 'start'}}>
								<InputLabel id={`field-${f.name}-label`} htmlFor={`field-${f.name}`} sx={{fontSize: 14, fontWeight: 500, color: 'var(--u-text2)', whiteSpace: 'normal', textAlign: {xs: 'left', sm: 'right'}, lineHeight: {sm: '34px'}}}>{f.label}</InputLabel>
								<Box sx={{minWidth: 0}}>
									<Input field={f} value={values[f.name] ?? (widget?.fields?.find((x) => x.name === f.name)?.default ?? '')} error={f.type === 'number' && (values[f.name] ?? '') !== '' && Number.isNaN(Number(values[f.name]))} onChange={(v) => setValues((x) => ({...x, [f.name]: v}))} />
								</Box>
							</Box>
						))}
					</Box>
				</DialogContent>
				<DialogActions sx={{px: 3, pt: 1, pb: 2.5}}>
					<Button type="submit" variant="contained" disabled={invalid}>{t('Save')}</Button>
				</DialogActions>
			</Box>
		</Dialog>
	)

}

/**
 * widgets of the dashboard of the user, by rows: moved by their handle (drag and drop: on the top / bottom of a widget = new row above / below,
 * on its left / right = beside it when its row has room, on the empty end of a row, under the last row = new row; or the arrow keys on the handle),
 * removed by their trash icon; onChange gets the new rows (saved by the page); an empty dashboard shows how to add widgets
 */
export function DashboardGrid({rows, version, onChange}: {rows: Rows, version: number, onChange: (rows: Rows) => void})
{

	// widget whose handle is pressed (the card becomes draggable), widget dragged, place under the pointer
	const [armed, setArmed] = useState<number | null>(null)
	const [dragging, setDragging] = useState<Placed | null>(null)
	const [zone, setZone] = useState<Zone | null>(null)
	const [settings, setSettings] = useState<Placed | null>(null)

	// handle released without a drag: the card is no longer draggable (text selection back)
	useEffect(() => {

		if(armed === null || dragging !== null)return
		const release = () => setArmed(null)
		window.addEventListener('pointerup', release)
		return () => window.removeEventListener('pointerup', release)

	}, [armed, dragging])

	const [before, after = ''] = useMemo(() => t('Click on [ICON] next to Dashboard to add widgets.').split('[ICON]'), [])

	if(rows.length === 0)
	{

		return (
			<Box sx={{minHeight: 280, display: 'grid', placeItems: 'center', textAlign: 'center', color: 'var(--u-muted)'}}>
				<Box>
					<Typography sx={{fontSize: 15, fontWeight: 500, color: 'var(--u-text2)'}}>{t('Your dashboard is empty.')}</Typography>
					<Typography sx={{mt: 0.5, fontSize: 13.5, color: 'var(--u-muted)'}}>{before}<AddCircleOutlineOutlined aria-label={t('Add widget')} sx={{fontSize: 17, mx: 0.25, verticalAlign: '-3px', color: 'primary.main'}} />{after}</Typography>
				</Box>
			</Box>
		)

	}

	const change = (next: Rows) => {

		if(!sameRows(next, rows))onChange(next)

	}

	const start = (e: DragEvent, w: Placed) => {

		e.dataTransfer.effectAllowed = 'move'
		e.dataTransfer.setData('text/plain', String(w.key))
		setDragging(w)

	}

	const end = () => {

		setDragging(null)
		setZone(null)
		setArmed(null)

	}

	const accept = (e: DragEvent, next: Zone) => {

		e.preventDefault()
		e.stopPropagation()
		e.dataTransfer.dropEffect = 'move'
		setZone((z) => JSON.stringify(z) === JSON.stringify(next) ? z : next)

	}

	// over a widget: its top / bottom third = new row, its middle = beside it (top / bottom when its row has no room)
	const overCard = (e: DragEvent, target: Placed, row: Placed[]) => {

		if(!dragging)return
		if(target.key === dragging.key)
		{

			e.preventDefault()
			e.stopPropagation()
			setZone(null)
			return

		}

		const box = e.currentTarget.getBoundingClientRect()
		const y = (e.clientY - box.top) / Math.max(1, box.height)
		let side: 'top' | 'bottom' | 'left' | 'right' = (y < 0.3) ? 'top' : ((y > 0.7) ? 'bottom' : ((e.clientX < box.left + box.width / 2) ? 'left' : 'right'))
		if((side === 'left' || side === 'right') && !fits(row, dragging))side = (y < 0.5) ? 'top' : 'bottom'

		accept(e, {key: target.key, side})

	}


	const drop = (e: DragEvent) => {

		e.preventDefault()
		if(dragging && zone)change(dropped(rows, dragging, zone))
		end()

	}

	// keyboard on the handle: left / right in the row, up / down = new row above / below (or into the row above / below when alone and it has room)
	const key = (e: KeyboardEvent, w: Placed) => {

		const r = rows.findIndex((row) => row.some((x) => x.key === w.key))
		const row = rows[r]
		const i = row.findIndex((x) => x.key === w.key)
		let next: Rows | null = null

		if(e.key === 'ArrowLeft' && i > 0)next = dropped(rows, w, {key: row[i - 1].key, side: 'left'})
		else if(e.key === 'ArrowRight' && i < row.length - 1)next = dropped(rows, w, {key: row[i + 1].key, side: 'right'})
		else if(e.key === 'ArrowUp' || e.key === 'ArrowDown')
		{

			const up = (e.key === 'ArrowUp')
			const other = row.find((x) => x.key !== w.key)
			const near = rows[up ? r - 1 : r + 1]

			if(other)next = dropped(rows, w, {key: other.key, side: up ? 'top' : 'bottom'})
			else if(near && fits(near, w))next = dropped(rows, w, {row: up ? r - 1 : r + 1, side: 'end'})
			else if(near)next = rows.map((x, n) => n === r ? near : (n === (up ? r - 1 : r + 1) ? row : x))

		}

		if(!next)return
		e.preventDefault()
		change(next)

	}

	const remove = (w: Placed) => change(rows.map((row) => row.filter((x) => x.key !== w.key)).filter((row) => row.length > 0))

	// one grid: each widget on the line of its row (desktop), in the order of the rows below (flow); the empty end of a row with room takes a drop
	return (
		<Box onDragOver={(e) => { if(dragging && zone)e.preventDefault() }} onDrop={drop}
			sx={{display: 'flex', flexDirection: 'column', gap: 2, '& .u-widget-tools': {opacity: 0, transition: 'opacity .15s'}, '& .u-widget:hover .u-widget-tools, & .u-widget:focus-within .u-widget-tools': {opacity: 1}, '@media (hover: none)': {'& .u-widget-tools': {opacity: 1}},
				'& .u-widget:hover .u-widget-handle .u-icon, & .u-widget-handle:focus-visible .u-icon': {display: 'none'}, '& .u-widget:hover .u-widget-handle .u-grip, & .u-widget-handle:focus-visible .u-grip': {display: 'block'}, ...(dragging ? {'& iframe': {pointerEvents: 'none'}} : {})}}>
			<Box sx={GRID_SX}>
				{rows.map((row, r) => row.map((w) => {

					// icon of the widget, replaced by the grip on hover (no empty space before the icon)
					const handle = (
						<Tooltip title={t('Move')} disableInteractive>
							<Box component="span" role="button" tabIndex={0} aria-label={`${t('Move')} ${w.name}`} className="u-widget-handle"
								onPointerDown={() => setArmed(w.key)} onKeyDown={(e) => key(e, w)}
								sx={{display: 'grid', placeItems: 'center', width: 20, height: 22, mx: -0.125, flexShrink: 0, borderRadius: '4px', color: 'var(--u-muted)', cursor: 'grab', '& .u-grip': {display: 'none'}, '&:hover': {bgcolor: 'var(--u-hover)', color: 'var(--u-text)'}, '&:focus-visible': {outline: '2px solid', outlineColor: 'primary.main'}}}>
								<Box component="span" className="u-icon" sx={{display: 'grid'}}><MenuIcon name={widgetIcon(w)} fontSize={18} color="currentColor" /></Box>
								<DragIndicatorOutlined className="u-grip" sx={{fontSize: 18}} />
							</Box>
						</Tooltip>
					)
					const tools = (
						<>
							{!!w.fields?.length && (
								<Tooltip title={t('Settings')}>
									<IconButton className="u-widget-tools" aria-label={`${t('Settings')} ${w.name}`} onClick={() => setSettings(w)}
										sx={{p: 0.25, flexShrink: 0, color: 'var(--u-muted)', '&:hover': {color: 'primary.main'}}}>
										<TuneOutlined sx={{fontSize: 17}} />
									</IconButton>
								</Tooltip>
							)}
							<Tooltip title={t('Remove')}>
								<IconButton className="u-widget-tools" aria-label={`${t('Remove')} ${w.name}`} onClick={() => remove(w)}
									sx={{p: 0.25, mr: -0.5, flexShrink: 0, color: 'var(--u-muted)', '&:hover': {color: 'var(--u-error)'}}}>
									<DeleteOutlineOutlined sx={{fontSize: 17}} />
								</IconButton>
							</Tooltip>
						</>
					)
					const mark = (zone && 'key' in zone && zone.key === w.key) ? MARK[zone.side] : null

					return (
						<Box key={w.key} className="u-widget" draggable={armed === w.key} onDragStart={(e) => start(e, w)} onDragOver={(e) => overCard(e, w, row)} onDragEnd={end}
							sx={{...widgetSpan(w.size), gridRow: {xs: 'auto', lg: r + 1}, position: 'relative', minWidth: 0, borderRadius: '12px', transition: 'opacity .15s', ...(dragging?.key === w.key ? {opacity: 0.4, outline: '2px dashed', outlineColor: 'primary.main', outlineOffset: '-2px'} : {})}}>
							{w.type === 'notes' && <Notes widget={w} handle={handle} tools={tools} />}
							{w.type === 'clock' && <ClockWidget widget={w} handle={handle} tools={tools} />}
							{w.type === 'url' && <UrlWidget widget={w} handle={handle} tools={tools} />}
							{w.type === 'data' && <WidgetCard widget={w} version={version} handle={handle} tools={tools} />}
							{mark && <Box aria-hidden sx={{position: 'absolute', ...mark, borderRadius: 2, bgcolor: 'primary.main', pointerEvents: 'none'}} />}
						</Box>
					)

				}))}
				{dragging && rows.map((row, r) => {

					// free end of the row (desktop only): a drop puts the widget at the end of this row
					const free = COLUMNS - width(row)
					if(free < span(dragging) || row[row.length - 1].key === dragging.key)return null

					const on = zone?.side === 'end' && zone.row === r
					return <Box key={`end-${r}`} onDragOver={(e) => accept(e, {row: r, side: 'end'})} sx={{display: {xs: 'none', lg: 'block'}, gridRow: r + 1, gridColumn: `span ${free}`, minHeight: 90, borderRadius: '12px', border: '2px dashed', borderColor: on ? 'primary.main' : 'transparent', bgcolor: on ? 'var(--u-accent-bg)' : 'transparent'}} />

				})}
			</Box>
			<WidgetSettingsDialog widget={settings} onClose={() => setSettings(null)} onSave={(config) => { const key = settings?.key; setSettings(null); change(rows.map((row) => row.map((x) => x.key === key ? {...x, config} : x))) }} />
			{dragging && (
				<Box onDragOver={(e) => accept(e, {side: 'last'})} sx={{height: 64, borderRadius: '12px', border: '2px dashed', borderColor: zone?.side === 'last' ? 'primary.main' : 'var(--u-border)', bgcolor: zone?.side === 'last' ? 'var(--u-accent-bg)' : 'transparent', display: 'grid', placeItems: 'center', fontSize: 12.5, color: 'var(--u-muted)'}}>
					{t('New row')}
				</Box>
			)}
		</Box>
	)

}
