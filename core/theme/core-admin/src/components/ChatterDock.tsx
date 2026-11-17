import { useEffect, useRef, useState, useSyncExternalStore, type ReactNode } from 'react'
import { Backdrop, Box, ButtonBase, CircularProgress, IconButton, Paper, Portal, Slide, Tooltip, useMediaQuery, useTheme } from '@mui/material'
import { ChatBubble as ChatBubbleFilled, ChatBubbleOutlineOutlined, CloseOutlined, Event as EventFilled, EventOutlined, Group as GroupFilled, GroupOutlined } from '@mui/icons-material'
import { t } from '../i18n'
import { ChatterActivities, ChatterFollowers, ChatterThread, useChatter } from './Chatter'

export type ChatterView = 'thread' | 'activities' | 'followers'
type Target = {module: string, id: number, label: string}

// width of the offcanvas panel (over the dialogs): 800 px, never more than the screen less the column of the buttons, full width on a phone
export const CHATTER_WIDTH = 'min(800px, calc(100vw - 80px))'

// store of the screens showing a record (the last one wins) and of the view opened
let targets: {key: number, target: Target}[] = []
let opened: ChatterView | null = null
let seq = 0
let snapshot: {target: Target | null, view: ChatterView | null} = {target: null, view: null}
const listeners = new Set<() => void>()

const emit = () => {

	const target = targets.length ? targets[targets.length - 1].target : null
	// no record anymore: the panel closes
	if(!target)opened = null
	snapshot = {target, view: opened}
	listeners.forEach((l) => l())

}

const subscribe = (l: () => void) => { listeners.add(l); return () => { listeners.delete(l) } }
const read = () => snapshot
const open = (view: ChatterView | null) => { opened = view; emit() }

/**
 * the record of a screen gets the chatter buttons on the right of the screen while the screen is shown, ex:
 * useChatterTarget('article', 12, 'Article #12', true); the form of a record does it when its plugin has the option chatter
 */
export function useChatterTarget(module: string, id: number, label: string, enabled: boolean = true)
{

	useEffect(() => {

		if(!enabled || !module || id <= 0)return
		const key = ++seq
		targets = [...targets, {key, target: {module, id, label}}]
		emit()
		return () => { targets = targets.filter((x) => x.key !== key); emit() }

	}, [module, id, label, enabled])

}

// view of the chatter opened (null: closed)
export function useChatterView(): ChatterView | null
{

	return useSyncExternalStore(subscribe, read).view

}

// icon: empty view, filled: the button colored by the state of its view
const VIEWS: {key: ChatterView, label: string, icon: ReactNode, filled: ReactNode}[] = [
	{key: 'thread', label: 'Thread', icon: <ChatBubbleOutlineOutlined />, filled: <ChatBubbleFilled />},
	{key: 'activities', label: 'Activities', icon: <EventOutlined />, filled: <EventFilled />},
	{key: 'followers', label: 'Followers', icon: <GroupOutlined />, filled: <GroupFilled />},
]

/**
 * chatter of the record shown (useChatterTarget): floating buttons on the right of the screen, out of the dialogs (thread,
 * activities, followers with their counts), each one opens its view in an offcanvas panel along the right edge
 */
