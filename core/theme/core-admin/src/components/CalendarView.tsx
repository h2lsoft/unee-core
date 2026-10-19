import { useCallback, useRef, useState } from 'react'
import FullCalendar from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin, { type DateClickArg } from '@fullcalendar/interaction'
import frLocale from '@fullcalendar/core/locales/fr'
import type { DatesSetArg, EventClickArg, EventContentArg, EventDropArg, EventInput } from '@fullcalendar/core'
import type { EventResizeDoneArg } from '@fullcalendar/interaction'
import { Box, Button, ButtonBase, IconButton, Typography } from '@mui/material'
import { AddCircleOutlineOutlined, ChevronLeftOutlined, ChevronRightOutlined } from '@mui/icons-material'
import { api, ApiException, dataChanged } from '../api'
import { getLanguage, t } from '../i18n'
import { BADGES } from './DataGrid'
import FormDialog from './FormDialog'
import { MenuIcon } from './icons'
import { useToast } from './Toast'
import UserAvatar, { type AvatarData } from './UserAvatar'
import CalendarUsers, { type CalendarUser } from './CalendarUsers'
import CalendarTeam, { monday, weekTitle } from './CalendarTeam'
import { useAuth } from '../auth'

type CalendarEvent = {id: number, title: string, start: string, end: string | null, allDay: boolean, color: string, status: string, user: {id: number, name: string, avatar: AvatarData}}
type Payload = {events: CalendarEvent[], users: CalendarUser[], rights: string[]}

// team: a row per planning (CalendarTeam), the others are views of FullCalendar
const VIEWS = [['dayGridMonth', 'Month'], ['timeGridWeek', 'Week'], ['timeGridDay', 'Day'], ['team', 'Team']] as const

