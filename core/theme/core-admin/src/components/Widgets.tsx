import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { Link as RouterLink } from 'react-router'
import { Box, ButtonBase, CircularProgress, Typography } from '@mui/material'
import { ChevronRightOutlined, OpenInNewOutlined } from '@mui/icons-material'
import { api } from '../api'
import { getLanguage, t } from '../i18n'
import { Badge, BADGES, tab_sx, TabCount } from './DataGrid'
import { MenuIcon } from './icons'
import { relativeDay, relativeTime } from './time'
import UserAvatar, { type AvatarData } from './UserAvatar'

// width on the dashboard of 4 columns: 1, 2, 3 or 4 columns
export type WidgetSize = 'quarter' | 'half' | 'three_quarters' | 'full'

// widget of the dashboard (GET /api/dashboard/), its data comes from GET /api/dashboard/widgets/{id}/ (see \Core\Widget), a url widget is a https page in an iframe
export type WidgetInfo = {id: number, type: 'notes' | 'clock' | 'data' | 'url', name: string, icon: string, route: string, size: WidgetSize, position: number, refresh: number, multiple?: boolean, fields?: WidgetField[], url?: string, height?: number, key?: number, config?: Record<string, string>, title?: string}

// setting of a configurable widget (\Core\Widget::fields), set by the user on each widget he placed
export type WidgetField = {name: string, label: string, type: 'text' | 'number' | 'select' | 'boolean', default: string, options: {value: string, label: string}[]}

// widget placed on the dashboard: key = its id (xcore_user_widget, < 0 until saved), config = its settings
export type Placed = WidgetInfo & {key: number, config: Record<string, string>}

// title of a widget: the one set by the user, else its name
export const widgetTitle = (w: WidgetInfo) => w.title || w.name

// icon of a widget, generic one when empty
export const widgetIcon = (w: WidgetInfo) => w.icon || ({notes: 'sticky_note_2', clock: 'schedule', url: 'language'} as Record<string, string>)[w.type] || 'widgets'

// parts given by the dashboard to the header of a widget: drag handle (in place of the icon, shown on hover) and tools (after the title)
export type WidgetSlots = {handle?: ReactNode, tools?: ReactNode}

// columns taken by each size on a row of 4 (\Core\Widget::SPANS)
export const SPANS: Record<WidgetSize, number> = {quarter: 1, half: 2, three_quarters: 3, full: 4}

/**
 * columns taken by a widget: 4 on mobile, 2 or 4 on tablet, its size on desktop
 */
export function widgetSpan(size: WidgetSize)
{

	const span = SPANS[size] ?? 4
	return {gridColumn: {xs: 'span 4', sm: `span ${span <= 2 ? 2 : 4}`, lg: `span ${span}`}}

}

export const CARD_SX = {height: '100%', minWidth: 0, display: 'flex', flexDirection: 'column', bgcolor: 'var(--u-widget)', borderRadius: '12px'} as const
export const HEADER_SX = {display: 'flex', alignItems: 'center', gap: 1, px: 1.75, py: 1.25, minWidth: 0} as const

type Cell = string | {text?: string, strong?: boolean, badge?: string, muted?: boolean, mono?: boolean, grow?: boolean, align?: 'left' | 'right', color?: string, date?: string, day?: string, avatar?: AvatarData, title?: string, width?: number, link?: string, small?: boolean}
type Rows = {rows: Cell[][], empty?: string, filter?: {column: number, options: string[]}, scroll?: number, max_rows?: number}
type WidgetData =
	| ({type: 'rows'} & Rows)
	| {type: 'progress', items: {name: string, link?: string, color: string, percent: number, badge?: {text: string, color: string}}[], empty?: string}
	| {type: 'counters', status?: {label: string, badge: {text: string, color: string}}, note?: {date?: string, day?: string, prefix?: string, text?: string}, tiles: {value: number, label: string, color: string}[]}
	| ({type: 'weather'} & Weather)
	| {type: 'error', message: string}

// see \Core\Weather::widget(): temperatures in degrees, wind in km/h, rain in mm the next hour, icon = material symbol
type Weather = {city: string, country?: string, now: {temp: number | null, icon: string, label: string}, min: number | null, max: number | null, wind: number | null, rain: number | null, days: {day: string, min: number | null, max: number | null, icon: string, label: string}[], credit: {text: string, url: string}}

const degrees = (value: number | null) => value === null ? '-' : `${value}°`

/**
 * weather: current temperature and sky, on its right min / max then wind / rain, next days, credit of the data (CC BY 4.0)
 */
