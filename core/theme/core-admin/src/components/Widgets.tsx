import { useEffect, useState, type ReactNode } from 'react'
import { Link as RouterLink } from 'react-router'
import { Box, ButtonBase, CircularProgress, Typography } from '@mui/material'
import { ChevronRightOutlined } from '@mui/icons-material'
import { api } from '../api'
import { t } from '../i18n'
import { Badge, BADGES, tab_sx, TabCount } from './DataGrid'
import { MenuIcon } from './icons'
import { relativeDay, relativeTime } from './time'
import UserAvatar, { type AvatarData } from './UserAvatar'

// widget of the dashboard (GET /api/dashboard/), its data comes from GET /api/dashboard/widgets/{id}/ (see \Core\Widget)
export type WidgetInfo = {id: number, type: 'notes' | 'data', name: string, icon: string, route: string, size: 'full' | 'half', position: number, refresh: number}

type Cell = string | {text?: string, strong?: boolean, badge?: string, muted?: boolean, mono?: boolean, grow?: boolean, align?: 'left' | 'right', color?: string, date?: string, day?: string, avatar?: AvatarData, title?: string, width?: number, link?: string, small?: boolean}
type Rows = {rows: Cell[][], empty?: string, filter?: {column: number, options: string[]}, scroll?: number}
type WidgetData =
	| ({type: 'rows'} & Rows)
	| {type: 'progress', items: {name: string, link?: string, color: string, percent: number, badge?: {text: string, color: string}}[], empty?: string}
	| {type: 'counters', status?: {label: string, badge: {text: string, color: string}}, note?: {date?: string, prefix?: string, text?: string}, tiles: {value: number, label: string, color: string}[]}
	| {type: 'error', message: string}

export const LABEL_SX = {fontSize: 12, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--u-muted)'}

