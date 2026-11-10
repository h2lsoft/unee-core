import { useEffect, useState } from 'react'
import { Box, Button, ButtonBase, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, OutlinedInput, Typography, useMediaQuery, useTheme } from '@mui/material'
import { ArrowForwardOutlined, CheckOutlined, CloseOutlined, LockOutlined } from '@mui/icons-material'
import { api, ApiException, type WorkflowHistory, type WorkflowState, type WorkflowSummary } from '../api'
import { getLanguage, t } from '../i18n'
import { Badge, BADGES } from './DataGrid'
import UserAvatar from './UserAvatar'
import { useToast } from './Toast'

// transition shown by the form (approvals of the record) or the grid (approvals needed only)
export type TransitionChoice = {code: string, label: string, from: string, to: string, to_label: string, comment: boolean, needed: number, given?: number, users?: string[]}

const format = (date: string, opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(getLanguage(), {...opts, hourCycle: 'h23'}).format(new Date(date.replace(' ', 'T')))

// colored dot of a state (buttons and menus of the transitions)
export function StateDot({color}: {color?: string})
{

	return <Box component="span" sx={{width: 8, height: 8, flexShrink: 0, borderRadius: '50%', bgcolor: (BADGES[color ?? 'grey'] ?? BADGES.grey).fg}} />

}

/**
 * top of the form of a record with a workflow: state, since when, who edits it (lock when the user can not), approvals given, history link
 */
export function WorkflowBand({wf, onHistory}: {wf: WorkflowSummary, onHistory: () => void})
{

	const locked = !wf.editable
	const groups = wf.editable_by.join(', ')
	const rights = locked ? (groups ? t('Read only for you: editable by [GROUPS]', {GROUPS: groups}) : t('Locked in this state: read only')) : (groups ? t('Editable by [GROUPS]', {GROUPS: groups}) : '')
	const pending = wf.transitions.filter((x) => x.approvals.needed > 1 && x.approvals.given > 0)

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 1, mb: 2}}>
			<Box sx={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 1.5, px: 1.5, py: 1.25, borderRadius: '8px', border: '1px solid', borderColor: locked ? 'var(--u-orange-bg)' : 'var(--u-border-soft)', bgcolor: locked ? 'var(--u-orange-bg)' : 'var(--u-widget)'}}>
				<Box sx={{display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 1.25, minWidth: 0}}>
					{wf.state && <Badge value={wf.state.label} color={wf.state.color} />}
					{wf.since && <Typography component="span" sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('since [DATE]', {DATE: format(wf.since, {dateStyle: 'medium', timeStyle: 'short'})})}</Typography>}
					{rights && (
						<Typography component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5, fontSize: 12.5, color: locked ? 'var(--u-orange)' : 'var(--u-text3)'}}>
							{locked && <LockOutlined sx={{fontSize: 15}} />}{rights}
						</Typography>
					)}
				</Box>
				<ButtonBase onClick={onHistory} sx={{fontFamily: 'inherit', fontSize: 12.5, fontWeight: 500, color: 'var(--u-accent-text)', '&:hover': {textDecoration: 'underline'}}}>{t('Workflow history ([COUNT])', {COUNT: wf.history_count})}</ButtonBase>
			</Box>
			{pending.map((x) => (
				<Typography key={x.code} sx={{display: 'flex', alignItems: 'center', gap: 0.75, fontSize: 12.5, color: 'var(--u-text2)'}}>
					<CheckOutlined sx={{fontSize: 16, color: 'primary.main'}} />
					{t('[TRANSITION]: [GIVEN] of [NEEDED] approvals ([USERS])', {TRANSITION: x.label, GIVEN: x.approvals.given, NEEDED: x.approvals.needed, USERS: x.approvals.users.join(', ')})}
				</Typography>
			))}
		</Box>
	)

}

/**
 * confirmation of a transition on one record or a selection: from -> to, approvals, comment (required or optional)
 * beforeFire: the form saves its changes first (false = stop, the form shows its errors)
 */
