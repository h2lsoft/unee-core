import { useEffect, useMemo, useState, type KeyboardEvent } from 'react'
import { useNavigate } from 'react-router'
import { Box, ButtonBase, CircularProgress, Dialog, IconButton, InputBase, MenuItem, Select, Typography, useMediaQuery, useTheme } from '@mui/material'
import { ChevronRightOutlined, CloseOutlined, SearchOutlined } from '@mui/icons-material'
import { api, type MenuCategory } from '../api'
import { t } from '../i18n'
import { MenuIcon } from './icons'

// GET /api/backend/search/ (BackendController::search)
type SearchResult = {
	modules: {route: string, name: string, icon: string, menu: string}[]
	groups: {route: string, title: string, icon: string, total: number, rows: {id: number, title: string, sub: string}[]}[]
	actions: {route: string, label: string, icon: string}[]
}
type Entry = {kind: 'module' | 'record' | 'action' | 'recent', title: string, sub: string, icon: string, to: string, route: string}
type Group = {title: string, items: Entry[], more?: {label: string, to: string}}

// results shown by group, See all opens the module with the search
const MAX = 3
const RECENT_KEY = 'unee.search.recent'

function readRecent(): Entry[]
{

	try
	{

		const list = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]')
		return Array.isArray(list) ? list.slice(0, 5) : []

	}
	catch
	{

		return []

	}

}

function remember(entry: Entry)
{

	try
	{

		localStorage.setItem(RECENT_KEY, JSON.stringify([{...entry, kind: 'recent'}, ...readRecent().filter((r) => r.to !== entry.to || r.title !== entry.title)].slice(0, 5)))

	}
	catch
	{

		// private mode: no recent entries

	}

}

function Highlight({text, q}: {text: string, q: string})
{

	const i = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1
	if(i < 0)return <>{text}</>
	return <>{text.slice(0, i)}<Box component="mark" sx={{bgcolor: '#fff1b8', color: '#1f2430', borderRadius: '2px', px: '1px'}}>{text.slice(i, i + q.length)}</Box>{text.slice(i + q.length)}</>

}

const KBD_SX = {display: 'inline-flex', alignItems: 'center', height: 20, px: 0.75, border: '1px solid var(--u-border-strong)', borderBottomWidth: 2, borderRadius: '4px', fontSize: 11, fontWeight: 500, color: 'var(--u-text2)', bgcolor: 'var(--u-paper)', fontFamily: 'ui-monospace, SFMono-Regular, Consolas, monospace'}
const Kbd = ({children}: {children: string}) => <Box component="span" sx={KBD_SX}>{children}</Box>

/**
 * global search (icon of the header, Ctrl+/): modules, records (3 by module), add actions and configuration (Globals), recent entries when empty
 * a module or All on the left limits the search; Enter opens, Ctrl+Enter opens the module filtered, Tab searches in the module of the active entry, Esc clears then closes
 */