function WeatherView({data}: {data: Weather})
{

	// city and country, ex: Paris, France
	const place = [data.city, data.country].filter(Boolean).join(', ')
	const weekday = (day: string) => new Date(`${day}T12:00:00`).toLocaleDateString(getLanguage(), {weekday: 'short'}).replace('.', '')

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.5, pt: 0.25}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5, minWidth: 0}}>
				<MenuIcon name={data.now.icon} fontSize={44} color="var(--mui-palette-primary-main)" />
				<Box sx={{minWidth: 0}}>
					<Box sx={{fontSize: 30, fontWeight: 600, lineHeight: 1.1, fontVariantNumeric: 'tabular-nums'}}>{degrees(data.now.temp)}</Box>
					<Box sx={{fontSize: 12.5, color: 'var(--u-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}} title={`${data.now.label} - ${place}`}>{data.now.label}{place && ` - ${place}`}</Box>
				</Box>
				{/* right of the temperature: min / max, then wind / rain */}
				<Box sx={{ml: 'auto', flexShrink: 0, display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 0.25, fontSize: 12.5, color: 'var(--u-text2)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap'}}>
					<Box sx={{display: 'flex', gap: 1.25}}><span>{t('Min')} {degrees(data.min)}</span><span>{t('Max')} {degrees(data.max)}</span></Box>
					{(data.wind !== null || !!data.rain) && (
						<Box sx={{display: 'flex', gap: 1, fontSize: 11, color: 'var(--u-muted)'}}>
							{data.wind !== null && <span>{t('Wind')} {data.wind} km/h</span>}
							{!!data.rain && <span>{t('Rain')} {data.rain} mm</span>}
						</Box>
					)}
				</Box>
			</Box>
			{data.days.length > 0 && (
				<Box sx={{display: 'grid', gridTemplateColumns: `repeat(${data.days.length}, minmax(0, 1fr))`, gap: 0.5, pt: 1, borderTop: '1px solid var(--u-border-soft)'}}>
					{data.days.map((d) => (
						<Box key={d.day} title={d.label} sx={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 0.25, minWidth: 0, fontVariantNumeric: 'tabular-nums'}}>
							<Box sx={{fontSize: 11.5, color: 'var(--u-muted)', textTransform: 'capitalize'}}>{weekday(d.day)}</Box>
							<MenuIcon name={d.icon} fontSize={22} color="var(--u-text2)" />
							<Box sx={{fontSize: 12, whiteSpace: 'nowrap'}}><b>{degrees(d.max)}</b> <Box component="span" sx={{color: 'var(--u-muted)'}}>{degrees(d.min)}</Box></Box>
						</Box>
					))}
				</Box>
			)}
			<Box component="a" href={data.credit.url} target="_blank" rel="noopener noreferrer" sx={{alignSelf: 'flex-end', fontSize: 10.5, color: 'var(--u-muted)', textDecoration: 'none', '&:hover': {textDecoration: 'underline'}}}>{t('Data: [SOURCE] (CC BY 4.0)', {SOURCE: data.credit.text})}</Box>
		</Box>
	)

}

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

