import { useEffect, useState } from 'react'
import { Box, ButtonBase, CircularProgress, Typography } from '@mui/material'
import { api } from '../api'
import { getLanguage, t } from '../i18n'
import { BADGES } from './DataGrid'
import type { CalendarUser } from './CalendarUsers'
import UserAvatar from './UserAvatar'

type TeamEvent = {id: number, title: string, start: string, end: string | null, allDay: boolean, color: string, status: string, user: {id: number}}

export const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const addDays = (d: Date, n: number) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)

// monday of the week of a date
export const monday = (d: Date) => addDays(d, -((d.getDay() + 6) % 7))

// title of a week: 19 - 25 oct. 2026
export const weekTitle = (start: Date) => {

	const end = addDays(start, 6)
	const day = (d: Date, opts: Intl.DateTimeFormatOptions) => d.toLocaleDateString(getLanguage(), opts)
	return `${day(start, {day: 'numeric', ...(start.getMonth() !== end.getMonth() ? {month: 'short'} : {})})} - ${day(end, {day: 'numeric', month: 'short', year: 'numeric'})}`

}

/**
 * view team of the calendar: a row per planning, a column per day of the week, click a cell to add an event for that person and day
 */
export default function CalendarTeam({start, users, version, canAdd, onAdd, onEdit}: {start: Date, users: CalendarUser[], version: number, canAdd: boolean, onAdd: (date: string, user: number) => void, onEdit: (id: number) => void})
{

	const [events, setEvents] = useState<TeamEvent[] | null>(null)
	const days = Array.from({length: 7}, (_, i) => addDays(start, i))
	const today = ymd(new Date())
	const ids = users.map((u) => u.id).join(',')

	useEffect(() => {

		let alive = true
		setEvents(null)
		if(!ids)return setEvents([])
		const query = new URLSearchParams({start: ymd(start), end: ymd(addDays(start, 7)), users: ids})
		api<{events: TeamEvent[]}>(`calendar/events/?${query}`).then((d) => alive && setEvents(d.events)).catch(() => alive && setEvents([]))
		return () => { alive = false }

	}, [start.getTime(), ids, version])

	const grid = {display: 'grid', gridTemplateColumns: {xs: '150px repeat(7, minmax(110px, 1fr))', md: '210px repeat(7, minmax(0, 1fr))'}}

	return (
		<Box sx={{bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '5px', overflow: 'auto', maxHeight: 'calc(100vh - 210px)'}}>
			<Box sx={{...grid, position: 'sticky', top: 0, zIndex: 2, bgcolor: 'var(--u-head)', borderBottom: '1px solid var(--u-border)', minWidth: {xs: 920, md: 0}}}>
				<Box sx={{px: 1.5, py: 1, fontSize: 11, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--u-muted)', position: 'sticky', left: 0, bgcolor: 'var(--u-head)'}}>{t('Person')}</Box>
				{days.map((d) => (
					<Box key={d.getTime()} sx={{px: 1, py: 1, borderLeft: '1px solid var(--u-border-soft)', fontSize: 11, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase', color: ymd(d) === today ? 'var(--u-accent-text)' : 'var(--u-muted)'}}>
						{d.toLocaleDateString(getLanguage(), {weekday: 'short', day: 'numeric'})}
					</Box>
				))}
			</Box>

			{events === null && <Box sx={{display: 'flex', justifyContent: 'center', py: 4}}><CircularProgress size={24} /></Box>}
			{events !== null && users.map((u) => (
				<Box key={u.id} sx={{...grid, borderTop: '1px solid var(--u-border-soft)', minWidth: {xs: 920, md: 0}}}>
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1, px: 1.5, py: 0.75, position: 'sticky', left: 0, zIndex: 1, bgcolor: 'var(--u-paper)', minWidth: 0}}>
						<UserAvatar avatar={u.avatar} size={26} />
						<Box sx={{display: 'flex', flexDirection: 'column', lineHeight: 1.25, minWidth: 0}}>
							<Box component="span" sx={{fontSize: 13, fontWeight: 500, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{u.name}</Box>
							{u.group && <Box component="span" sx={{fontSize: 11.5, color: 'var(--u-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{u.group}</Box>}
						</Box>
					</Box>
					{days.map((d) => {

						const date = ymd(d)
						const list = events.filter((e) => e.user.id === u.id && e.start.slice(0, 10) === date).sort((a, b) => Number(b.allDay) - Number(a.allDay) || a.start.localeCompare(b.start))
						return (
							<Box key={date} onClick={() => canAdd && onAdd(date, u.id)} sx={{p: 0.625, minHeight: 46, borderLeft: '1px solid var(--u-border-soft)', display: 'flex', flexDirection: 'column', gap: 0.375, bgcolor: date === today ? 'var(--u-accent-hover)' : 'transparent', cursor: canAdd ? 'cell' : 'default', minWidth: 0}}>
								{list.map((e) => {

									const c = BADGES[e.color] ?? BADGES.purple
									return (
										<ButtonBase key={e.id} title={`${e.allDay ? '' : `${e.start.slice(11, 16)} - ${(e.end ?? e.start).slice(11, 16)} `}${e.title}`} onClick={(ev) => { ev.stopPropagation(); onEdit(e.id) }}
											sx={{justifyContent: 'flex-start', px: 0.75, py: '2px', borderRadius: '4px', bgcolor: c.bg, color: c.fg, fontSize: 11.5, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', display: 'block', textAlign: 'left', opacity: e.status === 'cancelled' ? 0.55 : 1, textDecoration: e.status === 'cancelled' ? 'line-through' : 'none'}}>
											{!e.allDay && <Box component="b" sx={{fontWeight: 600, mr: 0.5}}>{e.start.slice(11, 16)}</Box>}{e.title}
										</ButtonBase>
									)

								})}
							</Box>
						)

					})}
				</Box>
			))}
			{events !== null && users.length === 0 && <Typography sx={{p: 3, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{t('No planning selected')}</Typography>}
		</Box>
	)

}