// local date and time of a calendar date: Y-m-d and H:i
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const hm = (d: Date) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`

// remembered per browser (storage may be blocked)
function stored(key: string, fallback: string): string
{

	try { return localStorage.getItem(key) ?? fallback } catch { return fallback }

}

function store(key: string, value: string)
{

	try { localStorage.setItem(key, value) } catch { /* private mode */ }

}

function EventChip({arg}: {arg: EventContentArg})
{

	const ev = arg.event.extendedProps as {user: CalendarEvent['user'], status: string}
	const cancelled = ev.status === 'cancelled'

	return (
		<Box title={`${arg.timeText} ${arg.event.title} - ${ev.user.name}`} sx={{display: 'flex', alignItems: arg.view.type === 'dayGridMonth' ? 'center' : 'flex-start', gap: 0.5, minWidth: 0, width: '100%', px: 0.25, py: '1px', overflow: 'hidden', opacity: cancelled ? 0.55 : 1}}>
			<UserAvatar avatar={ev.user.avatar} size={16} />
			{arg.timeText && <Box component="b" sx={{fontWeight: 600, flexShrink: 0}}>{arg.timeText}</Box>}
			<Box component="span" sx={{overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: arg.view.type === 'dayGridMonth' ? 'nowrap' : 'normal', textDecoration: cancelled ? 'line-through' : 'none'}}>{arg.event.title}</Box>
		</Box>
	)

}

/**
 * calendar of the users (module calendar): FullCalendar month / week / day, filter by user, click a day to add, drag to move
 */
export default function CalendarView({icon}: {icon: string})
{

	const toast = useToast()
	const ref = useRef<FullCalendar>(null)
	const [title, setTitle] = useState('')
	const [view, setView] = useState(() => stored('unee.calendar.view', 'dayGridMonth'))
	const {user: me} = useAuth()
	// plannings shown: null = all the users, else their ids
	const [selected, setSelected] = useState<number[] | null>(() => { try { const v = JSON.parse(stored('unee.calendar.users', 'null')); return Array.isArray(v) ? v.map(Number) : null } catch { return null } })
	const [users, setUsers] = useState<Payload['users']>([])
	const [team_start, setTeamStart] = useState(() => monday(new Date()))
	const [version, setVersion] = useState(0)
	const sel_key = selected === null ? 'all' : selected.join(',')
	const [rights, setRights] = useState<string[]>([])
	const [form, setForm] = useState<{open: boolean, id: number, defaults?: Record<string, string>}>({open: false, id: 0})

	const calendar = () => ref.current?.getApi()

	// events of the visible range, loaded by FullCalendar on each move
	const events = useCallback((info: {startStr: string, endStr: string}, success: (events: EventInput[]) => void, failure: (error: Error) => void) => {

		const query = new URLSearchParams({start: info.startStr.slice(0, 10), end: info.endStr.slice(0, 10), users: selected === null ? '' : selected.join(',') || '0'})
		api<Payload>(`calendar/events/?${query}`)
			.then((data) => {

				setUsers(data.users)
				setRights(data.rights)
				success(data.events.map((e) => {

					const c = BADGES[e.color] ?? BADGES.purple
					return {...e, id: String(e.id), end: e.end ?? undefined, backgroundColor: c.bg, borderColor: c.bg, textColor: c.fg, extendedProps: {user: e.user, status: e.status}}

				}))

			})
			.catch((e) => failure(e))

	}, [sel_key])

	const refresh = () => { calendar()?.refetchEvents(); setVersion((v) => v + 1) }
	const is_team = (view === 'team')

	const changeView = (value: string) => {

		// the team view starts on the week of the date shown, the others go back to the week shown in team
		if(value === 'team')setTeamStart(monday(calendar()?.getDate() ?? new Date()))
		else if(is_team)calendar()?.gotoDate(team_start)
		setView(value)
		store('unee.calendar.view', value)
		if(value !== 'team')
		{

			calendar()?.changeView(value)
			window.setTimeout(() => calendar()?.updateSize(), 0)

		}

	}

	const changeUsers = (value: number[] | null) => {

		setSelected(value)
		store('unee.calendar.users', JSON.stringify(value))

	}

	// today / previous / next: weeks in the team view
	const nav = (step: -1 | 0 | 1) => {

		if(is_team)return setTeamStart((d) => step ? new Date(d.getFullYear(), d.getMonth(), d.getDate() + step * 7) : monday(new Date()))
		if(step === 0)calendar()?.today()
		else if(step < 0)calendar()?.prev()
		else calendar()?.next()

	}

	// new event on the day (and hour in week and day views) clicked
	const add = (date: Date, all_day: boolean, user = 0) => {

		if(!rights.includes('add'))return
		const end = new Date(date.getTime() + 3600000)
		setForm({open: true, id: 0, defaults: {date: ymd(date), ...(all_day ? {} : {hour_start: hm(date), hour_end: hm(end)}), ...(user ? {xcore_user_id: String(user)} : {})}})

	}

	const move = async (info: EventDropArg | EventResizeDoneArg) => {

		const start = info.event.start
		if(!start)return info.revert()
		const end = info.event.end ?? new Date(start.getTime() + 3600000)

		try
		{

			await api(`calendar/${info.event.id}/move/`, {method: 'POST', body: info.event.allDay ? {date: ymd(start), all_day: 'yes'} : {date: ymd(start), all_day: 'no', hour_start: hm(start), hour_end: (ymd(end) !== ymd(start)) ? '23:45' : hm(end)}})
			dataChanged('calendar')

		}
		catch(e)
		{

			info.revert()
			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}

	}

	return (
		// half the gap of the page between the title, the toolbar and the calendar
		<Box sx={{display: 'flex', flexDirection: 'column', gap: {xs: 1, md: 1.25}}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, flexWrap: 'wrap'}}>
				<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: 'var(--u-accent-bg)', color: 'primary.main'}}><MenuIcon name={icon} /></Box>
				<Typography component="h1" sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em', flexGrow: 1}}>{t('Calendar')}</Typography>
				{rights.includes('add') && <Button variant="contained" startIcon={<AddCircleOutlineOutlined />} onClick={() => add(new Date(new Date().setMinutes(0, 0, 0) + 3600000), false)}>{t('Add event')}</Button>}
			</Box>

			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap'}}>
				<Button variant="outlined" color="inherit" onClick={() => nav(0)} sx={{height: 34, borderColor: 'var(--u-border-strong)', bgcolor: 'var(--u-paper)', fontWeight: 600}}>{t('Today')}</Button>
				<IconButton aria-label={t('Previous')} onClick={() => nav(-1)}><ChevronLeftOutlined /></IconButton>
				<IconButton aria-label={t('Next')} onClick={() => nav(1)}><ChevronRightOutlined /></IconButton>
				<Typography component="h2" sx={{fontSize: 18, fontWeight: 600, flexGrow: 1, textTransform: 'capitalize'}}>{is_team ? weekTitle(team_start) : title}</Typography>
				<CalendarUsers users={users} selected={selected} me={me?.id ?? 0} onChange={changeUsers} />
				<Box role="group" aria-label={t('View')} sx={{display: 'flex', border: '1px solid var(--u-border-strong)', borderRadius: 2, overflow: 'hidden'}}>
					{VIEWS.map(([key, label], i) => (
						<ButtonBase key={key} aria-pressed={view === key} onClick={() => changeView(key)} sx={{height: 32, px: 1.75, borderLeft: i ? '1px solid var(--u-border-strong)' : 0, bgcolor: view === key ? 'var(--u-accent-bg)' : 'var(--u-paper)', color: view === key ? 'var(--u-accent-text)' : 'var(--u-text2)', fontSize: 13, fontWeight: 600}}>{t(label)}</ButtonBase>
					))}
				</Box>
			</Box>

			{is_team && <CalendarTeam start={team_start} users={users.filter((u) => selected === null || selected.includes(u.id))} version={version} canAdd={rights.includes('add')}
				onAdd={(date, uid) => { const [y, m, d] = date.split('-').map(Number); add(new Date(y, m - 1, d, 9), false, uid) }} onEdit={(id) => rights.includes('edit') && setForm({open: true, id})} />}

			<Box sx={{display: is_team ? 'none' : 'block', bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '5px', overflow: 'hidden', p: {xs: 0, md: 0},
				'--fc-border-color': 'var(--u-border-soft)', '--fc-page-bg-color': 'var(--u-paper)', '--fc-neutral-bg-color': 'var(--u-head)', '--fc-today-bg-color': 'var(--u-accent-hover)', '--fc-now-indicator-color': 'var(--mui-palette-primary-main)', '--fc-highlight-color': 'var(--u-accent-bg)',
				'& .fc': {fontSize: 13, color: 'var(--u-text)'},
				'& .fc .fc-col-header-cell': {bgcolor: 'var(--u-head)', py: 0.75, fontSize: 11, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--u-muted)'},
				'& .fc .fc-col-header-cell-cushion, & .fc .fc-daygrid-day-number': {color: 'inherit', textDecoration: 'none'},
				'& .fc .fc-daygrid-day-number': {fontSize: 12, fontWeight: 600, p: 0.75},
				'& .fc .fc-daygrid-day-top': {flexDirection: 'row'},
				'& .fc .fc-day-other .fc-daygrid-day-number': {color: 'var(--u-faint)'},
				'& .fc .fc-day-today .fc-daygrid-day-number': {m: 0.5, p: 0, width: 24, height: 24, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: '50%', bgcolor: 'primary.main', color: '#fff'},
				'& .fc .fc-scrollgrid': {border: 0},
				'& .fc-daygrid-event, & .fc-timegrid-event': {borderRadius: '4px', fontSize: 11.5, cursor: 'pointer'},
				'& .fc .fc-daygrid-day-frame': {minHeight: 104},
				'& .fc .fc-timegrid-slot': {height: 32},
				'& .fc .fc-timegrid-slot-label, & .fc .fc-timegrid-axis': {fontSize: 11, color: 'var(--u-muted)'},
			}}>
				<FullCalendar
					ref={ref}
					plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
					initialView={view === 'team' ? 'timeGridWeek' : view}
					headerToolbar={false}
					locale={getLanguage() === 'fr' ? frLocale : undefined}
					firstDay={1}
					height="auto"
					dayMaxEvents={4}
					eventDisplay="block"
					nowIndicator
					slotMinTime="07:00:00"
					slotMaxTime="21:00:00"
					scrollTime="08:00:00"
					eventTimeFormat={{hour: '2-digit', minute: '2-digit', hour12: false}}
					slotLabelFormat={{hour: '2-digit', minute: '2-digit', hour12: false}}
					editable={rights.includes('edit')}
					eventDurationEditable={rights.includes('edit')}
					events={events}
					key={sel_key}
					datesSet={(arg: DatesSetArg) => setTitle(arg.view.title)}
					eventContent={(arg) => <EventChip arg={arg} />}
					dateClick={(arg: DateClickArg) => add(arg.date, arg.allDay)}
					eventClick={(arg: EventClickArg) => rights.includes('edit') && setForm({open: true, id: Number(arg.event.id)})}
					eventDrop={move}
					eventResize={move}
				/>
			</Box>

			<FormDialog module="calendar" id={form.id} label={t('event')} open={form.open} defaults={form.defaults}
				onClose={() => setForm({...form, open: false})}
				onSaved={() => { setForm({...form, open: false}); dataChanged('calendar'); refresh() }}
				onRestored={refresh}
				onDeleted={rights.includes('delete') ? () => { setForm({...form, open: false}); dataChanged('calendar'); refresh() } : undefined} />
		</Box>
	)

}
