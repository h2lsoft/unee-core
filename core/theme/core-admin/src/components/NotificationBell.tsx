import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { Badge, Box, ButtonBase, Dialog, IconButton, Popover, Tooltip, Typography, useMediaQuery, useTheme } from '@mui/material'
import { CheckOutlined, CloseOutlined, ErrorOutlineOutlined, InfoOutlined, NotificationsActiveOutlined, NotificationsNoneOutlined, NotificationsOutlined, WarningAmberOutlined } from '@mui/icons-material'
import { api } from '../api'
import { t } from '../i18n'
import { relativeTime } from './time'

type Item = {id: number, level: 'info' | 'success' | 'warning' | 'error', category: string, title: string, message: string, link: string, date: string, read: boolean}

// notifications of the browser (system), only while the backend is open
const BROWSER = typeof window !== 'undefined' && 'Notification' in window

const POLL_MS = 60000

// icon and colors of each level (colors.css variables)
const LEVELS = {
	info: {icon: InfoOutlined, bg: 'var(--u-blue-bg)', fg: 'var(--u-blue)'},
	success: {icon: CheckOutlined, bg: 'var(--u-green-bg)', fg: 'var(--u-green)'},
	warning: {icon: WarningAmberOutlined, bg: 'var(--u-orange-bg)', fg: 'var(--u-orange)'},
	error: {icon: ErrorOutlineOutlined, bg: 'var(--u-red-bg)', fg: 'var(--u-red)'},
}

/**
 * bell of the header: unread count polled every minute (and when the tab comes back), list of the last notifications
 * desktop: popover under the bell, mobile: full screen
 * new unread notifications are also shown by the browser when allowed and the tab is not in front
 */
