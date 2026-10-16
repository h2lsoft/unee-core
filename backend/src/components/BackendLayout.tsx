import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router'
import { Avatar, Box, ButtonBase, Divider, Drawer, IconButton, ListItemIcon, ListItemText, Menu, MenuItem, Paper, Popper, Tooltip, Typography, useMediaQuery, useTheme } from '@mui/material'
import { BookmarkBorderOutlined, CalendarMonthOutlined, CheckOutlined, KeyboardArrowDownOutlined, LanguageOutlined, LogoutOutlined, MenuOpenOutlined, MenuOutlined } from '@mui/icons-material'
import { api, type MenuCategory, type MenuItem as MenuEntry } from '../api'
import { useAuth } from '../auth'
import { t } from '../i18n'
import { useLoginConfig } from './AuthLayout'
import { MenuIcon } from './icons'

const DEFAULT_LOGO = `${import.meta.env.BASE_URL}logo.svg`
const COLLAPSED_KEY = 'unee.menu.collapsed'
const BORDER = '#eceef3'

type MenuState = {menu: MenuCategory[] | null, find: (route: string) => MenuEntry | null}
const MenuContext = createContext<MenuState>({menu: null, find: () => null})

/**
 * menu of the logged user, null while loading
 */
export function useMenu(): MenuState
{

	return useContext(MenuContext)

}

// per browser convenience, storage may be blocked
function readCollapsed(): boolean
{

	try
	{

		return localStorage.getItem(COLLAPSED_KEY) === '1'

	}
	catch
	{

		return false

	}

}

function writeCollapsed(value: boolean)
{

	try
	{

		localStorage.setItem(COLLAPSED_KEY, value ? '1' : '0')

	}
	catch
	{

		// private mode: not remembered

	}

}

function initials(firstname: string, lastname: string): string
{

	return `${firstname.charAt(0)}${lastname.charAt(0)}`.toUpperCase() || '?'

}

// one link of the sidebar, label hidden when collapsed (tooltip instead)
function SideLink({to, icon, label, collapsed, onClick, external}: {to: string, icon: string, label: string, collapsed: boolean, onClick?: () => void, external?: boolean})
{

	const sx = {
		height: 36,
		display: 'flex',
		alignItems: 'center',
		gap: 1.5,
		borderRadius: 2,
		color: '#3a3f55',
		fontSize: 14,
		textDecoration: 'none',
		justifyContent: collapsed ? 'center' : 'flex-start',
		width: collapsed ? 44 : 'auto',
		px: collapsed ? 0 : 1.5,
		'&:hover': {bgcolor: '#f2f3f7', color: '#1f2335'},
		'&.active': {bgcolor: '#f0ecff', color: '#4b2fd6', fontWeight: 500},
	}

	const content = (external)
		? <Box component="a" href={to} target="_blank" rel="noopener noreferrer" sx={sx} onClick={onClick}><MenuIcon name={icon} />{!collapsed && label}</Box>
		: <Box component={NavLink} to={to} end sx={sx} onClick={onClick}><MenuIcon name={icon} />{!collapsed && label}</Box>

	return (collapsed) ? <Tooltip title={label} placement="right">{content}</Tooltip> : content

}