export default function ChatterDock()
{

	const theme = useTheme()
	const phone = useMediaQuery(theme.breakpoints.down('sm'))
	const {target, view} = useSyncExternalStore(subscribe, read)
	const {chat, error} = useChatter(target?.module ?? '', target?.id ?? 0)

	// own layer, last child of the body when a record shows its chatter and when a view opens: the buttons, the backdrop
	// and the panel stay above every dialog opened before (same z-index, the dom order decides), under the ones opened from the panel
	const [layer] = useState(() => document.createElement('div'))
	useEffect(() => {

		document.body.appendChild(layer)
		return () => layer.remove()

	}, [layer])
	useEffect(() => {

		if(!target)return
		document.body.appendChild(layer)
		// hidden by a dialog opened before (aria-hidden on the other children of the body)
		layer.removeAttribute('aria-hidden')

	}, [layer, target, view])

	// escape closes the panel only, never the dialog under it; the panel takes the focus when it opens
	useEffect(() => {

		if(!view)return
		const panel = () => document.querySelector<HTMLElement>('[data-chatter-panel]')
		const p = panel()
		if(p && !p.contains(document.activeElement))p.focus({preventScroll: true})

		// focus out of the panel (dialog under it, buttons): the dialog never sees the key, a modal opened over the panel (confirmation, menu) keeps it
		const capture = (e: KeyboardEvent) => {

			if(e.key !== 'Escape')return
			const p = panel()
			const target = e.target instanceof Element ? e.target : null
			if(!p || (target && p.contains(target)))return
			const modal = target?.closest('.MuiModal-root')
			if(modal && p.compareDocumentPosition(modal) & Node.DOCUMENT_POSITION_FOLLOWING)return
			e.stopPropagation()
			e.preventDefault()
			open(null)

		}

		// focus in the panel, by bubbling: a list open in it (emojis, mentions) closes first and stops the key
		const bubble = (e: KeyboardEvent) => { if(e.key === 'Escape' && e.target instanceof Element && panel()?.contains(e.target))open(null) }

		window.addEventListener('keydown', capture, true)
		window.addEventListener('keydown', bubble)
		return () => {

			window.removeEventListener('keydown', capture, true)
			window.removeEventListener('keydown', bubble)

		}

	}, [view])

	// panel closed: the focus goes back to the dialog under it (escape then closes the dialog)
	const was_open = useRef(false)
	useEffect(() => {

		if(view)
		{

			was_open.current = true
			return

		}

		if(!was_open.current)return
		was_open.current = false
		const dialogs = document.querySelectorAll<HTMLElement>('.MuiDialog-container')
		const last = dialogs[dialogs.length - 1]
		if(last && !last.contains(document.activeElement))last.focus({preventScroll: true})

	}, [view])

	// new messages and readings of the others: loaded again every 30 s while the page is shown
	const refresh = chat?.refresh
	useEffect(() => {

		if(!refresh)return
		const timer = window.setInterval(() => { if(document.visibilityState === 'visible')refresh() }, 30000)
		return () => window.clearInterval(timer)

	}, [refresh])

	if(!target || error)return null

	const data = chat?.data
	const primary = theme.vars?.palette.primary.main ?? theme.palette.primary.main
	// background of a button by the state of its view ('' = empty view): thread with messages or notes green,
	// activities red when late, orange today, green planned, followers blue
	const talk = !!data?.thread.some((i) => i.kind === 'message' || i.kind === 'note')
	const activities = data?.activities ?? []
	const activity = activities.some((a) => a.state === 'late') ? 'var(--u-pill-red)' : (activities.some((a) => a.state === 'today') ? 'var(--u-pill-orange)' : 'var(--u-pill-green)')
	const pills: Record<ChatterView, {count: number, badge: string, fill: string}> = {
		thread: {count: data?.unread ?? 0, badge: primary, fill: talk ? 'var(--u-pill-green)' : ''},
		activities: {count: activities.length, badge: 'var(--u-text)', fill: activities.length ? activity : ''},
		followers: {count: data?.followers.length ?? 0, badge: 'var(--u-text)', fill: data?.followers.length ? primary : ''},
	}
	const current = VIEWS.find((v) => v.key === view)

	return (
		<Portal container={layer}>
			{/* above the dialogs: mounted after them, same layer; the backdrop under the buttons and the panel closes it */}
			<Backdrop open={!!view} onClick={() => open(null)} sx={{zIndex: theme.zIndex.modal, bgcolor: 'rgba(0, 0, 0, .35)'}} />
			{!(phone && view) && (
				<Box sx={{position: 'fixed', zIndex: theme.zIndex.modal, right: view ? `calc(${CHATTER_WIDTH} + 16px)` : 20, top: '50%', transform: 'translateY(-50%)', display: 'flex', flexDirection: 'column', gap: 1.5, transition: 'right .2s'}}>
					{VIEWS.map((v) => {

						// the open view: a ring of its color around it
						const on = view === v.key
						const pill = pills[v.key]
						return (
							<Tooltip key={v.key} title={t(v.label)} placement="left">
								<ButtonBase aria-label={t(v.label)} aria-pressed={on} onClick={() => open(on ? null : v.key)}
									sx={{position: 'relative', width: 48, height: 48, borderRadius: '50%', bgcolor: pill.fill || 'var(--u-paper)', color: pill.fill ? '#fff' : (on ? primary : 'var(--u-text2)'), border: pill.fill ? 0 : '1px solid var(--u-border)', boxShadow: on ? `0 0 0 3px var(--u-paper), 0 0 0 5px ${pill.fill || primary}, 0 6px 18px var(--u-shadow)` : '0 4px 14px var(--u-shadow)', transition: 'box-shadow .15s, filter .15s', '&:hover': pill.fill ? {filter: 'brightness(.92)'} : {bgcolor: 'var(--u-hover)'}, '& svg': {fontSize: 22}}}>
									{pill.fill ? v.filled : v.icon}
									{pill.count > 0 && (
										<Box component="span" sx={{position: 'absolute', top: -4, right: -4, minWidth: 20, height: 20, px: 0.5, boxSizing: 'border-box', borderRadius: '10px', border: '2px solid var(--u-paper)', bgcolor: pill.badge, color: 'var(--u-paper)', fontSize: 10.5, fontWeight: 600, lineHeight: 1, display: 'flex', alignItems: 'center', justifyContent: 'center'}}>{pill.count > 99 ? '99+' : pill.count}</Box>
									)}
								</ButtonBase>
							</Tooltip>
						)

					})}
				</Box>
			)}

			<Slide direction="left" in={!!view} mountOnEnter unmountOnExit>
				<Paper data-chatter-panel tabIndex={-1} role="complementary" aria-label={current ? t(current.label) : t('Chatter')} square elevation={0}
					sx={{position: 'fixed', zIndex: theme.zIndex.modal, top: 0, right: 0, bottom: 0, width: {xs: '100vw', sm: CHATTER_WIDTH}, display: 'flex', flexDirection: 'column', outline: 0, bgcolor: 'var(--u-paper)', borderLeft: '1px solid var(--u-border)', boxShadow: '-10px 0 32px var(--u-shadow)'}}>
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, pl: 2, pr: 1, py: 1.5, borderBottom: '1px solid var(--u-border)'}}>
						<Box sx={{width: 40, height: 40, flexShrink: 0, borderRadius: '50%', bgcolor: 'var(--u-accent-bg)', color: 'primary.main', display: 'flex', alignItems: 'center', justifyContent: 'center', '& svg': {fontSize: 20}}}>{current?.icon}</Box>
						<Box sx={{flexGrow: 1, minWidth: 0}}>
							<Box component="h2" sx={{m: 0, fontSize: 15, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{target.label}</Box>
							<Box sx={{fontSize: 12, color: 'var(--u-text3)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>
								{(view === 'thread' && data?.followers.length) ? data.followers.map((f) => f.name).join(', ') : (current ? t(current.label) : '')}
							</Box>
						</Box>
						{/* on a phone the panel covers the buttons: its views as tabs */}
						{phone && VIEWS.map((v) => <IconButton key={v.key} aria-label={t(v.label)} aria-pressed={view === v.key} onClick={() => open(v.key)} sx={{color: view === v.key ? 'primary.main' : 'var(--u-text3)'}}>{v.icon}</IconButton>)}
						<IconButton aria-label={t('Close the panel')} onClick={() => open(null)}><CloseOutlined /></IconButton>
					</Box>
					{/* the thread scrolls by itself with its composer at the bottom, the other views in the panel */}
					<Box sx={view === 'thread' ? {flex: '1 1 auto', minHeight: 0, display: 'flex', flexDirection: 'column', overflow: 'hidden'} : {flex: '1 1 auto', minHeight: 0, overflowY: 'auto', p: 2}}>
						{!chat && <Box sx={{display: 'flex', justifyContent: 'center', py: 4}}><CircularProgress size={22} /></Box>}
						{chat && view === 'thread' && <ChatterThread chat={chat} />}
						{chat && view === 'activities' && <ChatterActivities chat={chat} />}
						{chat && view === 'followers' && <ChatterFollowers chat={chat} />}
					</Box>
				</Paper>
			</Slide>
		</Portal>
	)

}
