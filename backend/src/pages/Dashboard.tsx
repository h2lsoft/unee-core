import { useEffect, useState } from 'react'
import { Link as RouterLink } from 'react-router'
import { Box, CircularProgress, Tab, Tabs, Typography } from '@mui/material'
import { api, type MenuCategory, type MenuItem } from '../api'
import { useDocumentTitle } from '../components/AuthLayout'
import { MenuIcon } from '../components/icons'
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

// plugin of the menu tab: icon and name (two lines), external link for url plugins
function PluginTile({item}: {item: MenuItem})
{

	const link = item.url ? {component: 'a', href: item.url, target: '_blank', rel: 'noopener noreferrer'} : {component: RouterLink, to: `/${item.route}/`}

	return (
		<Box {...link} sx={{height: 111, px: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 1, bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '5px', color: 'var(--u-text)', textDecoration: 'none', textAlign: 'center', transition: 'border-color .15s, background-color .15s', '&:hover': {borderColor: 'var(--u-accent-border)', bgcolor: 'var(--u-accent-hover)'}}}>
			<MenuIcon name={item.icon} fontSize={26} color="var(--u-muted)" />
			<Box component="span" sx={{display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', fontSize: 13.5, fontWeight: 500, lineHeight: 1.3}}>{item.name}</Box>
		</Box>
	)

}

/**
 * home page of the backend (module core-backend/dashboard): tab dashboard (widgets, later), tab menu (plugins allowed, by category)
 */
export default function Dashboard()
{

	const [tab, setTab] = useState<DashboardTab>(readTab)
	const [menu, setMenu] = useState<MenuCategory[] | null>(null)
	useDocumentTitle(t('Dashboard'))

	useEffect(() => {

		api<{menu: MenuCategory[]}>('dashboard/').then((data) => setMenu(data.menu)).catch(() => setMenu([]))

	}, [])

	const change = (value: DashboardTab) => {

		setTab(value)
		writeTab(value)

	}

	return (
		<>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25}}>
				<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: 'var(--u-accent-bg)', color: 'primary.main'}}><MenuIcon name="speed" /></Box>
				<Typography component="h1" sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>{t('Dashboard')}</Typography>
			</Box>

			<Tabs value={tab} onChange={(_, value) => change(value)} sx={{minHeight: 40, mt: -1, borderBottom: '1px solid var(--u-border)', '& .MuiTab-root': {minHeight: 40, px: 1.75, textTransform: 'uppercase', fontSize: 13, fontWeight: 600, letterSpacing: '.04em'}}}>
				<Tab value="dashboard" label={t('Dashboard')} />
				<Tab value="menu" label={t('Menu')} />
			</Tabs>

			{tab === 'menu' && !menu && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
			{tab === 'menu' && menu?.map((c) => ({...c, items: c.items.filter((i) => !i.hidden)})).filter((c) => c.items.length > 0).map((category) => (
				<Box component="section" key={category.name} sx={{display: 'flex', flexDirection: 'column', gap: 1.25}}>
					<Typography component="h2" sx={{fontSize: 13, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--u-muted)'}}>{category.name}</Typography>
					<Box sx={{display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: 2}}>
						{category.items.map((item) => <PluginTile key={item.route + (item.url ?? '')} item={item} />)}
					</Box>
				</Box>
			))}
		</>
	)

}