// collapsed rail: one icon per category, its modules in a flyout aligned on the icon (hover, or click on touch screens)
function RailCategory({category, active}: {category: MenuCategory, active: boolean})
{

	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	const timer = useRef(0)

	const open = (el: HTMLElement) => {

		window.clearTimeout(timer.current)
		setAnchor(el)

	}

	// short delay: the pointer crosses the gap between the rail and the flyout
	const close = () => {

		window.clearTimeout(timer.current)
		timer.current = window.setTimeout(() => setAnchor(null), 150)

	}

	const on = (active || !!anchor)

	return (
		<>
			<ButtonBase
				aria-label={category.name}
				aria-haspopup="menu"
				aria-expanded={!!anchor}
				onMouseEnter={(e) => open(e.currentTarget)}
				onMouseLeave={close}
				onClick={(e) => (anchor) ? setAnchor(null) : open(e.currentTarget)}
				sx={{width: 44, height: 40, borderRadius: 2, color: on ? '#4b2fd6' : '#5b6178', bgcolor: on ? '#f0ecff' : 'transparent', '&:hover': {bgcolor: on ? '#f0ecff' : '#f2f3f7'}}}
			>
				<MenuIcon name={category.icon} />
			</ButtonBase>
			<Popper open={!!anchor} anchorEl={anchor} placement="right-start" modifiers={[{name: 'offset', options: {offset: [0, 8]}}]} sx={{zIndex: 1300}} onMouseEnter={() => window.clearTimeout(timer.current)} onMouseLeave={close}>
				<Paper role="menu" aria-label={category.name} elevation={0} sx={{width: 240, p: 1, display: 'flex', flexDirection: 'column', gap: 0.25, border: `1px solid ${BORDER}`, borderRadius: '10px', boxShadow: '0 8px 24px rgba(16, 20, 40, 0.12)'}}>
					<Typography component="p" sx={{m: '4px 12px 6px', fontSize: 11, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: '#6b7088'}}>{category.name}</Typography>
					{category.items.map((item) => (
						<SideLink key={item.route} to={item.url ?? `/${item.route}/`} external={!!item.url} icon={item.icon} label={item.name} collapsed={false} onClick={() => setAnchor(null)} />
					))}
				</Paper>
			</Popper>
		</>
	)

}

function Sidebar({menu, collapsed, onNavigate}: {menu: MenuCategory[] | null, collapsed: boolean, onNavigate?: () => void})
{

	const location = useLocation()
	const current = location.pathname.split('/').filter(Boolean)[0] ?? ''

	if(collapsed)
	{

		return (
			<Box component="nav" aria-label={t('Main menu')} sx={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 0.5}}>
				<SideLink to="/" icon="speed" label={t('Dashboard')} collapsed onClick={onNavigate} />
				<Box sx={{width: 32, height: '1px', my: 0.75, bgcolor: BORDER}} />
				{menu?.map((category) => <RailCategory key={category.name} category={category} active={category.items.some((i) => i.route === current)} />)}
			</Box>
		)

	}

	return (
		<Box component="nav" aria-label={t('Main menu')} sx={{display: 'flex', flexDirection: 'column', gap: 0.25}}>
			<SideLink to="/" icon="speed" label={t('Dashboard')} collapsed={false} onClick={onNavigate} />
			{menu?.map((category) => (
				<Box key={category.name} sx={{display: 'flex', flexDirection: 'column', gap: 0.25}}>
					<Typography component="p" sx={{m: '18px 12px 6px', fontSize: 11, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: '#6b7088'}}>{category.name}</Typography>
					{category.items.map((item) => (
						<SideLink key={item.route} to={item.url ?? `/${item.route}/`} external={!!item.url} icon={item.icon} label={item.name} collapsed={false} onClick={onNavigate} />
					))}
				</Box>
			))}
		</Box>
	)

}

