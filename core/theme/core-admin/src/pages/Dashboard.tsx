import { useEffect, useState } from 'react'
import { Link as RouterLink, useNavigate } from 'react-router'
import { Box, CircularProgress, InputAdornment, OutlinedInput, Tab, Tabs, Typography } from '@mui/material'
import { SearchOutlined, WavingHandOutlined } from '@mui/icons-material'
import { api, ApiException, type MenuCategory, type MenuItem } from '../api'
import { useDocumentTitle } from '../components/AuthLayout'
import { MenuIcon } from '../components/icons'
import { AddWidgetButton, AddWidgetDialog, addToRows, DashboardGrid, toRows, type Rows } from '../components/DashboardWidgets'
import { useToast } from '../components/Toast'
import { type Placed, type WidgetInfo } from '../components/Widgets'
import { useAuth } from '../auth'
import { t } from '../i18n'

const TABS = ['dashboard', 'menu'] as const
type DashboardTab = typeof TABS[number]

// active tab, per browser (storage may be blocked)
function readTab(): DashboardTab
{

	try
	{

		const value = localStorage.getItem('unee.dashboard.tab')
		return (TABS as readonly string[]).includes(value ?? '') ? value as DashboardTab : 'dashboard'

	}
	catch
	{

		return 'dashboard'

	}

}

function writeTab(tab: DashboardTab)
{

	try
	{

		localStorage.setItem('unee.dashboard.tab', tab)

	}
	catch
	{

		// private mode: not remembered

	}

}

// lower case without accents, for the search
const fold = (text: string) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