export default function SearchDialog({open, onClose, menu}: {open: boolean, onClose: () => void, menu: MenuCategory[] | null})
{

	const theme = useTheme()
	const full_screen = useMediaQuery(theme.breakpoints.down('md'))
	const navigate = useNavigate()
	const [q, setQ] = useState('')
	const [scope, setScope] = useState('')
	const [data, setData] = useState<SearchResult | null>(null)
	const [loading, setLoading] = useState(false)
	const [active, setActive] = useState(0)

	// modules of the scope select: the items of the menu with a list
	const scopes = useMemo(() => (menu ?? []).flatMap((c) => c.items).filter((i) => !i.url && i.route !== 'calendar'), [menu])
	const term = q.trim()

	// every opening starts from the initial state: empty search, All
	useEffect(() => {

		if(!open)return
		setQ('')
		setScope('')
		setActive(0)

	}, [open])

	// search 250 ms after the last key, 2 characters at least, the answer of an older query is dropped
	useEffect(() => {

		if(!open || term.length < 2)
		{

			setData(null)
			setLoading(false)
			return

		}

		let alive = true
		setLoading(true)
		const timer = window.setTimeout(() => {

			api<SearchResult>(`backend/search/?q=${encodeURIComponent(term)}&module=${encodeURIComponent(scope)}`)
				.then((d) => { if(alive){ setData(d); setActive(0) } })
				.catch(() => alive && setData({modules: [], groups: [], actions: []}))
				.finally(() => alive && setLoading(false))

		}, 250)

		return () => { alive = false; window.clearTimeout(timer) }

	}, [term, scope, open])

	const filtered = (route: string) => `/${route}/?search[]=${encodeURIComponent(`q||${term}`)}`

	const groups = useMemo<Group[]>(() => {

		if(term.length < 2)
		{

			const recent = readRecent()
			return recent.length ? [{title: t('Recent'), items: recent}] : []

		}
		if(!data)return []

		const group = (g: SearchResult['groups'][number], title: string): Group => ({
			title,
			items: g.rows.slice(0, MAX).map((r) => ({kind: 'record', title: r.title, sub: r.sub, icon: g.icon, to: filtered(g.route), route: g.route})),
			more: (g.total > MAX) ? {label: t('See all [COUNT]', {COUNT: g.total}), to: filtered(g.route)} : undefined,
		})

		const list: Group[] = []
		if(data.modules.length)list.push({title: t('Modules'), items: data.modules.slice(0, MAX).map((m) => ({kind: 'module', title: m.name, sub: m.menu, icon: m.icon, to: `/${m.route}/`, route: m.route}))})
		for(const g of data.groups.filter((x) => x.route !== 'globals'))list.push(group(g, g.title))
		if(data.actions.length)list.push({title: t('Actions'), items: data.actions.slice(0, MAX).map((a) => ({kind: 'action', title: `${t('Add')} ${a.label}`, sub: '', icon: 'add', to: `/${a.route}/?add=1`, route: a.route}))})
		for(const g of data.groups.filter((x) => x.route === 'globals'))list.push(group(g, t('Configuration')))
		return list

	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [data, term])

	const flat = groups.flatMap((g) => g.items)
	const current = flat[Math.min(active, flat.length - 1)]

	const go = (to: string, entry?: Entry) => {

		if(entry)remember(entry)
		onClose()
		navigate(to)

	}

	const onKey = (e: KeyboardEvent) => {

		if(e.key === 'ArrowDown'){ e.preventDefault(); setActive((i) => Math.min(i + 1, flat.length - 1)) }
		else if(e.key === 'ArrowUp'){ e.preventDefault(); setActive((i) => Math.max(i - 1, 0)) }
		else if(e.key === 'Enter' && current && (e.ctrlKey || e.metaKey) && current.kind !== 'action' && term.length >= 2){ e.preventDefault(); go(filtered(current.route)) }
		else if(e.key === 'Enter' && current){ e.preventDefault(); go(current.to, current) }
		else if(e.key === 'Tab' && !e.shiftKey && scope === '' && current && (current.kind === 'module' || current.kind === 'record')){ e.preventDefault(); setScope(current.route); setActive(0) }

	}

	const empty = term.length >= 2 && !loading && !!data && groups.length === 0

	return (
		<Dialog
			open={open}
			// Esc clears the search first, a second Esc closes
			onClose={(_, reason) => { if(reason === 'escapeKeyDown' && q !== ''){ setQ(''); setActive(0); return } onClose() }}
			fullScreen={full_screen}
			// focus not given back to the icon of the header (it would stay highlighted with its tooltip)
			disableRestoreFocus
			maxWidth={false}
			slotProps={{
				backdrop: {sx: {bgcolor: 'color-mix(in srgb, var(--u-bg) 40%, transparent)', backdropFilter: 'blur(10px) saturate(150%)'}},
				paper: {sx: {width: '100%', maxWidth: {md: 780}, alignSelf: 'flex-start', mt: {md: 9}, borderRadius: {md: '16px'}, overflow: 'hidden', bgcolor: 'color-mix(in srgb, var(--u-paper) 80%, transparent)', backgroundImage: 'none', backdropFilter: 'blur(28px) saturate(180%)', border: {md: '1px solid color-mix(in srgb, var(--u-paper) 70%, transparent)'}, boxShadow: '0 24px 70px rgba(40,52,90,.22)'}},
			}}
		>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, p: 1.75, pb: 1.5}}>
				<Box sx={{flex: 1, minWidth: 0, display: 'flex', alignItems: 'center', bgcolor: 'var(--u-paper)', border: '2px solid', borderColor: 'primary.main', borderRadius: '12px', boxShadow: '0 0 0 4px color-mix(in srgb, var(--mui-palette-primary-main) 14%, transparent)'}}>
					<Select
						value={scope}
						displayEmpty
						variant="standard"
						disableUnderline
						onChange={(e) => { setScope(String(e.target.value)); setActive(0) }}
						inputProps={{'aria-label': t('Search in')}}
						sx={{height: 52, maxWidth: {xs: 120, md: 200}, flexShrink: 0, px: 1.5, fontSize: 14, fontWeight: 500, bgcolor: 'var(--u-widget)', borderRight: '1px solid var(--u-border)', borderRadius: '10px 0 0 10px', '& .MuiSelect-select': {py: 0, pr: '24px !important', bgcolor: 'transparent'}}}
					>
						<MenuItem value="" sx={{fontSize: 14}}>{t('All')}</MenuItem>
						{scopes.map((s) => <MenuItem key={s.route} value={s.route} sx={{fontSize: 14, gap: 1.25}}><MenuIcon name={s.icon} fontSize={18} color="var(--u-text3)" />{s.name}</MenuItem>)}
					</Select>
					<SearchOutlined sx={{ml: 1.75, fontSize: 22, color: 'var(--u-text3)', flexShrink: 0}} />
					<InputBase
						autoFocus
						value={q}
						onChange={(e) => { setQ(e.target.value); setActive(0) }}
						onKeyDown={onKey}
						placeholder={t('Search a record, a module, an action...')}
						inputProps={{'aria-label': t('Search'), maxLength: 100}}
						sx={{flex: 1, minWidth: 0, height: 52, px: 1.75, fontSize: 19}}
					/>
					{loading && <CircularProgress size={18} sx={{mr: 1.5, flexShrink: 0}} />}
					{q !== '' && !loading && <IconButton aria-label={t('Clear')} onClick={() => { setQ(''); setActive(0) }} sx={{mr: 1}}><CloseOutlined sx={{fontSize: 18}} /></IconButton>}
				</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</Box>

			<Box sx={{maxHeight: {md: 500}, flex: {xs: 1, md: 'none'}, overflowY: 'auto', px: 1, pb: 1, borderTop: '1px solid var(--u-border-soft)'}}>
				{term.length < 2 && groups.length === 0 && <Typography sx={{py: 5, textAlign: 'center', fontSize: 14, color: 'var(--u-text3)'}}>{t('Type 2 characters at least')}</Typography>}
				{empty && (
					<Box sx={{py: 5, textAlign: 'center', display: 'flex', flexDirection: 'column', gap: 0.75}}>
						<Typography sx={{fontSize: 15, fontWeight: 500}}>{t('No result for "[Q]"', {Q: term})}</Typography>
						<Typography sx={{fontSize: 13, color: 'var(--u-text3)'}}>{t('Check the spelling, or choose another module on the left')}</Typography>
					</Box>
				)}
				{groups.map((g) => (
					<Box key={g.title}>
						<Box sx={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1.25, px: 1.5, pt: 1.5, pb: 0.5}}>
							<Typography component="h3" sx={{fontSize: 11, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--u-muted)'}}>{g.title}</Typography>
							{g.more && <ButtonBase onClick={() => go(g.more!.to)} sx={{gap: 0.5, px: 0.75, py: 0.5, borderRadius: '6px', fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', '&:hover': {bgcolor: 'var(--u-accent-bg)'}}}>{g.more.label}<ChevronRightOutlined sx={{fontSize: 16}} /></ButtonBase>}
						</Box>
						{g.items.map((it) => {

							const on = (it === current)
							return (
								<ButtonBase key={`${it.kind}-${it.to}-${it.title}`} onClick={() => go(it.to, it)} onMouseMove={() => !on && setActive(flat.indexOf(it))}
									sx={{width: '100%', minHeight: 44, display: 'flex', alignItems: 'center', gap: 1.5, px: 1.5, py: 0.875, borderRadius: '10px', textAlign: 'left', justifyContent: 'flex-start', bgcolor: on ? 'var(--u-accent-bg)' : 'transparent', boxShadow: on ? 'inset 3px 0 0 var(--mui-palette-primary-main)' : 'none'}}>
									<Box sx={{width: 30, height: 30, flexShrink: 0, borderRadius: '8px', display: 'flex', alignItems: 'center', justifyContent: 'center', bgcolor: it.kind === 'action' ? 'var(--u-green-bg, #e6f4ec)' : (it.kind === 'module' ? 'var(--u-accent-bg)' : 'var(--u-widget)'), color: it.kind === 'module' ? 'var(--u-accent-text)' : 'var(--u-text2)'}}>
										<MenuIcon name={it.kind === 'recent' && !it.icon ? 'history' : it.icon} fontSize={18} />
									</Box>
									<Box sx={{flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: '1px'}}>
										<Box component="span" sx={{fontSize: 14, fontWeight: 500, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}><Highlight text={it.title} q={term} /></Box>
										{it.sub && <Box component="span" sx={{fontSize: 12, color: 'var(--u-text3)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}><Highlight text={it.sub} q={term} /></Box>}
									</Box>
									{on && !full_screen && <Kbd>Enter</Kbd>}
								</ButtonBase>
							)

						})}
					</Box>
				))}
			</Box>
		</Dialog>
	)

}