function UserMenu({compact}: {compact: boolean})
{

	const {user, logout, changeLanguage} = useAuth()
	const config = useLoginConfig()
	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	const [language_anchor, setLanguageAnchor] = useState<HTMLElement | null>(null)

	if(!user)return null
	const name = `${user.firstname} ${user.lastname}`.trim() || user.login

	return (
		<>
			<ButtonBase aria-label={t('User menu')} onClick={(e) => setAnchor(e.currentTarget)} sx={{display: 'flex', alignItems: 'center', gap: 1.25, p: compact ? 0.5 : '4px 8px 4px 4px', borderRadius: 2, textAlign: 'left'}}>
				<Avatar sx={{width: compact ? 32 : 34, height: compact ? 32 : 34, bgcolor: 'primary.main', fontSize: 13, fontWeight: 600}}>{initials(user.firstname, user.lastname)}</Avatar>
				{!compact && (
					<>
						<Box sx={{display: 'flex', flexDirection: 'column', lineHeight: 1.25}}>
							<Typography component="span" sx={{fontSize: 13, fontWeight: 600}}>{name}</Typography>
							<Typography component="span" sx={{fontSize: 12, color: 'text.secondary'}}>{user.group_name}</Typography>
						</Box>
						<KeyboardArrowDownOutlined sx={{fontSize: 18, color: '#5b6178'}} />
					</>
				)}
			</ButtonBase>

			<Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}} slotProps={{paper: {sx: {minWidth: 240, mt: 0.5}}}}>
				<Box sx={{px: 2, py: 1.25}}>
					<Typography sx={{fontSize: 14, fontWeight: 600}}>{name}</Typography>
					<Typography sx={{fontSize: 13, color: 'text.secondary'}}>{user.email}</Typography>
				</Box>
				<Divider />
				<MenuItem onClick={(e) => setLanguageAnchor(e.currentTarget)}>
					<ListItemIcon><LanguageOutlined fontSize="small" /></ListItemIcon>
					<ListItemText>{t('Language')}</ListItemText>
					<Typography sx={{fontSize: 13, color: 'text.secondary'}}>{config?.languages[user.language] ?? user.language}</Typography>
				</MenuItem>
				<MenuItem onClick={() => { setAnchor(null); logout() }}>
					<ListItemIcon><LogoutOutlined fontSize="small" /></ListItemIcon>
					<ListItemText>{t('Sign out')}</ListItemText>
				</MenuItem>
			</Menu>

			<Menu anchorEl={language_anchor} open={!!language_anchor} onClose={() => setLanguageAnchor(null)} anchorOrigin={{vertical: 'top', horizontal: 'left'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}>
				{Object.entries(config?.languages ?? {}).map(([code, label]) => (
					<MenuItem key={code} selected={code === user.language} onClick={() => { setLanguageAnchor(null); setAnchor(null); changeLanguage(code) }}>
						<ListItemIcon>{code === user.language && <CheckOutlined fontSize="small" />}</ListItemIcon>
						<ListItemText>{label}</ListItemText>
					</MenuItem>
				))}
			</Menu>
		</>
	)

}

/**
 * shell of the backend: top bar, sidebar (collapsible, remembered per browser), drawer under 900px
 */