function RowsView({rows, empty, filter, scroll, max_rows}: Rows)
{

	// options of the filter switched off by the user
	const [off, setOff] = useState<string[]>([])
	const list = useRef<HTMLDivElement>(null)
	const [fit, setFit] = useState(0)
	const count = filter ? rows.filter((r) => !off.includes(optionOf(r, filter.column))).length : rows.length

	// max_rows: height of the first rows measured, the others scrolled (scroll in px wins)
	useLayoutEffect(() => {

		const el = list.current
		const last = (!scroll && max_rows && count > max_rows) ? el?.children[max_rows - 1] as HTMLElement | undefined : undefined
		setFit(last ? last.offsetTop + last.offsetHeight : 0)

	}, [scroll, max_rows, count])

	if(!rows.length)return <Typography sx={{py: 2, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{empty || t('Nothing to show')}</Typography>

	// a column with a grow cell takes the free space (table at 100%), otherwise the table is sized to its content
	const grow = Array.from({length: Math.max(...rows.map((r) => r.length))}, (_, j) => rows.some((r) => typeof r[j] === 'object' && r[j].grow))
	const full = grow.includes(true)

	const shown = filter ? rows.filter((r) => !off.includes(optionOf(r, filter.column))) : rows

	return (
		<>
			{filter && <FilterChips rows={rows} filter={filter} off={off} setOff={setOff} />}
			{/* columns aligned by a subgrid, each row keeps its own hover; with scroll (height in px) or max_rows, the rows beyond are scrolled */}
			{/* rows on the inner background of the widget */}
			<Box sx={{bgcolor: 'var(--u-widget-inner)', borderRadius: '10px', p: 0.5}}>
				<Box ref={list} sx={{position: 'relative', display: 'grid', gridTemplateColumns: grow.map((g) => g ? 'minmax(0, 1fr)' : 'auto').join(' '), columnGap: 1.5, width: full ? '100%' : 'fit-content', maxWidth: '100%', ...((scroll || fit) ? {height: scroll || fit, alignContent: 'start', overflowY: 'auto', overflowX: 'hidden', scrollbarGutter: 'stable'} : {})}}>
					{shown.map((row, i) => (
						<Box key={i} sx={{display: 'grid', gridColumn: '1 / -1', gridTemplateColumns: 'subgrid', alignItems: 'center', minHeight: 32, py: 0.25, px: 1, borderRadius: '4px', borderBottom: i < shown.length - 1 ? '1px solid var(--u-border-soft)' : 0, transition: 'background-color .15s', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
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

	// error centered in the card
	if(data.type === 'error')return <Box sx={{flexGrow: 1, minHeight: 80, display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', fontSize: 13, color: 'var(--u-error)'}}>{data.message}</Box>
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

	if(data.type === 'weather')return <WeatherView data={data} />

	if(data.type === 'counters')
	{

		return (
			<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.25, pt: 0.5}}>
				{(data.status || data.note) && (
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 12.5, color: 'var(--u-muted)'}}>
						{data.status && <>{data.status.label}<Badge value={data.status.badge.text} color={data.status.badge.color} /></>}
						{data.note && <Box component="span" title={data.note.date ? new Date(data.note.date).toLocaleString() : (data.note.day ? new Date(`${data.note.day}T00:00:00`).toLocaleDateString() : undefined)} sx={{ml: 'auto', fontSize: 11.5}}>{(data.note.date || data.note.day) ? `${data.note.prefix ?? ''} ${data.note.date ? relativeTime(data.note.date) : relativeDay(data.note.day ?? '')}`.trim() : data.note.text}</Box>}
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
export function WidgetCard({widget, version, handle, tools}: {widget: WidgetInfo, version: number} & WidgetSlots)
{

	const [data, setData] = useState<WidgetData | null>(null)
	// settings changed: data loaded again
	const config = JSON.stringify(widget.config ?? {})
	const [, setTick] = useState(0)

	useEffect(() => {

		let alive = true
		// a widget just added has no key yet: loaded once saved
		if(!widget.key || widget.key < 0)return
		const load = () => api<WidgetData>(`dashboard/widgets/${widget.key}/`).then((d) => alive && setData(d)).catch((e) => alive && setData({type: 'error', message: e.message}))
		setData(null)
		load()

		const timer = widget.refresh ? window.setInterval(load, widget.refresh * 1000) : 0
		// relative times stay right between two loads
		const clock = window.setInterval(() => setTick((v) => v + 1), 30000)
		return () => { alive = false; window.clearInterval(timer); window.clearInterval(clock) }

	}, [widget.key, widget.refresh, version, config])

	return (
		<Box component="section" aria-label={widgetTitle(widget)} sx={CARD_SX}>
			<Box sx={HEADER_SX}>
				{handle ?? <MenuIcon name={widgetIcon(widget)} fontSize={18} color="var(--u-muted)" />}
				<Typography component="h2" noWrap sx={{...LABEL_SX, flexGrow: 1}}>{widgetTitle(widget)}</Typography>
				{widget.route && <Box component={RouterLink} to={`/${widget.route}/`} sx={{display: 'flex', alignItems: 'center', flexShrink: 0, fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', textDecoration: 'none', '&:hover': {textDecoration: 'underline'}}}>{t('View all')}<ChevronRightOutlined sx={{fontSize: 16}} /></Box>}
				{tools}
			</Box>
			<Box sx={{px: 1.75, py: 0.75, flexGrow: 1, display: 'flex', flexDirection: 'column'}}>
				{data ? <Body data={data} /> : <Box sx={{display: 'flex', justifyContent: 'center', py: 3}}><CircularProgress size={22} /></Box>}
			</Box>
		</Box>
	)

}

/**
 * url widget: https page in a sandboxed iframe of its height, link to open it in a new tab
 * the site must allow to be framed (X-Frame-Options, CSP frame-ancestors), otherwise the browser shows it empty
 */
export function UrlWidget({widget, handle, tools}: {widget: WidgetInfo} & WidgetSlots)
{

	const url = (widget.url ?? '').toLowerCase().startsWith('https://') ? widget.url ?? '' : ''

	return (
		<Box component="section" aria-label={widgetTitle(widget)} sx={CARD_SX}>
			<Box sx={HEADER_SX}>
				{handle ?? <MenuIcon name={widgetIcon(widget)} fontSize={18} color="var(--u-muted)" />}
				<Typography component="h2" noWrap sx={{...LABEL_SX, flexGrow: 1}}>{widgetTitle(widget)}</Typography>
				{url && <Box component="a" href={url} target="_blank" rel="noopener noreferrer" sx={{display: 'flex', alignItems: 'center', gap: 0.5, flexShrink: 0, fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', textDecoration: 'none', '&:hover': {textDecoration: 'underline'}}}>{t('Open')}<OpenInNewOutlined sx={{fontSize: 14}} /></Box>}
				{tools}
			</Box>
			<Box sx={{px: 1.75, pb: 1.75, pt: 0.25, flexGrow: 1, display: 'flex', flexDirection: 'column'}}>
				{url
					? <Box component="iframe" src={url} title={widgetTitle(widget)} loading="lazy" referrerPolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox" sx={{display: 'block', width: '100%', height: widget.height ?? 300, border: 0, borderRadius: '8px', bgcolor: 'var(--u-widget-inner)'}} />
					: <Body data={{type: 'error', message: t('invalid url')}} />}
			</Box>
		</Box>
	)

}

// hour, minute, second (and AM / PM) in a time zone, the time of the computer when the zone is unknown
function clockParts(now: Date, zone: string | undefined, h12: boolean): Record<string, string>
{

	const options: Intl.DateTimeFormatOptions = {hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: h12}
	try
	{

		return Object.fromEntries(new Intl.DateTimeFormat('en-US', {...options, timeZone: zone}).formatToParts(now).map((p) => [p.type, p.value]))

	}
	catch
	{

		return Object.fromEntries(new Intl.DateTimeFormat('en-US', options).formatToParts(now).map((p) => [p.type, p.value]))

	}

}

// long date in the language of the user, in a time zone
function clockDate(now: Date, zone: string | undefined): string
{

	const options: Intl.DateTimeFormatOptions = {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'}
	try
	{

		return now.toLocaleDateString(getLanguage(), {...options, timeZone: zone})

	}
	catch
	{

		return now.toLocaleDateString(getLanguage(), options)

	}

}

/**
 * clock widget (\Core\Widget::CLOCK): time and date in the language of the user, refreshed every second, settings time zone, format, seconds, date
 */
export function ClockWidget({widget, handle, tools}: {widget: WidgetInfo} & WidgetSlots)
{

	const [now, setNow] = useState(() => new Date())

	useEffect(() => {

		const timer = window.setInterval(() => setNow(new Date()), 1000)
		return () => window.clearInterval(timer)

	}, [])

	// settings: time zone (browser = time of the computer), format 24 / 12, seconds and date yes / no
	const config = widget.config ?? {}
	const zone = (config.timezone && config.timezone !== 'browser') ? config.timezone : undefined
	const h12 = (config.format === '12')
	const time = clockParts(now, zone, h12)
	const date = clockDate(now, zone)

	return (
		<Box component="section" aria-label={widgetTitle(widget)} sx={CARD_SX}>
			<Box sx={HEADER_SX}>
				{handle ?? <MenuIcon name={widgetIcon(widget)} fontSize={18} color="var(--u-muted)" />}
				<Typography component="h2" noWrap sx={{...LABEL_SX, flexGrow: 1}}>{widgetTitle(widget)}</Typography>
				{tools}
			</Box>
			<Box component="time" dateTime={now.toISOString()} sx={{px: 1.75, pb: 2, pt: 0.5, flexGrow: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', textAlign: 'center', fontVariantNumeric: 'tabular-nums'}}>
				<Box sx={{fontSize: 38, fontWeight: 600, lineHeight: 1.1, letterSpacing: '-0.01em', color: 'primary.main', whiteSpace: 'nowrap'}}>
					{time.hour}:{time.minute}
					{config.seconds !== 'no' && <Box component="span" sx={{ml: 0.5, fontSize: 16, fontWeight: 500, color: 'var(--u-accent-text)', opacity: 0.6}}>{time.second}</Box>}
					{h12 && <Box component="span" sx={{ml: 0.5, fontSize: 14, fontWeight: 600, color: 'var(--u-accent-text)'}}>{time.dayPeriod}</Box>}
				</Box>
				{config.date !== 'no' && <Box sx={{mt: 0.5, fontSize: 13, color: 'var(--u-muted)', '&::first-letter': {textTransform: 'uppercase'}}}>{date}</Box>}
				{zone && <Box sx={{mt: 0.25, fontSize: 11.5, color: 'var(--u-muted)'}}>{zone.split('/').pop()?.replace(/_/g, ' ')}</Box>}
			</Box>
		</Box>
	)

}

/**
 * grid of widgets: 4 columns on desktop, 2 on tablet, 1 on mobile (see widgetSpan)
 */
export const GRID_SX = {display: 'grid', gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: 2} as const

export function WidgetGrid({children}: {children: ReactNode})
{

	return <Box sx={GRID_SX}>{children}</Box>

}
