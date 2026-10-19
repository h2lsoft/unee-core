import { useEffect, useState, type ReactNode } from 'react'
import { Link as RouterLink } from 'react-router'
import { Box, ButtonBase, CircularProgress, Typography } from '@mui/material'
import { ChevronRightOutlined } from '@mui/icons-material'
import { api } from '../api'
import { t } from '../i18n'
import { Badge, BADGES } from './DataGrid'
import { MenuIcon } from './icons'
import { relativeDay, relativeTime } from './time'
import UserAvatar, { type AvatarData } from './UserAvatar'

// widget of the dashboard (GET /api/dashboard/), its data comes from GET /api/dashboard/widgets/{id}/ (see \Core\Widget)
export type WidgetInfo = {id: number, name: string, icon: string, route: string, size: 'full' | 'half', position: number, refresh: number}

type Cell = string | {text?: string, strong?: boolean, badge?: string, muted?: boolean, mono?: boolean, grow?: boolean, align?: 'left' | 'right', color?: string, date?: string, day?: string, avatar?: AvatarData, title?: string, width?: number}
type Rows = {rows: Cell[][], empty?: string}
type WidgetData =
	| ({type: 'rows'} & Rows)
	| {type: 'progress', items: {name: string, color: string, percent: number, badge?: {text: string, color: string}}[], empty?: string}
	| {type: 'counters', status?: {label: string, badge: {text: string, color: string}}, note?: {date?: string, prefix?: string, text?: string}, tiles: {value: number, label: string, color: string}[]}
	| {type: 'tabs', tabs: ({label: string} & Rows)[]}
	| {type: 'error', message: string}

const LABEL_SX = {fontSize: 12, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--u-muted)'}

function CellView({cell}: {cell: Cell})
{

	if(typeof cell === 'string')return <Box component="span" sx={{fontSize: 13.5}}>{cell}</Box>
	if(cell.avatar)return <UserAvatar avatar={cell.avatar} title={cell.title} />
	if(cell.badge)return <Badge value={cell.text ?? ''} color={cell.badge} />

	const text = cell.date ? relativeTime(cell.date) : (cell.day ? relativeDay(cell.day) : cell.text ?? '')
	return (
		<Box component="span" title={cell.date ? new Date(cell.date).toLocaleString() : undefined} sx={{display: 'block', fontSize: (cell.mono || cell.muted || cell.date || cell.day) ? 12.5 : 13.5, fontWeight: cell.strong ? 500 : 400, fontFamily: cell.mono ? 'ui-monospace, SFMono-Regular, Consolas, monospace' : undefined, color: cell.color === 'error' ? 'var(--u-error)' : ((cell.muted || cell.date || cell.day) ? 'var(--u-muted)' : 'var(--u-text)'), textAlign: cell.align ?? 'left', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>
			{text}
		</Box>
	)

}

function RowsView({rows, empty}: Rows)
{

	if(!rows.length)return <Typography sx={{py: 2, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{empty || t('Nothing to show')}</Typography>

	return (
		<Box>
			{rows.map((row, i) => (
				<Box key={i} sx={{display: 'flex', alignItems: 'center', gap: 1.5, minHeight: 40, py: 0.5, borderBottom: i < rows.length - 1 ? '1px solid var(--u-border-soft)' : 0}}>
					{row.map((cell, j) => {

						const c = typeof cell === 'string' ? {} : cell
						return <Box key={j} sx={{minWidth: 0, flex: c.grow ? '1 1 0' : '0 1 auto', width: c.grow ? undefined : c.width, overflow: 'hidden', display: 'flex', justifyContent: c.align === 'right' ? 'flex-end' : 'flex-start'}}><CellView cell={cell} /></Box>

					})}
				</Box>
			))}
		</Box>
	)

}

function Body({data}: {data: WidgetData})
{

	const [tab, setTab] = useState(0)

	if(data.type === 'error')return <Typography sx={{fontSize: 13, color: 'var(--u-error)'}}>{data.message}</Typography>
	if(data.type === 'rows')return <RowsView rows={data.rows} empty={data.empty} />

	if(data.type === 'progress')
	{

		if(!data.items.length)return <Typography sx={{py: 2, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{data.empty || t('Nothing to show')}</Typography>

		return (
			<Box>
				{data.items.map((item, i) => (
					<Box key={i} sx={{display: 'grid', gridTemplateColumns: {xs: '1fr 48px', sm: 'minmax(140px, 220px) 1fr 48px 90px'}, gap: 1.75, alignItems: 'center', minHeight: 40, borderBottom: i < data.items.length - 1 ? '1px solid var(--u-border-soft)' : 0}}>
						<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 13.5, fontWeight: 500, minWidth: 0}}><Box component="span" sx={{width: 8, height: 8, flexShrink: 0, borderRadius: '50%', bgcolor: item.color}} /><Box component="span" sx={{overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{item.name}</Box></Box>
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

						const c = BADGES[tile.color] ?? BADGES.grey
						return (
							<Box key={tile.label} sx={{py: 1.75, px: 1, borderRadius: '5px', bgcolor: c.bg, color: c.fg, textAlign: 'center'}}>
								<Box sx={{fontSize: 20, fontWeight: 600, fontVariantNumeric: 'tabular-nums'}}>{tile.value.toLocaleString()}</Box>
								<Box sx={{fontSize: 12, fontWeight: 500}}>{tile.label}</Box>
							</Box>
						)

					})}
				</Box>
			</Box>
		)

	}

	const current = data.tabs[Math.min(tab, data.tabs.length - 1)]
	return (
		<>
			<Box role="tablist" sx={{display: 'flex', gap: 0.5, mb: 0.5}}>
				{data.tabs.map((tb, i) => (
					<ButtonBase key={tb.label} role="tab" aria-selected={i === tab} onClick={() => setTab(i)} sx={{height: 32, px: 1.5, borderBottom: '2px solid', borderColor: i === tab ? 'primary.main' : 'transparent', fontSize: 12.5, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: i === tab ? 'var(--u-accent-text)' : 'var(--u-text3)'}}>{tb.label}</ButtonBase>
				))}
			</Box>
			{current && <RowsView rows={current.rows} empty={current.empty} />}
		</>
	)

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
		<Box component="section" aria-label={widget.name} sx={{gridColumn: {xs: 'span 2', md: widget.size === 'half' ? 'span 1' : 'span 2'}, minWidth: 0, display: 'flex', flexDirection: 'column', bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '5px'}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, px: 1.75, py: 1.25, borderBottom: '1px solid var(--u-border-soft)'}}>
				<MenuIcon name={widget.icon} fontSize={18} color="var(--u-muted)" />
				<Typography component="h2" sx={{...LABEL_SX, flexGrow: 1}}>{widget.name}</Typography>
				<Box component={RouterLink} to={`/${widget.route}/`} sx={{display: 'flex', alignItems: 'center', fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', textDecoration: 'none', '&:hover': {textDecoration: 'underline'}}}>{t('View all')}<ChevronRightOutlined sx={{fontSize: 16}} /></Box>
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