export default function BackendLayout()
{

	const theme = useTheme()
	const is_mobile = useMediaQuery(theme.breakpoints.down('md'))
	const config = useLoginConfig()
	const location = useLocation()
	const [menu, setMenu] = useState<MenuCategory[] | null>(null)
	const [collapsed, setCollapsed] = useState(readCollapsed)
	const [drawer, setDrawer] = useState(false)

	useEffect(() => {

		api<{menu: MenuCategory[]}>('backend/menu/').then((data) => setMenu(data.menu)).catch(() => setMenu([]))

	}, [])

	// drawer closed after a navigation
	useEffect(() => setDrawer(false), [location.pathname])

	const toggle = () => {

		writeCollapsed(!collapsed)
		setCollapsed(!collapsed)

	}

	const find = (route: string) => menu?.flatMap((c) => c.items).find((i) => i.route === route) ?? null
	const logo = config?.logo || DEFAULT_LOGO

	return (
		<MenuContext.Provider value={{menu, find}}>
			<Box sx={{minHeight: '100vh', display: 'flex', flexDirection: 'column', bgcolor: '#f7f8fb', color: 'text.primary'}}>

				<Box component="header" sx={{height: {xs: 56, md: 60}, flexShrink: 0, display: 'flex', alignItems: 'center', gap: {xs: 0.5, md: 2}, px: {xs: 1, md: 2.5}, bgcolor: '#fff', borderBottom: `1px solid ${BORDER}`, position: 'sticky', top: 0, zIndex: theme.zIndex.appBar}}>
					{is_mobile && <IconButton aria-label={t('Open menu')} onClick={() => setDrawer(true)}><MenuOutlined /></IconButton>}
					<Box component={NavLink} to="/" sx={{display: 'flex'}}><Box component="img" src={logo} alt="logo" sx={{height: {xs: 26, md: 30}, width: 'auto'}} /></Box>
					<Box sx={{flexGrow: 1}} />
					{/* calendar and bookmarks: placeholders until the agenda and the bookmarks modules */}
					{!is_mobile && (
						<Box sx={{display: 'flex', alignItems: 'center', gap: 0.5}}>
							<Tooltip title={t('Calendar')}><IconButton aria-label={t('Calendar')} sx={{width: 40, height: 40, borderRadius: 2, color: '#5b6178'}}><CalendarMonthOutlined sx={{fontSize: 24}} /></IconButton></Tooltip>
							<Tooltip title={t('Bookmarks')}><IconButton aria-label={t('Bookmarks')} sx={{width: 40, height: 40, borderRadius: 2, color: '#5b6178'}}><BookmarkBorderOutlined sx={{fontSize: 24}} /></IconButton></Tooltip>
							<Box sx={{width: '1px', height: 28, mx: 1, bgcolor: BORDER}} />
						</Box>
					)}
					<UserMenu compact={is_mobile} />
				</Box>

				<Box sx={{flexGrow: 1, display: 'flex', minHeight: 0}}>
					{is_mobile
						? (
							<Drawer open={drawer} onClose={() => setDrawer(false)} slotProps={{paper: {sx: {width: 280, p: 1.5}}}}>
								<Box sx={{px: 1, pb: 1.5, pt: 0.5}}><Box component="img" src={logo} alt="logo" sx={{height: 26, width: 'auto'}} /></Box>
								<Sidebar menu={menu} collapsed={false} onNavigate={() => setDrawer(false)} />
							</Drawer>
						)
						: (
							<Box sx={{width: collapsed ? 64 : 248, flexShrink: 0, display: 'flex', flexDirection: 'column', p: collapsed ? '12px 10px 16px' : '12px 12px 16px', bgcolor: '#fff', borderRight: `1px solid ${BORDER}`, transition: 'width .15s', position: 'sticky', top: 60, height: 'calc(100vh - 60px)', overflowY: 'auto', overflowX: 'hidden'}}>
								<Sidebar menu={menu} collapsed={collapsed} />
								<ButtonBase onClick={toggle} aria-label={collapsed ? t('Expand menu') : t('Collapse menu')} sx={{mt: 'auto', height: 36, display: 'flex', alignItems: 'center', justifyContent: collapsed ? 'center' : 'flex-start', alignSelf: collapsed ? 'center' : 'stretch', width: collapsed ? 44 : 'auto', gap: 1.5, px: collapsed ? 0 : 1.5, borderRadius: 2, color: '#5b6178', fontSize: 13, '&:hover': {bgcolor: '#f2f3f7'}}}>
									{collapsed ? <MenuOpenOutlined sx={{fontSize: 20, transform: 'scaleX(-1)'}} /> : <><MenuOpenOutlined sx={{fontSize: 20}} />{t('Collapse menu')}</>}
								</ButtonBase>
							</Box>
						)}

					<Box component="main" sx={{flex: '1 1 auto', minWidth: 0, p: {xs: '20px 16px', md: '28px 32px'}, display: 'flex', flexDirection: 'column', gap: {xs: 2, md: 2.5}}}>
						<Outlet />
					</Box>
				</Box>

			</Box>
		</MenuContext.Provider>
	)

}

// page title row: h1 and actions on the right
export function PageHeader({title, children}: {title: string, children?: ReactNode})
{

	return (
		<Box sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: {xs: 1, md: 2}}}>
			<Typography component="h1" sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em', flexGrow: 1}}>{title}</Typography>
			{children}
		</Box>
	)

}