export function TransitionDialog({module, ids, transition, states, skipped = 0, dirty = false, beforeFire, onClose, onDone}: {module: string, ids: number[], transition: TransitionChoice, states: Record<string, WorkflowState>, skipped?: number, dirty?: boolean, beforeFire?: () => Promise<boolean>, onClose: () => void, onDone: () => void})
{

	const theme = useTheme()
	const full_screen = useMediaQuery(theme.breakpoints.down('sm'))
	const toast = useToast()
	const [comment, setComment] = useState('')
	const [error, setError] = useState('')
	const [busy, setBusy] = useState(false)

	const from = states[transition.from]
	const to = states[transition.to]
	const many = ids.length > 1
	const given = transition.given ?? 0
	const approvals = (transition.needed < 2) ? ''
		: (many || transition.given === undefined) ? t('[NEEDED] approvals from different users are needed: yours is counted, the record moves once they are all given.', {NEEDED: transition.needed})
		: (given + 1 >= transition.needed) ? t('Your approval completes the [NEEDED] needed ([USERS] already approved): the record goes to [STATE].', {NEEDED: transition.needed, USERS: (transition.users ?? []).join(', '), STATE: transition.to_label})
		: t('[NEEDED] approvals from different users are needed, [GIVEN] given: the record stays in [STATE] until they are all given.', {NEEDED: transition.needed, GIVEN: given, STATE: from?.label ?? transition.from})

	const confirm = async () => {

		if(transition.comment && !comment.trim()){ setError(t('A comment is required.')); return }
		setBusy(true)
		setError('')

		try
		{

			if(beforeFire && !(await beforeFire())){ onClose(); return }

			if(many)
			{

				const r = await api<{done: number, approved: number, errors: {id: number, message: string}[]}>(`${module}/batch/workflow/${transition.code}/`, {method: 'POST', body: {ids, comment}})
				const parts = [r.done ? t('[COUNT] moved to [STATE]', {COUNT: r.done, STATE: transition.to_label}) : '', r.approved ? t('[COUNT] approvals saved', {COUNT: r.approved}) : '', r.errors.length ? t('[COUNT] refused', {COUNT: r.errors.length}) : ''].filter(Boolean)
				const details = r.errors.slice(0, 3).map((e) => `#${e.id} ${e.message}`).join(' - ')
				toast(parts.join(', ') + (details ? ` (${details}${r.errors.length > 3 ? '...' : ''})` : ''), r.errors.length ? ((r.done || r.approved) ? 'warning' : 'error') : 'success')

			}
			else
			{

				const r = await api<{done: boolean, approvals: number, needed: number}>(`${module}/${ids[0]}/workflow/${transition.code}/`, {method: 'POST', body: {comment}})
				toast(r.done ? t('[TRANSITION]: now [STATE]', {TRANSITION: transition.label, STATE: transition.to_label}) : t('Approval saved: [COUNT] more needed', {COUNT: r.needed - r.approvals}))

			}

			onDone()

		}
		catch(e)
		{

			setError(e instanceof ApiException ? e.error.message : t('network error, please retry'))
			setBusy(false)

		}

	}

	return (
		<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && onClose()} fullScreen={full_screen} maxWidth="xs" fullWidth>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 17, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{many ? t('[TRANSITION] - [COUNT] records', {TRANSITION: transition.label, COUNT: ids.length}) : transition.label}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{display: 'flex', flexDirection: 'column', gap: 2, pt: '4px !important'}}>
				<Box sx={{display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 1}}>
					<Badge value={from?.label ?? transition.from} color={from?.color} />
					<ArrowForwardOutlined sx={{fontSize: 16, color: 'var(--u-muted)'}} />
					<Badge value={to?.label ?? transition.to_label} color={to?.color} />
				</Box>
				{approvals && <Box sx={{fontSize: 13, lineHeight: 1.5, color: 'var(--u-text2)', bgcolor: 'var(--u-widget)', borderRadius: '6px', px: 1.25, py: 1}}>{approvals}</Box>}
				{skipped > 0 && <Typography sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('[COUNT] selected records in another state are left out.', {COUNT: skipped})}</Typography>}
				<Box component="label" sx={{display: 'flex', flexDirection: 'column', gap: 0.75, fontSize: 13, fontWeight: 500, color: 'var(--u-text2)'}}>
					{transition.comment ? t('Comment (required)') : t('Comment (optional)')}
					<OutlinedInput multiline minRows={3} value={comment} onChange={(e) => { setComment(e.target.value); setError('') }} placeholder={t('Kept in the history')} sx={{height: 'auto', fontSize: 14}} />
				</Box>
				{dirty && <Typography sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Your changes to the form are saved first.')}</Typography>}
				{error && <Box role="alert" sx={{px: 1.5, py: 1, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 13.5}}>{error}</Box>}
			</DialogContent>
			<DialogActions sx={{px: 3, pb: 2.5}}>
				<Button variant="contained" disabled={busy} onClick={confirm}>{busy ? <CircularProgress size={20} color="inherit" /> : transition.label}</Button>
			</DialogActions>
		</Dialog>
	)

}