export default function NotificationBell()
{

	const theme = useTheme()
	const is_mobile = useMediaQuery(theme.breakpoints.down('md'))
	const navigate = useNavigate()
	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	const [unread, setUnread] = useState(0)
	const [items, setItems] = useState<Item[]>([])
	const [permission, setPermission] = useState(BROWSER ? window.Notification.permission : 'denied')

	// highest id already seen: only newer unread ones go to the browser (none on the first load)
	const seen = useRef<number | null>(null)

	const load = useCallback(() => {

		api<{unread: number, items: Item[]}>('notification-bell/')
			.then((data) => {

				setUnread(data.unread)
				setItems(data.items)

				const max = Math.max(0, ...data.items.map((i) => i.id))
				if(seen.current !== null && BROWSER && window.Notification.permission === 'granted' && document.visibilityState !== 'visible')
				{

					for(const n of data.items.filter((i) => !i.read && i.id > (seen.current ?? 0)))
					{

						const popup = new window.Notification(n.title, {body: n.message, tag: `unee-notification-${n.id}`})
						popup.onclick = () => { window.focus(); popup.close(); if(n.link)navigate(`/${n.link}`) }

					}

				}
				seen.current = Math.max(seen.current ?? 0, max)

			})
			.catch(() => null)

	}, [navigate])

	useEffect(() => {

		load()
		const timer = window.setInterval(load, POLL_MS)
		const onVisible = () => { if(document.visibilityState === 'visible')load() }
		document.addEventListener('visibilitychange', onVisible)
		return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', onVisible) }

	}, [load])

	const close = () => setAnchor(null)

	const allowBrowser = () => {

		window.Notification.requestPermission().then(setPermission).catch(() => null)

	}

	const open = async (n: Item) => {

		close()
		if(!n.read)
		{

			setItems((list) => list.map((i) => i.id === n.id ? {...i, read: true} : i))
			api<{unread: number}>(`notification-bell/${n.id}/read/`, {method: 'POST'}).then((data) => setUnread(data.unread)).catch(() => null)

		}
		if(n.link)navigate(`/${n.link}`)

	}

	const readAll = async () => {

		setItems((list) => list.map((i) => ({...i, read: true})))
		setUnread(0)
		await api('notification-bell/read-all/', {method: 'POST'}).catch(() => null)

	}

	const label = unread ? t('Notifications, [N] unread', {N: unread}) : t('Notifications')

	const content = (
		<Box sx={{display: 'flex', flexDirection: 'column', minHeight: 0, height: is_mobile ? '100%' : 'auto'}}>
			<Box sx={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1, minHeight: is_mobile ? 56 : 'auto', px: 2, pt: is_mobile ? 0 : 1.75, pb: is_mobile ? 0 : 1.5, pr: is_mobile ? 1 : 2, borderBottom: '1px solid var(--u-border-soft)', flexShrink: 0}}>
				<Typography component="h2" sx={{m: 0, fontSize: is_mobile ? 17 : 15, fontWeight: 600}}>{t('Notifications')}</Typography>
				{!is_mobile && unread > 0 && <ButtonBase onClick={readAll} sx={{fontFamily: 'inherit', fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', '&:hover': {textDecoration: 'underline'}}}>{t('Mark all as read')}</ButtonBase>}
				{is_mobile && <IconButton aria-label={t('Close')} onClick={close} sx={{width: 44, height: 44}}><CloseOutlined /></IconButton>}
			</Box>

			{is_mobile && unread > 0 && (
				<Box sx={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', px: 2, py: 1, borderBottom: '1px solid var(--u-border-soft)', flexShrink: 0}}>
					<Typography sx={{fontSize: 13, color: 'var(--u-text3)'}}>{t('[N] unread', {N: unread})}</Typography>
					<ButtonBase onClick={readAll} sx={{height: 36, fontFamily: 'inherit', fontSize: 13, fontWeight: 500, color: 'var(--u-accent-text)'}}>{t('Mark all as read')}</ButtonBase>
				</Box>
			)}

			{items.length === 0
				? (
					<Box sx={{display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 1.25, px: 3, py: 5, textAlign: 'center'}}>
						<Box sx={{width: 48, height: 48, borderRadius: '50%', bgcolor: 'var(--u-widget)', color: 'var(--u-muted)', display: 'flex', alignItems: 'center', justifyContent: 'center'}}><NotificationsNoneOutlined /></Box>
						<Typography sx={{fontSize: 14, fontWeight: 600}}>{t('No notification')}</Typography>
						<Typography sx={{fontSize: 12.5, color: 'var(--u-muted)'}}>{t('You are up to date.')}</Typography>
					</Box>
				)
				: (
					<Box component="ul" sx={{m: 0, p: 0, listStyle: 'none', overflowY: 'auto', maxHeight: is_mobile ? 'none' : 420, flex: is_mobile ? '1 1 0' : 'none'}}>
						{items.map((n) => {

							const level = LEVELS[n.level] ?? LEVELS.info
							const Icon = level.icon
							return (
								<li key={n.id}>
									<ButtonBase onClick={() => open(n)} sx={{width: '100%', display: 'flex', alignItems: 'flex-start', gap: 1.5, px: 2, py: is_mobile ? 1.75 : 1.5, textAlign: 'left', fontFamily: 'inherit', borderBottom: '1px solid var(--u-border-soft)', bgcolor: n.read ? 'transparent' : 'var(--u-accent-hover)', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
										<Box sx={{width: 32, height: 32, flexShrink: 0, borderRadius: '50%', bgcolor: level.bg, color: level.fg, display: 'flex', alignItems: 'center', justifyContent: 'center'}}><Icon sx={{fontSize: 18}} /></Box>
										<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.25, flex: '1 1 0', minWidth: 0}}>
											<Typography sx={{fontSize: is_mobile ? 14 : 13.5, fontWeight: n.read ? 500 : 600, lineHeight: 1.35}}>{n.title}</Typography>
											{n.message && <Typography sx={{fontSize: is_mobile ? 13 : 12.5, color: 'var(--u-text3)', lineHeight: 1.4, overflowWrap: 'anywhere', display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden'}}>{n.message}</Typography>}
											<Typography sx={{fontSize: is_mobile ? 12 : 11.5, color: 'var(--u-muted)'}}>{n.category ? `${n.category} - ${relativeTime(n.date)}` : relativeTime(n.date)}</Typography>
										</Box>
										{!n.read && <Box aria-label={t('unread')} sx={{width: 8, height: 8, mt: 0.75, flexShrink: 0, borderRadius: '50%', bgcolor: 'primary.main'}} />}
									</ButtonBase>
								</li>
							)

						})}
					</Box>
				)}

			{/* browser notifications: asked once, from a click (the browsers refuse it otherwise) */}
			{BROWSER && permission === 'default' && (
				<ButtonBase onClick={allowBrowser} sx={{flexShrink: 0, justifyContent: 'center', gap: 0.75, minHeight: 44, px: 2, fontFamily: 'inherit', fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', borderTop: '1px solid var(--u-border-soft)', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
					<NotificationsActiveOutlined sx={{fontSize: 18}} />{t('Show them as browser notifications')}
				</ButtonBase>
			)}
		</Box>
	)

	return (
		<>
			<Tooltip title={t('Notifications')}>
				<IconButton aria-label={label} aria-haspopup="dialog" aria-expanded={!!anchor} onClick={(e) => setAnchor(e.currentTarget)} sx={{width: 40, height: 40, borderRadius: 2, color: anchor ? 'primary.main' : 'var(--u-text3)', bgcolor: anchor ? 'var(--u-accent-bg)' : 'transparent'}}>
					<Badge badgeContent={unread} max={99} color="error" overlap="circular" sx={{'& .MuiBadge-badge': {fontSize: 11, fontWeight: 600, height: 18, minWidth: 18, border: '2px solid var(--u-page)'}}}>
						<NotificationsOutlined sx={{fontSize: 24}} />
					</Badge>
				</IconButton>
			</Tooltip>

			{is_mobile
				? <Dialog open={!!anchor} onClose={close} fullScreen aria-label={t('Notifications')}>{content}</Dialog>
				: (
					<Popover open={!!anchor} anchorEl={anchor} onClose={close} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}
						slotProps={{paper: {role: 'dialog', 'aria-label': t('Notifications'), sx: {width: 380, maxWidth: 'calc(100vw - 16px)', mt: 0.5, borderRadius: '12px', border: '1px solid var(--u-border)', boxShadow: '0 12px 32px rgba(16, 20, 40, .14)', overflow: 'hidden'}} as object}}>
						{content}
					</Popover>
				)}
		</>
	)

}
