import { useState } from 'react'
import { Box, ButtonBase, Checkbox, InputAdornment, OutlinedInput, Popover, Typography } from '@mui/material'
import { ArrowDropDownOutlined, GroupsOutlined, LocalOfferOutlined, PersonOutlined, SearchOutlined } from '@mui/icons-material'
import { t } from '../i18n'
import UserAvatar, { type AvatarData } from './UserAvatar'

export type CalendarUser = {id: number, name: string, avatar: AvatarData, group: string, tags: string[]}

// lower case without accents, for the search
const fold = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

export function AvatarStack({users, max = 5, size = 24}: {users: CalendarUser[], max?: number, size?: number})
{

	const more = users.length - max
	return (
		<Box component="span" sx={{display: 'inline-flex', alignItems: 'center', flexShrink: 0}}>
			{users.slice(0, max).map((u, i) => <Box key={u.id} component="span" sx={{ml: i ? '-5px' : 0, display: 'inline-flex', borderRadius: '50%', boxShadow: '0 0 0 2px var(--u-paper)'}}><UserAvatar avatar={u.avatar} size={size} /></Box>)}
			{more > 0 && <Box component="span" sx={{ml: '-5px', height: size, minWidth: size, px: '5px', boxSizing: 'border-box', borderRadius: size / 2, bgcolor: 'var(--u-border-soft)', color: 'var(--u-text2)', fontSize: 10.5, fontWeight: 600, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 0 0 2px var(--u-paper)'}}>+{more}</Box>}
		</Box>
	)

}

/**
 * plannings shown in the calendar: avatars in the field, popover with search, groups and tags (a team at once), all users, only me
 * selected = null: all the users
 */
export default function CalendarUsers({users, selected, me, onChange}: {users: CalendarUser[], selected: number[] | null, me: number, onChange: (selected: number[] | null) => void})
{

	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	const [search, setSearch] = useState('')

	const ids = selected ?? users.map((u) => u.id)
	const chosen = users.filter((u) => ids.includes(u.id))
	const set = (next: number[]) => onChange(next.length === users.length && users.every((u) => next.includes(u.id)) ? null : next)

	// a group or a tag selects its members (only them when everybody is shown), or unselects them when they all are selected
	const team = (members: CalendarUser[]) => {

		if(selected === null)return set(members.map((u) => u.id))
		const all = members.every((u) => ids.includes(u.id))
		set(all ? ids.filter((id) => !members.some((u) => u.id === id)) : [...new Set([...ids, ...members.map((u) => u.id)])])

	}

	const groups = [...new Set(users.map((u) => u.group).filter(Boolean))].sort()
	const tags = [...new Set(users.flatMap((u) => u.tags))].sort()
	const query = fold(search.trim())
	const list = users.filter((u) => !query || fold(u.name).includes(query) || u.tags.some((tg) => fold(tg).includes(query))).sort((a, b) => Number(ids.includes(b.id)) - Number(ids.includes(a.id)))

	const chip = (key: string, label: string, members: CalendarUser[], icon: React.ReactNode) => {

		const on = selected !== null && members.length > 0 && members.every((u) => ids.includes(u.id))
		return (
			<ButtonBase key={key} aria-pressed={on} onClick={() => team(members)} sx={{height: 26, gap: 0.5, px: 1.25, borderRadius: '13px', fontSize: 12, fontWeight: 500, border: '1px solid', borderColor: on ? 'var(--u-accent-border)' : 'var(--u-border)', bgcolor: on ? 'var(--u-accent-bg)' : 'var(--u-paper)', color: on ? 'var(--u-accent-text)' : 'var(--u-text2)'}}>
				{icon}{label} ({members.length})
			</ButtonBase>
		)

	}

	const label = selected === null ? t('All users') : (chosen.length === 1 ? chosen[0].name : (chosen.length ? t('[COUNT] plannings', {COUNT: chosen.length}) : t('No planning')))

	return (
		<>
			<ButtonBase aria-haspopup="dialog" aria-label={t('Plannings')} onClick={(e) => setAnchor(e.currentTarget)}
				sx={{width: {xs: '100%', sm: 260}, height: 34, gap: 1, px: 1, justifyContent: 'flex-start', border: '1px solid', borderColor: anchor ? 'primary.main' : 'var(--u-border-strong)', borderRadius: 2, bgcolor: 'var(--u-paper)', fontSize: 13.5, color: 'var(--u-text)'}}>
				{chosen.length > 0 && <AvatarStack users={chosen} />}
				<Box component="span" sx={{flexGrow: 1, textAlign: 'left', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{label}</Box>
				<ArrowDropDownOutlined sx={{color: 'var(--u-text3)'}} />
			</ButtonBase>

			<Popover open={!!anchor} anchorEl={anchor} onClose={() => { setAnchor(null); setSearch('') }} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}
				slotProps={{paper: {sx: {mt: 0.75, width: 380, maxWidth: 'calc(100vw - 16px)', border: '1px solid var(--u-border)', borderRadius: '10px', boxShadow: '0 16px 40px rgba(16, 20, 40, .18)', display: 'flex', flexDirection: 'column', maxHeight: 'min(620px, calc(100vh - 140px))'}}}}>
				<Box sx={{p: 1.5, pb: 1, display: 'flex', flexDirection: 'column', gap: 1.25}}>
					<OutlinedInput autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search a person or a tag...')} inputProps={{'aria-label': t('Search a person or a tag...')}}
						startAdornment={<InputAdornment position="start"><SearchOutlined sx={{fontSize: 18, color: 'var(--u-muted)'}} /></InputAdornment>} sx={{height: 34, fontSize: 13.5}} />
					{(groups.length > 0 || tags.length > 0) && (
						<Box sx={{display: 'flex', flexWrap: 'wrap', gap: 0.75}}>
							{groups.map((g) => chip(`g-${g}`, g, users.filter((u) => u.group === g), <GroupsOutlined sx={{fontSize: 15}} />))}
							{tags.map((tg) => chip(`t-${tg}`, tg, users.filter((u) => u.tags.includes(tg)), <LocalOfferOutlined sx={{fontSize: 14}} />))}
						</Box>
					)}
				</Box>
				<Box sx={{px: 0.75, borderTop: '1px solid var(--u-border-soft)', overflowY: 'auto', flexGrow: 1}}>
					{!query && (
						<>
							<Box component="label" sx={{height: 40, display: 'flex', alignItems: 'center', gap: 1, px: 0.5, fontSize: 13.5, fontWeight: 600, cursor: 'pointer'}}>
								<Checkbox size="small" checked={chosen.length === users.length} indeterminate={chosen.length > 0 && chosen.length < users.length} onChange={(e) => set(e.target.checked ? users.map((u) => u.id) : [])} />
								<Box component="span" sx={{flexGrow: 1}}>{t('All users')}</Box>
								<Box component="span" sx={{fontSize: 12, fontWeight: 400, color: 'var(--u-muted)', pr: 1}}>{chosen.length} / {users.length}</Box>
							</Box>
							<ButtonBase onClick={() => set([me])} sx={{width: '100%', height: 36, justifyContent: 'flex-start', gap: 1.5, px: 1.25, borderRadius: 1.5, fontSize: 13.5, '&:hover': {bgcolor: 'var(--u-hover)'}}}><PersonOutlined sx={{fontSize: 20, color: 'var(--u-muted)'}} />{t('Only me')}</ButtonBase>
							<Box sx={{height: '1px', bgcolor: 'var(--u-border-soft)', my: 0.5}} />
						</>
					)}
					{list.map((u) => (
						<Box component="label" key={u.id} sx={{height: 38, display: 'flex', alignItems: 'center', gap: 1, px: 0.5, borderRadius: 1.5, cursor: 'pointer', '&:hover': {bgcolor: 'var(--u-hover)'}}}>
							<Checkbox size="small" checked={ids.includes(u.id)} onChange={(e) => set(e.target.checked ? [...ids, u.id] : ids.filter((id) => id !== u.id))} />
							<UserAvatar avatar={u.avatar} size={26} />
							<Box component="span" sx={{flexGrow: 1, minWidth: 0, fontSize: 13.5, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{u.name}</Box>
							<Box component="span" sx={{fontSize: 11.5, color: 'var(--u-muted)', pr: 1, whiteSpace: 'nowrap'}}>{u.group}</Box>
						</Box>
					))}
					{list.length === 0 && <Typography sx={{p: 2, fontSize: 13, color: 'var(--u-muted)', textAlign: 'center'}}>{t('Nobody found')}</Typography>}
				</Box>
				<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5, px: 1.5, py: 1, borderTop: '1px solid var(--u-border-soft)', fontSize: 12.5, fontWeight: 600}}>
					<ButtonBase onClick={() => onChange(null)} sx={{color: 'var(--u-accent-text)', fontSize: 12.5, fontWeight: 600}}>{t('Select all')}</ButtonBase>
					<ButtonBase onClick={() => set([])} sx={{color: 'var(--u-text3)', fontSize: 12.5, fontWeight: 600}}>{t('Clear')}</ButtonBase>
					<Box sx={{flexGrow: 1}} />
					<Box component="span" sx={{fontSize: 12, fontWeight: 400, color: 'var(--u-muted)'}}>{t('remembered per browser')}</Box>
				</Box>
			</Popover>
		</>
	)

}