/**
 * changes of state of a record, last first (GET /api/<module>/<id>/workflow/)
 */
export function WorkflowHistoryDialog({module, id, title, onClose}: {module: string, id: number, title: string, onClose: () => void})
{

	const theme = useTheme()
	const full_screen = useMediaQuery(theme.breakpoints.down('md'))
	const [data, setData] = useState<{history: WorkflowHistory[], states: Record<string, WorkflowState>} | null>(null)

	useEffect(() => {

		api<{history: WorkflowHistory[], states: Record<string, WorkflowState>}>(`${module}/${id}/workflow/`).then(setData).catch(() => setData({history: [], states: {}}))

	}, [module, id])

	const badge = (label: string, code: string) => (code === '') ? <Badge value={label} /> : <Badge value={label} color={data?.states[code]?.color} />

	return (
		<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && onClose()} fullScreen={full_screen} maxWidth="sm" fullWidth>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', pr: 1.5, fontSize: 18, fontWeight: 600}}>
				<Box component="span" sx={{flexGrow: 1}}>{title}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{pt: 0}}>
				{!data && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
				{data?.history.length === 0 && <Typography sx={{py: 4, textAlign: 'center', color: 'var(--u-muted)'}}>{t('No change of state yet')}</Typography>}
				{data?.history.map((h, i) => (
					<Box key={i} sx={{display: 'flex', gap: 1.5, py: 1.5, borderBottom: '1px solid var(--u-border-soft)'}}>
						{h.avatar ? <UserAvatar avatar={h.avatar} size={30} /> : <Box sx={{width: 30, height: 30, flexShrink: 0, borderRadius: '50%', bgcolor: 'var(--u-border-soft)', color: 'var(--u-text3)', fontSize: 11, fontWeight: 600, display: 'flex', alignItems: 'center', justifyContent: 'center'}}>S</Box>}
						<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75, flex: '1 1 0', minWidth: 0}}>
							<Box sx={{display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', flexWrap: 'wrap', gap: 1}}>
								<Typography component="span" sx={{fontSize: 13.5}}><b>{h.user}</b> - {h.transition}</Typography>
								<Typography component="span" sx={{fontSize: 12, color: 'var(--u-muted)'}}>{format(h.date, {dateStyle: 'medium', timeStyle: 'short'})}</Typography>
							</Box>
							<Box sx={{display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 0.75}}>
								{badge(h.from, h.from_state)}
								<ArrowForwardOutlined sx={{fontSize: 14, color: 'var(--u-muted)'}} />
								{badge(h.to, h.to_state)}
							</Box>
							{h.comment && <Box sx={{fontSize: 13, color: 'var(--u-text2)', bgcolor: 'var(--u-widget)', borderLeft: '3px solid var(--u-border-strong)', borderRadius: '4px', px: 1.25, py: 0.75, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere'}}>{h.comment}</Box>}
						</Box>
					</Box>
				))}
			</DialogContent>
		</Dialog>
	)

}