// plugin of the menu tab: icon and name (two lines), external link for url plugins
function PluginTile({item}: {item: MenuItem})
{

	const link = item.url ? {component: 'a', href: item.url, target: '_blank', rel: 'noopener noreferrer'} : {component: RouterLink, to: `/${item.route}/`}

	return (
		<Box {...link} sx={{height: 101, px: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 1, bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '5px', color: 'var(--u-text)', textDecoration: 'none', textAlign: 'center', transition: 'border-color .15s, background-color .15s', '&:hover': {borderColor: 'var(--u-accent-border)', bgcolor: 'var(--u-accent-hover)'}}}>
			<MenuIcon name={item.icon} fontSize={26} color="var(--u-muted)" />
			<Box component="span" sx={{display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', fontSize: 13.5, fontWeight: 500, lineHeight: 1.3}}>{item.name}</Box>
		</Box>
	)

}

/**
 * home page of the backend (module core-backend/dashboard): tab dashboard (widgets placed by the user, personal notes), tab plugins (plugins allowed, by category)
 */
export default function Dashboard()
{

	const [tab, setTab] = useState<DashboardTab>(readTab)
	const [menu, setMenu] = useState<MenuCategory[] | null>(null)
	const [widgets, setWidgets] = useState<Rows | null>(null)
	const [catalog, setCatalog] = useState<WidgetInfo[]>([])
	const [adding, setAdding] = useState(false)
	const toast = useToast()
	const [search, setSearch] = useState('')
	const {user} = useAuth()
	const navigate = useNavigate()
	useDocumentTitle(t('Dashboard'))

	useEffect(() => {

		api<{menu: MenuCategory[], widgets: (Placed & {row: number})[], catalog: WidgetInfo[]}>('dashboard/').then((data) => { setMenu(data.menu); setWidgets(toRows(data.widgets)); setCatalog(data.catalog) }).catch(() => { setMenu([]); setWidgets([]) })

	}, [])

	// visible plugins whose name holds the search, the ones starting with it first
	const query = fold(search.trim())
	const plugins = (menu ?? []).map((c) => ({...c, items: c.items.filter((i) => !i.hidden && fold(i.name).includes(query)).sort((a, b) => Number(fold(b.name).startsWith(query)) - Number(fold(a.name).startsWith(query)))})).filter((c) => c.items.length > 0)

	// name highlighted inside the translated sentence
	const [before, after = ''] = t('Welcome [NAME]').split('[NAME]')

	const change = (value: DashboardTab) => {

		setTab(value)
		writeTab(value)

	}

	// dashboard of the user (rows) saved at each change (PUT /api/dashboard/layout/), the previous one back on error
	const save = (rows: Rows) => {

		const previous = widgets
		setWidgets(rows)
		api<{widgets: (Placed & {row: number})[]}>('dashboard/layout/', {method: 'PUT', body: {rows: rows.map((row) => row.map((w) => ({key: Math.max(0, w.key), widget: w.id, config: w.config})))}})
			.then((data) => setWidgets(toRows(data.widgets)))
			.catch((e) => { setWidgets(previous); toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error') })

	}

	// the dialog stays open: several widgets added in a row
	const add = (widget: WidgetInfo) => {

		if(!widgets || (!widget.multiple && widgets.flat().some((w) => w.id === widget.id)))return

		// key < 0 until saved, settings at their defaults
		const config = Object.fromEntries((widget.fields ?? []).map((f) => [f.name, f.default]))
		save(addToRows(widgets, {...widget, key: -Date.now(), config}))
		toast(t('[NAME] added to the dashboard', {NAME: widget.name}), 'success')

	}

	return (
		<>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 2}}>
				<Typography component="h1" sx={{m: 0, fontSize: {xs: 21, md: 24}, fontWeight: 600, letterSpacing: '-0.01em'}}>
					<WavingHandOutlined aria-hidden sx={{fontSize: '0.9em', mr: 1, verticalAlign: '-0.1em', color: 'var(--u-accent-text)'}} />{before}<Box component="span" sx={{color: 'var(--u-accent-text)'}}>{user?.firstname || user?.login || ''}</Box>{after}
				</Typography>
			</Box>

			<Tabs value={tab} onChange={(_, value) => change(value)} sx={{minHeight: 40, mt: -2, borderBottom: '1px solid var(--u-border)', '& .MuiTab-root': {minHeight: 40, px: 1.75, textTransform: 'uppercase', fontSize: 13, fontWeight: 600, letterSpacing: '.04em'}}}>
				<Tab value="dashboard" label={t('Dashboard')} />
				<AddWidgetButton hidden={tab !== 'dashboard' || !widgets} onAdd={() => setAdding(true)} />
				<Tab value="menu" label={t('Plugins')} />
			</Tabs>

			{tab === 'dashboard' && !widgets && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
			{tab === 'dashboard' && widgets && <DashboardGrid rows={widgets} version={0} onChange={save} />}
			<AddWidgetDialog open={adding} catalog={catalog} placed={widgets?.flat() ?? []} onAdd={add} onClose={() => setAdding(false)} />

			{tab === 'menu' && !menu && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
			{tab === 'menu' && menu && (
				<OutlinedInput autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search a plugin...')} inputProps={{'aria-label': t('Search a plugin...')}}
					onKeyDown={(e) => { if(e.key === 'Escape')setSearch(''); if(e.key === 'Enter' && plugins[0]?.items[0]){ const first = plugins[0].items[0]; if(first.url)window.open(first.url, '_blank', 'noopener'); else navigate(`/${first.route}/`) } }}
					startAdornment={<InputAdornment position="start"><SearchOutlined sx={{fontSize: 20, color: 'var(--u-muted)'}} /></InputAdornment>}
					sx={{width: {xs: '100%', sm: 320}, height: 36, fontSize: 14, bgcolor: 'var(--u-paper)'}} />
			)}
			{tab === 'menu' && menu && plugins.length === 0 && <Typography sx={{fontSize: 13.5, color: 'var(--u-muted)'}}>{t('No plugin found')}</Typography>}
			{tab === 'menu' && plugins.map((category) => (
				<Box component="section" key={category.name} sx={{display: 'flex', flexDirection: 'column', gap: 1.25}}>
					<Typography component="h2" sx={{fontSize: 13, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--u-muted)'}}>{category.name}</Typography>
					<Box sx={{display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 2}}>
						{category.items.map((item) => <PluginTile key={item.route + (item.url ?? '')} item={item} />)}
					</Box>
				</Box>
			))}
		</>
	)

}