function CellView({cell}: {cell: Cell})
{

	if(typeof cell === 'string')return <Box component="span" sx={{fontSize: 13.5}}>{cell}</Box>
	if(cell.avatar)return <UserAvatar avatar={cell.avatar} title={cell.title} />
	if(cell.badge)return <Badge value={cell.text ?? ''} color={cell.badge} />

	const text = cell.date ? relativeTime(cell.date) : (cell.day ? relativeDay(cell.day) : cell.text ?? '')
	if(cell.link)return <Box component={RouterLink} to={`/${cell.link}`} sx={{fontSize: 13.5, fontWeight: cell.strong ? 500 : 400, color: 'var(--u-text)', textDecoration: 'none', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', '&:hover': {color: 'primary.main', textDecoration: 'underline'}}}>{text}</Box>
	return (
		<Box component="span" title={cell.date ? new Date(cell.date).toLocaleString() : undefined} sx={{display: 'block', fontSize: cell.small ? 11.5 : ((cell.mono || cell.muted || cell.date || cell.day) ? 12.5 : 13.5), fontWeight: cell.strong ? 500 : 400, fontFamily: cell.mono ? 'ui-monospace, SFMono-Regular, Consolas, monospace' : undefined, color: cell.color === 'error' ? 'var(--u-error)' : ((cell.muted || cell.date || cell.day) ? 'var(--u-muted)' : 'var(--u-text)'), textAlign: cell.align ?? 'left', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>
			{text}
		</Box>
	)

}

// text of the filter column of a row
const optionOf = (row: Cell[], column: number) => {

	const c = row[column]
	return typeof c === 'string' ? c : c?.text ?? ''

}

// chips of a rows filter, above the rows: each option toggled on or off
function FilterChips({rows, filter, off, setOff}: Required<Pick<Rows, 'filter'>> & Rows & {off: string[], setOff: (fn: (list: string[]) => string[]) => void})
{

	return (
		<Box sx={{display: 'flex', gap: 0.5, mb: 0.75}}>
			{filter.options.map((o) => {

				const on = !off.includes(o)
				return <ButtonBase key={o} aria-pressed={on} sx={tab_sx(on)} onClick={() => setOff((list) => on ? [...list, o] : list.filter((v) => v !== o))}>{o}<TabCount value={rows.filter((r) => optionOf(r, filter.column) === o).length} on={on} /></ButtonBase>

			})}
		</Box>
	)

}

function RowsView({rows, empty, filter, scroll}: Rows)
{

	// options of the filter switched off by the user
	const [off, setOff] = useState<string[]>([])

	if(!rows.length)return <Typography sx={{py: 2, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{empty || t('Nothing to show')}</Typography>

	// a column with a grow cell takes the free space (table at 100%), otherwise the table is sized to its content
	const grow = Array.from({length: Math.max(...rows.map((r) => r.length))}, (_, j) => rows.some((r) => typeof r[j] === 'object' && r[j].grow))
	const full = grow.includes(true)

	const shown = filter ? rows.filter((r) => !off.includes(optionOf(r, filter.column))) : rows

	return (
		<>
			{filter && <FilterChips rows={rows} filter={filter} off={off} setOff={setOff} />}
			{/* columns aligned by a subgrid, each row keeps its own hover; with scroll (height in px), the rows beyond are scrolled */}
			{/* rows on the inner background of the widget */}
			<Box sx={{bgcolor: 'var(--u-widget-inner)', borderRadius: '10px', p: 0.5}}>
				<Box sx={{display: 'grid', gridTemplateColumns: grow.map((g) => g ? 'minmax(0, 1fr)' : 'auto').join(' '), columnGap: 1.5, width: full ? '100%' : 'fit-content', maxWidth: '100%', ...(scroll ? {height: scroll, alignContent: 'start', overflowY: 'auto', overflowX: 'hidden', scrollbarGutter: 'stable'} : {})}}>
					{shown.map((row, i) => (
						<Box key={i} sx={{display: 'grid', gridColumn: '1 / -1', gridTemplateColumns: 'subgrid', alignItems: 'center', minHeight: 40, py: 0.5, px: 1, borderRadius: '4px', borderBottom: i < shown.length - 1 ? '1px solid var(--u-border-soft)' : 0, transition: 'background-color .15s', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
							{row.map((cell, j) => {
	
								const c = typeof cell === 'string' ? {} : cell
								return <Box key={j} sx={{minWidth: 0, width: c.width, overflow: 'hidden', display: 'flex', justifyContent: c.align === 'right' ? 'flex-end' : 'flex-start'}}><CellView cell={cell} /></Box>
	
							})}
						</Box>
					))}
				</Box>
			</Box>
		</>
	)

}

function Body({data}: {data: WidgetData})
{

	if(data.type === 'error')return <Typography sx={{fontSize: 13, color: 'var(--u-error)'}}>{data.message}</Typography>
	if(data.type === 'rows')return <RowsView {...data} />

	if(data.type === 'progress')
	{

		if(!data.items.length)return <Typography sx={{py: 2, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{data.empty || t('Nothing to show')}</Typography>

		return (
			<Box sx={{bgcolor: 'var(--u-widget-inner)', borderRadius: '10px', px: 1.5}}>
				{data.items.map((item, i) => (
					<Box key={i} sx={{display: 'grid', gridTemplateColumns: {xs: '1fr 48px', sm: 'minmax(140px, 220px) 1fr 48px 90px'}, gap: 1.75, alignItems: 'center', minHeight: 40, borderBottom: i < data.items.length - 1 ? '1px solid var(--u-border-soft)' : 0}}>
						<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 13.5, fontWeight: 500, minWidth: 0}}><Box component="span" sx={{width: 8, height: 8, flexShrink: 0, borderRadius: '50%', bgcolor: item.color}} />{item.link
							? <Box component={RouterLink} to={`/${item.link}`} sx={{overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'inherit', textDecoration: 'none', '&:hover': {color: 'primary.main', textDecoration: 'underline'}}}>{item.name}</Box>
							: <Box component="span" sx={{overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{item.name}</Box>}</Box>
						<Box sx={{display: {xs: 'none', sm: 'block'}, height: 6, borderRadius: 3, bgcolor: 'var(--u-border-soft)', overflow: 'hidden'}}><Box sx={{height: '100%', width: `${Math.max(0, Math.min(100, item.percent))}%`, bgcolor: item.color}} /></Box>
						<Box sx={{fontSize: 12.5, color: 'var(--u-muted)', textAlign: 'right', fontVariantNumeric: 'tabular-nums'}}>{item.percent}%</Box>
						<Box sx={{display: {xs: 'none', sm: 'flex'}, justifyContent: 'flex-end'}}>{item.badge && <Badge value={item.badge.text} color={item.badge.color} />}</Box>
					</Box>
				))}
			</Box>
		)

	}

	if(data.type === 'counters')
	{

		return (
			<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.25, pt: 0.5}}>
				{(data.status || data.note) && (
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 12.5, color: 'var(--u-muted)'}}>
						{data.status && <>{data.status.label}<Badge value={data.status.badge.text} color={data.status.badge.color} /></>}
						{data.note && <Box component="span" sx={{ml: 'auto'}}>{data.note.date ? `${data.note.prefix ?? ''} ${relativeTime(data.note.date)}`.trim() : data.note.text}</Box>}
					</Box>
				)}
				<Box sx={{display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(90px, 1fr))', gap: 1}}>
					{data.tiles.map((tile) => {

						// a tile at 0 is not colored: white background, grey text
						const c = (tile.value === 0) ? {bg: 'var(--u-widget-inner)', fg: 'var(--u-muted)'} : (BADGES[tile.color] ?? BADGES.grey)
						return (
							<Box key={tile.label} sx={{py: 1.75, px: 1, borderRadius: '8px', bgcolor: c.bg, color: c.fg, textAlign: 'center'}}>
								<Box sx={{fontSize: 20, fontWeight: 600, fontVariantNumeric: 'tabular-nums'}}>{tile.value.toLocaleString()}</Box>
								<Box sx={{fontSize: 12, fontWeight: 500}}>{tile.label}</Box>
							</Box>
						)

					})}
				</Box>
			</Box>
		)

	}

	return null

}

/**
 * card of a widget: title linked to its plugin, data loaded on mount and refreshed every `refresh` seconds
 */
export function WidgetCard({widget, version}: {widget: WidgetInfo, version: number})
{

	const [data, setData] = useState<WidgetData | null>(null)
	const [, setTick] = useState(0)

	useEffect(() => {

		let alive = true
		const load = () => api<WidgetData>(`dashboard/widgets/${widget.id}/`).then((d) => alive && setData(d)).catch((e) => alive && setData({type: 'error', message: e.message}))
		load()

		const timer = widget.refresh ? window.setInterval(load, widget.refresh * 1000) : 0
		// relative times stay right between two loads
		const clock = window.setInterval(() => setTick((v) => v + 1), 30000)
		return () => { alive = false; window.clearInterval(timer); window.clearInterval(clock) }

	}, [widget.id, widget.refresh, version])

	return (
		<Box component="section" aria-label={widget.name} sx={{gridColumn: {xs: 'span 2', md: widget.size === 'half' ? 'span 1' : 'span 2'}, minWidth: 0, display: 'flex', flexDirection: 'column', bgcolor: 'var(--u-widget)', borderRadius: '12px'}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, px: 1.75, py: 1.25}}>
				<MenuIcon name={widget.icon} fontSize={18} color="var(--u-muted)" />
				<Typography component="h2" sx={{...LABEL_SX, flexGrow: 1}}>{widget.name}</Typography>
				{widget.route && <Box component={RouterLink} to={`/${widget.route}/`} sx={{display: 'flex', alignItems: 'center', fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', textDecoration: 'none', '&:hover': {textDecoration: 'underline'}}}>{t('View all')}<ChevronRightOutlined sx={{fontSize: 16}} /></Box>}
			</Box>
			<Box sx={{px: 1.75, py: 0.75, flexGrow: 1}}>
				{data ? <Body data={data} /> : <Box sx={{display: 'flex', justifyContent: 'center', py: 3}}><CircularProgress size={22} /></Box>}
			</Box>
		</Box>
	)

}

/**
 * grid of widgets: a full widget takes 2 columns, a half one 1 (one column on mobile)
 */
export function WidgetGrid({children}: {children: ReactNode})
{

	return <Box sx={{display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 2}}>{children}</Box>

}
