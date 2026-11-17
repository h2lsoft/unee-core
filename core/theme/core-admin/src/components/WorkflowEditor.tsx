import { useEffect, useRef, useState } from 'react'
import { useBlocker, useNavigate } from 'react-router'
import { Box, Button, ButtonBase, Checkbox, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, MenuItem, OutlinedInput, Select, Tooltip, Typography } from '@mui/material'
import { AddOutlined, ArrowBackOutlined, CloseOutlined, DeleteOutlineOutlined, SaveOutlined } from '@mui/icons-material'
import { api, ApiException, dataChanged } from '../api'
import { t } from '../i18n'
import { Badge, BADGES } from './DataGrid'
import { useToast } from './Toast'

export type WfState = {code: string, label: string, color: string, initial: boolean, final: boolean, edit_groups: number[], sla_hours: number, sla_action: string, sla_group: number, sla_transition: string, x: number, y: number, count?: number}
export type WfEffect = {type: string, field?: string, value?: string, template?: string, to?: string, title?: string, message?: string, level?: string, url?: string, job?: string, payload?: Record<string, unknown>}
export type WfTransition = {code: string, label: string, from: string, to: string, groups: number[], system: boolean, fields: string[], comment: boolean, approvals: number, effects: WfEffect[], bend: number}
type Definition = {
	workflow: {id: number, name: string, module: string, plugin: string, table: string, state_column: string, active: boolean}
	states: WfState[]
	transitions: WfTransition[]
	without_state: number
	groups: {id: number, name: string}[]
	columns: string[]
	templates: string[]
	jobs: string[]
	colors: string[]
}

// size of the diagram and of a state
const CW = 1000
const CH = 460
const BW = 160
const BH = 74
const START = {x: 24, y: 200, r: 13}

const message = (e: unknown) => e instanceof ApiException ? e.error.message : t('network error, please retry')
const EFFECTS: Record<string, string> = {set: 'Set a field', email: 'Send an email (mail template)', notify: 'In-app notification', webhook: 'Call a webhook', job: 'Run a job (coded by a developer)'}
const COLOR_NAMES: Record<string, string> = {grey: 'Grey', blue: 'Blue', green: 'Green', orange: 'Orange', red: 'Red', purple: 'Purple', yellow: 'Yellow', cyan: 'Cyan'}
// distance between the arrows that join the same states (curve added to theirs)
const SPREAD = 70
const VALUES: Record<string, string> = {'@now': 'Now (date and time)', '@today': 'Today', '@user': 'Name of the user', '@user_id': 'Id of the user', '@empty': 'Empty'}
const pill = (on: boolean) => ({height: 28, px: 1.25, borderRadius: 14, fontSize: 12.5, fontWeight: 500, border: '1px solid', borderColor: on ? 'primary.main' : 'var(--u-border-strong)', bgcolor: on ? 'primary.main' : 'var(--u-paper)', color: on ? 'primary.contrastText' : 'var(--u-text2)'})
const toggle = <T,>(list: T[], v: T) => list.includes(v) ? list.filter((x) => x !== v) : [...list, v]
const key = (text: string) => text.toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 50)
const r1 = (n: number) => Math.round(n * 10) / 10

// label of a field of a dialog, the control under it
function Row({label, children}: {label: string, children: React.ReactNode})
{

	return (
		<Box component="label" sx={{display: 'flex', flexDirection: 'column', gap: 0.5, fontSize: 12.5, fontWeight: 500, color: 'var(--u-text2)', minWidth: 0}}>
			{label}
			{children}
		</Box>
	)

}

function Section({children}: {children: React.ReactNode})
{

	return <Box sx={{fontSize: 11.5, fontWeight: 600, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--u-text3)', borderTop: '1px solid var(--u-border-soft)', pt: 1.75, mt: 0.5}}>{children}</Box>

}

// chips of groups or columns, toggled
function Picks({options, value, onChange}: {options: {value: string | number, label: string}[], value: (string | number)[], onChange: (v: (string | number)[]) => void})
{

	return (
		<Box sx={{display: 'flex', flexWrap: 'wrap', gap: 0.75}}>
			{options.map((o) => <ButtonBase key={o.value} aria-pressed={value.includes(o.value)} onClick={() => onChange(toggle(value, o.value))} sx={pill(value.includes(o.value))}>{o.label}</ButtonBase>)}
		</Box>
	)

}

/**
 * recipients of an effect: creator, actor, followers, a group, a column (user id or email)
 */
function Recipients({def, value, onChange}: {def: Definition, value: string, onChange: (v: string) => void})
{

	return (
		<Select size="small" value={value} onChange={(e) => onChange(e.target.value)} displayEmpty sx={{fontSize: 14}}>
			<MenuItem value="creator">{t('Creator of the record')}</MenuItem>
			<MenuItem value="actor">{t('User who ran the transition')}</MenuItem>
			<MenuItem value="followers">{t('Followers of the record (chatter)')}</MenuItem>
			{def.groups.map((g) => <MenuItem key={g.id} value={`group:${g.id}`}>{t('Group [NAME]', {NAME: g.name})}</MenuItem>)}
			{def.columns.map((c) => <MenuItem key={c} value={`field:${c}`}>{t('Column [NAME] (user id or email)', {NAME: c})}</MenuItem>)}
		</Select>
	)

}

// editor of an effect by its type
function EffectEditor({def, effect, onChange, onRemove}: {def: Definition, effect: WfEffect, onChange: (e: WfEffect) => void, onRemove: () => void})
{

	const custom = effect.type === 'set' && !Object.keys(VALUES).includes(effect.value ?? '')
	const [payload, setPayload] = useState(JSON.stringify(effect.payload ?? {}))

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 1, p: 1.25, border: '1px solid var(--u-border)', borderRadius: '6px'}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
				<Box sx={{fontSize: 13, fontWeight: 600, flexGrow: 1}}>{t(EFFECTS[effect.type] ?? effect.type)}</Box>
				<IconButton size="small" aria-label={t('Remove the effect')} onClick={onRemove}><DeleteOutlineOutlined sx={{fontSize: 18}} /></IconButton>
			</Box>
			{effect.type === 'set' && (
				<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, gap: 1}}>
					<Row label={t('Column')}>
						<Select size="small" value={effect.field ?? ''} onChange={(e) => onChange({...effect, field: e.target.value})} sx={{fontSize: 14}}>
							{def.columns.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
						</Select>
					</Row>
					<Row label={t('Value')}>
						<Select size="small" value={custom ? 'text' : (effect.value ?? '@now')} onChange={(e) => onChange({...effect, value: e.target.value === 'text' ? '' : e.target.value})} sx={{fontSize: 14}}>
							{Object.entries(VALUES).map(([v, l]) => <MenuItem key={v} value={v}>{t(l)}</MenuItem>)}
							<MenuItem value="text">{t('Fixed text')}</MenuItem>
						</Select>
					</Row>
					{custom && <Box sx={{gridColumn: '1 / -1'}}><Row label={t('Fixed text')}><OutlinedInput size="small" value={effect.value ?? ''} onChange={(e) => onChange({...effect, value: e.target.value})} inputProps={{maxLength: 255}} /></Row></Box>}
				</Box>
			)}
			{effect.type === 'email' && (
				<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, gap: 1}}>
					<Row label={t('Mail template')}>
						<Select size="small" value={effect.template ?? ''} onChange={(e) => onChange({...effect, template: e.target.value})} sx={{fontSize: 14}}>
							{def.templates.map((n) => <MenuItem key={n} value={n}>{n}</MenuItem>)}
						</Select>
					</Row>
					<Row label={t('To')}><Recipients def={def} value={effect.to ?? 'creator'} onChange={(to) => onChange({...effect, to})} /></Row>
				</Box>
			)}
			{effect.type === 'notify' && (
				<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, gap: 1}}>
					<Row label={t('To')}><Recipients def={def} value={effect.to ?? 'creator'} onChange={(to) => onChange({...effect, to})} /></Row>
					<Row label={t('Level')}>
						<Select size="small" value={effect.level ?? 'info'} onChange={(e) => onChange({...effect, level: e.target.value})} sx={{fontSize: 14}}>
							{['info', 'success', 'warning', 'error'].map((l) => <MenuItem key={l} value={l}>{t(l)}</MenuItem>)}
						</Select>
					</Row>
					<Box sx={{gridColumn: '1 / -1'}}><Row label={t('Title')}><OutlinedInput size="small" value={effect.title ?? ''} placeholder={t('Title, ex: [[record]] validated')} onChange={(e) => onChange({...effect, title: e.target.value})} inputProps={{maxLength: 255}} /></Row></Box>
					<Box sx={{gridColumn: '1 / -1'}}><Row label={t('Message')}><OutlinedInput size="small" value={effect.message ?? ''} placeholder={t('Message, ex: [[from]] to [[to]] by [[author]]')} onChange={(e) => onChange({...effect, message: e.target.value})} inputProps={{maxLength: 1000}} /></Row></Box>
				</Box>
			)}
			{effect.type === 'webhook' && (
				<Row label={t('Url (POST json, signed with the global workflow.webhook.secret)')}>
					<OutlinedInput size="small" value={effect.url ?? ''} placeholder="https://" onChange={(e) => onChange({...effect, url: e.target.value.trim()})} inputProps={{maxLength: 500, spellCheck: false}} />
				</Row>
			)}
			{effect.type === 'job' && (
				<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, gap: 1}}>
					<Row label={t('Job')}>
						<Select size="small" value={effect.job ?? ''} onChange={(e) => onChange({...effect, job: e.target.value})} sx={{fontSize: 14}}>
							{def.jobs.map((j) => <MenuItem key={j} value={j}>{j}</MenuItem>)}
						</Select>
					</Row>
					<Row label={t('Payload (json)')}>
						<OutlinedInput size="small" value={payload} onChange={(e) => {

							setPayload(e.target.value)
							try { const p = JSON.parse(e.target.value || '{}'); if(p && typeof p === 'object' && !Array.isArray(p))onChange({...effect, payload: p}) } catch { /* kept until valid */ }

						}} inputProps={{spellCheck: false}} sx={{fontFamily: 'monospace', fontSize: 13}} />
					</Row>
				</Box>
			)}
		</Box>
	)

}

/**
 * editor of a workflow (module Workflows, workflow/?editor=<id>): diagram of the states and the transitions, the same in tables,
 * dialogs of a state (deadline) and of a transition (who, conditions, approvals, effects), saved at once (PUT definition)
 */
export default function WorkflowEditor({id}: {id: number})
{

	const navigate = useNavigate()
	const toast = useToast()
	const [def, setDef] = useState<Definition | null>(null)
	const [states, setStates] = useState<WfState[]>([])
	const [transitions, setTransitions] = useState<WfTransition[]>([])
	const [dirty, setDirty] = useState(false)
	const [errors, setErrors] = useState<string[]>([])
	const [saving, setSaving] = useState(false)
	const [error, setError] = useState('')
	const [dialog, setDialog] = useState<{kind: 'state' | 'transition', index: number} | null>(null)
	const [connect, setConnect] = useState<null | 'from' | {from: string}>(null)
	const [init_state, setInitState] = useState('')
	const drag = useRef<{index: number, x0: number, y0: number, px: number, py: number, moved: boolean} | null>(null)
	const dragged = useRef(false)

	const load = (data: Definition) => {

		// states never placed (x = y = 0): set in a grid
		setStates(data.states.map((s, i) => (s.x || s.y) ? s : {...s, x: 110 + (i % 4) * 220, y: 50 + Math.floor(i / 4) * 140}))
		setTransitions(data.transitions)
		setDef(data)
		setDirty(false)
		setErrors([])

	}

	useEffect(() => {

		api<Definition>(`workflow/${id}/definition/`).then(load).catch((e) => setError(message(e)))

	}, [id])

	// leaving with unsaved changes asks the browser to confirm
	useEffect(() => {

		if(!dirty)return
		const warn = (e: BeforeUnloadEvent) => e.preventDefault()
		window.addEventListener('beforeunload', warn)
		return () => window.removeEventListener('beforeunload', warn)

	}, [dirty])

	// the same inside the backend (back button, menu): a lightbox asks to save or to leave
	const blocker = useBlocker(({currentLocation, nextLocation}) => dirty && (currentLocation.pathname !== nextLocation.pathname || currentLocation.search !== nextLocation.search))

	if(error)return <Box role="alert" sx={{p: 3, color: 'var(--u-error)'}}>{error}</Box>
	if(!def)return <Box sx={{display: 'flex', justifyContent: 'center', py: 8}}><CircularProgress /></Box>

	const change = () => { setDirty(true); setErrors([]) }
	const setState = (index: number, patch: Partial<WfState>) => { setStates((list) => list.map((s, i) => i === index ? {...s, ...patch} : (patch.initial ? {...s, initial: false} : s))); change() }
	const setTransition = (index: number, patch: Partial<WfTransition>) => { setTransitions((list) => list.map((x, i) => i === index ? {...x, ...patch} : x)); change() }
	const stateOf = (code: string) => states.find((s) => s.code === code)
	const labelOf = (code: string) => code === '' ? t('New record') : (stateOf(code)?.label ?? code)
	const groupName = (gid: number) => def.groups.find((g) => g.id === gid)?.name ?? `#${gid}`

	const save = async ():Promise<boolean> => {

		setSaving(true)

		try
		{

			load(await api<Definition>(`workflow/${id}/definition/`, {method: 'PUT', body: {states, transitions}}))
			dataChanged('workflow')
			toast(t('Saved'))
			return true

		}
		catch(e)
		{

			const errs = (e instanceof ApiException) ? ((e.error as {errors?: string[]}).errors ?? [e.error.message]) : [message(e)]
			setErrors(errs)
			return false

		}
		finally
		{

			setSaving(false)

		}

	}

	const saveDialog = () => {

		setDialog(null)
		if(dirty)save()

	}

	const initialize = async () => {

		try
		{

			const r = await api<{initialized: number}>(`workflow/${id}/initialize/`, {method: 'POST', body: {state: init_state}})
			toast(t('[COUNT] records initialized', {COUNT: r.initialized}))
			load(await api<Definition>(`workflow/${id}/definition/`))

		}
		catch(e)
		{

			toast(message(e), 'error')

		}

	}

	const addState = () => {

		let k = 1
		while(states.some((s) => s.code === `state_${k}`))k++
		// on the right of the rightmost state, same height, next row when the diagram is full
		const last = states.reduce<WfState | null>((r, s) => (!r || s.x > r.x) ? s : r, null)
		let x = last ? last.x + BW + 60 : 110
		let y = last ? last.y : 50
		if(x > CW - BW)
		{

			x = 110
			y = Math.max(...states.map((s) => s.y)) + BH + 50

		}
		x = Math.min(x, CW - BW)
		y = Math.max(0, Math.min(y, CH - BH))
		setStates((list) => [...list, {code: `state_${k}`, label: 'New state', color: 'grey', initial: false, final: false, edit_groups: [], sla_hours: 0, sla_action: 'notify', sla_group: 0, sla_transition: '', x, y, count: 0}])
		setDialog({kind: 'state', index: states.length})
		setConnect(null)
		change()

	}

	// connect mode: + Transition, the starting state (or the black dot of the creation), then the target
	const pick = (code: string): boolean => {

		if(connect === 'from'){ setConnect({from: code}); return true }
		if(connect && typeof connect === 'object')
		{

			if(code === '' || code === connect.from)return true
			let k = 1
			while(transitions.some((x) => x.code === `transition_${k}`))k++
			setTransitions((list) => [...list, {code: `transition_${k}`, label: 'New transition', from: connect.from, to: code, groups: [], system: false, fields: [], comment: false, approvals: 1, effects: [], bend: 0}])
			setDialog({kind: 'transition', index: transitions.length})
			setConnect(null)
			change()
			return true

		}
		return false

	}

	// geometry of the diagram
	const center = (code: string) => {

		if(code === '')return {x: START.x + START.r, y: START.y + START.r}
		const s = stateOf(code)
		return s ? {x: s.x + BW / 2, y: s.y + BH / 2} : {x: 0, y: 0}

	}
	const half = (code: string) => code === '' ? {w: START.r + 2, h: START.r + 2} : {w: BW / 2 + 2, h: BH / 2 + 2}
	const clip = (c: {x: number, y: number}, to: {x: number, y: number}, h: {w: number, h: number}) => {

		const dx = to.x - c.x
		const dy = to.y - c.y
		if(!dx && !dy)return c
		const k = Math.min(dx ? h.w / Math.abs(dx) : Infinity, dy ? h.h / Math.abs(dy) : Infinity)
		return {x: c.x + dx * k, y: c.y + dy * k}

	}

	// transitions joining the same two states (any direction): each one gets its own distance from the straight line
	const pairs: Record<string, number[]> = {}
	transitions.forEach((tr, i) => { const k = [tr.from, tr.to].sort().join('\u0000'); (pairs[k] ??= []).push(i) })
	const spread = (tr: WfTransition, i: number) => {

		const list = pairs[[tr.from, tr.to].sort().join('\u0000')] ?? [i]
		if(list.length < 2)return 0
		const off = (list.indexOf(i) - (list.length - 1) / 2) * SPREAD
		// distance given in the direction first -> second state: reversed for a transition going the other way
		return (tr.from <= tr.to) ? off : -off

	}

	const edges = transitions.map((tr, i) => {

		const a = center(tr.from)
		const b = center(tr.to)
		const len = Math.hypot(b.x - a.x, b.y - a.y) || 1
		const n = {x: -(b.y - a.y) / len, y: (b.x - a.x) / len}
		const bend = spread(tr, i) + (tr.bend || 0)
		const ctrl = {x: (a.x + b.x) / 2 + n.x * bend, y: (a.y + b.y) / 2 + n.y * bend}
		const p1 = clip(a, bend ? ctrl : b, half(tr.from))
		const p2 = clip(b, bend ? ctrl : a, half(tr.to))
		const d = bend ? `M${r1(p1.x)} ${r1(p1.y)} Q${r1(ctrl.x)} ${r1(ctrl.y)} ${r1(p2.x)} ${r1(p2.y)}` : `M${r1(p1.x)} ${r1(p1.y)} L${r1(p2.x)} ${r1(p2.y)}`
		const tx = p2.x - (bend ? ctrl.x : p1.x)
		const ty = p2.y - (bend ? ctrl.y : p1.y)
		const tl = Math.hypot(tx, ty) || 1
		const u = {x: tx / tl, y: ty / tl}
		const base = {x: p2.x - u.x * 9, y: p2.y - u.y * 9}
		const head = `M${r1(p2.x)} ${r1(p2.y)} L${r1(base.x - u.y * 5)} ${r1(base.y + u.x * 5)} L${r1(base.x + u.y * 5)} ${r1(base.y - u.x * 5)} Z`
		const label = bend ? {x: 0.25 * p1.x + 0.5 * ctrl.x + 0.25 * p2.x, y: 0.25 * p1.y + 0.5 * ctrl.y + 0.25 * p2.y} : {x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2}
		const on = dialog?.kind === 'transition' && dialog.index === i
		return {tr, i, d, head, label, on, color: on ? 'var(--mui-palette-primary-main)' : (tr.system ? 'var(--u-faint)' : 'var(--u-text3)')}

	})

	const move = (e: React.PointerEvent) => {

		const dr = drag.current
		if(!dr)return
		const dx = e.clientX - dr.x0
		const dy = e.clientY - dr.y0
		if(!dr.moved && Math.abs(dx) + Math.abs(dy) < 4)return
		dr.moved = true
		const x = Math.max(0, Math.min(CW - BW, Math.round(dr.px + dx)))
		const y = Math.max(0, Math.min(CH - BH, Math.round(dr.py + dy)))
		setStates((list) => list.map((s, i) => i === dr.index ? {...s, x, y} : s))

	}
	const up = () => {

		if(drag.current?.moved){ dragged.current = true; change() }
		drag.current = null

	}

	const ds = (dialog?.kind === 'state') ? states[dialog.index] : undefined
	const dt = (dialog?.kind === 'transition') ? transitions[dialog.index] : undefined
	const without = def.without_state

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 2}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap'}}>
				<IconButton aria-label={t('Back')} onClick={() => navigate('/workflow/')} sx={{border: '1px solid var(--u-border)', bgcolor: 'var(--u-paper)', width: 34, height: 34}}><ArrowBackOutlined fontSize="small" /></IconButton>
				<Box sx={{flexGrow: 1, minWidth: 0}}>
					<Typography component="h1" noWrap sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600}}>{def.workflow.name}</Typography>
					<Box sx={{display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap', fontSize: 13, color: 'var(--u-text3)'}}>
						<span>{def.workflow.plugin}</span>
						<Box component="code" sx={{fontSize: 12}}>{def.workflow.table}.{def.workflow.state_column}</Box>
						<Badge value={def.workflow.active ? t('Active') : t('Inactive')} color={def.workflow.active ? 'green' : 'grey'} />
					</Box>
				</Box>
				{dirty && <Box sx={{fontSize: 12.5, fontWeight: 500, px: 1.25, py: 0.5, borderRadius: 3, bgcolor: 'var(--u-orange-bg)', color: 'var(--u-orange)'}}>{t('Unsaved changes')}</Box>}
				<Button variant="contained" startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <SaveOutlined />} disabled={!dirty || saving} onClick={save}>{t('Save')}</Button>
			</Box>

			{errors.length > 0 && (
				<Box role="alert" sx={{px: 2, py: 1.5, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 14}}>
					<Box component="ul" sx={{m: 0, pl: 2.25}}>{errors.map((line) => <li key={line}>{line}</li>)}</Box>
				</Box>
			)}

			{without > 0 && states.length > 0 && (
				<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap', px: 2, py: 1.25, borderRadius: '6px', bgcolor: 'var(--u-orange-bg)', color: 'var(--u-orange)', fontSize: 14}}>
					<Box sx={{flexGrow: 1}}>{t('[COUNT] records have no state yet', {COUNT: without})}</Box>
					<Select size="small" value={init_state} displayEmpty onChange={(e) => setInitState(e.target.value)} inputProps={{'aria-label': t('State')}} sx={{minWidth: 180, bgcolor: 'var(--u-paper)', fontSize: 14}}>
						<MenuItem value="" disabled>{t('Choose a state')}</MenuItem>
						{(def.states).map((s) => <MenuItem key={s.code} value={s.code}>{s.label}</MenuItem>)}
					</Select>
					<Tooltip title={dirty ? t('Save the workflow first') : ''}><span><Button variant="outlined" color="inherit" disabled={!init_state || dirty || !def.workflow.active} onClick={initialize} sx={{bgcolor: 'var(--u-paper)'}}>{t('Initialize')}</Button></span></Tooltip>
				</Box>
			)}

			<Box sx={{bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '8px', p: 1.5, display: 'flex', flexDirection: 'column', gap: 1.25}}>
				<Box sx={{display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap'}}>
					<Button size="small" variant="outlined" startIcon={<AddOutlined />} onClick={addState}>{t('State')}</Button>
					<Button size="small" variant={connect ? 'contained' : 'outlined'} startIcon={<AddOutlined />} onClick={() => { setConnect(connect ? null : 'from'); setDialog(null) }}>{t('Transition')}</Button>
					<Box sx={{flexGrow: 1, fontSize: 13, color: 'var(--u-text3)'}}>
						{connect === 'from' && t('Click the state the transition starts from, or the black dot for a new record.')}
						{connect && typeof connect === 'object' && t('From [STATE]: click the target state.', {STATE: labelOf(connect.from)})}
						{!connect && t('Drag a state to move it, click a state or a transition to edit it.')}
					</Box>
					<Box sx={{display: 'flex', gap: 1.5, alignItems: 'center', fontSize: 12, color: 'var(--u-text3)'}}>
						<Box sx={{display: 'inline-flex', alignItems: 'center', gap: 0.75}}><Box sx={{width: 22, borderTop: '2px solid var(--u-text3)'}} />{t('Groups')}</Box>
						<Box sx={{display: 'inline-flex', alignItems: 'center', gap: 0.75}}><Box sx={{width: 22, borderTop: '2px dashed var(--u-faint)'}} />{t('System')}</Box>
					</Box>
				</Box>
				<Box sx={{overflowX: 'auto', border: '1px solid var(--u-border-soft)', borderRadius: '6px'}}>
					<Box onPointerMove={move} onPointerUp={up} onPointerLeave={up} sx={{position: 'relative', width: CW, height: CH, mx: 'auto', backgroundImage: 'radial-gradient(var(--u-border) 1px, transparent 1px)', backgroundSize: '18px 18px'}}>
						<svg width={CW} height={CH} style={{position: 'absolute', inset: 0, pointerEvents: 'none'}} aria-hidden="true">
							{edges.map((e) => (
								<g key={e.i}>
									<path d={e.d} fill="none" stroke={e.color} strokeWidth={e.on ? 2.5 : 1.6} strokeDasharray={e.tr.system ? '5 4' : undefined} />
									<path d={e.head} fill={e.color} />
								</g>
							))}
						</svg>
						<ButtonBase aria-label={t('New record')} title={t('New record')} onClick={() => pick('')} sx={{position: 'absolute', left: START.x, top: START.y, width: START.r * 2, height: START.r * 2, borderRadius: '50%', bgcolor: 'var(--u-text)', border: '3px solid var(--u-paper)', boxShadow: '0 0 0 1px var(--u-text)'}} />
						{states.map((s, i) => {

							const c = BADGES[s.color] ?? BADGES.grey
							const on = (dialog?.kind === 'state' && dialog.index === i) || (connect && typeof connect === 'object' && connect.from === s.code)
							return (
								<Box key={s.code + i} onPointerDown={(e) => { dragged.current = false; if(connect)return; drag.current = {index: i, x0: e.clientX, y0: e.clientY, px: s.x, py: s.y, moved: false} }}
									sx={{position: 'absolute', left: s.x, top: s.y, width: BW, height: BH, borderRadius: '8px', bgcolor: 'var(--u-paper)', touchAction: 'none', userSelect: 'none', cursor: connect ? 'crosshair' : 'grab',
										border: `${s.final ? 2 : 1}px solid`, borderColor: on ? 'primary.main' : (s.final ? BADGES.green.fg : 'var(--u-border-strong)'), boxShadow: on ? '0 0 0 3px var(--u-accent-bg2)' : '0 1px 3px var(--u-shadow)'}}>
									<ButtonBase aria-label={t('Edit the state [STATE]', {STATE: s.label})} onClick={() => { if(dragged.current){ dragged.current = false; return } if(!pick(s.code))setDialog({kind: 'state', index: i}) }}
										sx={{width: '100%', height: '100%', display: 'flex', flexDirection: 'column', alignItems: 'stretch', justifyContent: 'center', gap: 0.5, px: 1.25, textAlign: 'left', cursor: 'inherit', borderRadius: '8px'}}>
										<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75, minWidth: 0}}>
											<Box sx={{width: 9, height: 9, borderRadius: '50%', flexShrink: 0, bgcolor: (c.fg === '#ffffff') ? c.bg : c.fg}} />
											<Box sx={{fontWeight: 600, fontSize: 13.5, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flexGrow: 1}}>{s.label}</Box>
											<Box sx={{fontSize: 11.5, color: 'var(--u-text3)'}}>{s.count ?? 0}</Box>
										</Box>
										<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75, fontSize: 11, color: 'var(--u-text3)', minWidth: 0}}>
											<Box component="code" sx={{fontSize: 11, overflow: 'hidden', textOverflow: 'ellipsis'}}>{s.code}</Box>
											{s.initial && <Badge value={t('initial')} color={s.color} />}
											{s.final && <Badge value={t('final')} color="green" />}
											{s.sla_hours > 0 && <Box sx={{ml: 'auto'}}><Badge value={`${s.sla_hours} h`} color="orange" /></Box>}
										</Box>
									</ButtonBase>
								</Box>
							)

						})}
						{edges.map((e) => (
							<ButtonBase key={`l${e.i}`} onClick={() => { setDialog({kind: 'transition', index: e.i}); setConnect(null) }}
								sx={{position: 'absolute', left: e.label.x, top: e.label.y, transform: 'translate(-50%, -50%)', gap: 0.5, px: 1, py: '2px', borderRadius: 3, fontSize: 11.5, fontWeight: 500, whiteSpace: 'nowrap', bgcolor: 'var(--u-paper)', border: '1px solid', borderColor: e.on ? 'primary.main' : 'var(--u-border-strong)', color: 'var(--u-text)'}}>
								{e.tr.label}
								{e.tr.system && <Badge value={t('System')} color="grey" />}
								{!e.tr.system && e.tr.approvals > 1 && <Badge value={`${e.tr.approvals} ${t('approvals')}`} color="orange" />}
							</ButtonBase>
						))}
					</Box>
				</Box>
			</Box>

			<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', lg: '1fr 1fr'}, gap: 2, alignItems: 'start'}}>
				<Box sx={{bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '8px', overflow: 'hidden'}}>
					<Box sx={{px: 2, pt: 1.5, pb: 1, fontWeight: 600, fontSize: 14}}>{t('States')}</Box>
					<Box sx={{overflowX: 'auto'}}>
						<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', fontSize: 13, '& th, & td': {px: 2, py: 0.9, borderTop: '1px solid var(--u-border-soft)', textAlign: 'left', whiteSpace: 'nowrap'}, '& th': {fontSize: 11.5, fontWeight: 500, color: 'var(--u-text3)', textTransform: 'uppercase'}, '& tbody tr': {cursor: 'pointer'}, '& tbody tr:hover': {bgcolor: 'var(--u-hover)'}}}>
							<thead><tr><th>{t('State')}</th><th>{t('Key')}</th><th>{t('Editable by')}</th><th>{t('Deadline')}</th><th style={{textAlign: 'right'}}>{t('Records')}</th></tr></thead>
							<tbody>
								{states.map((s, i) => (
									<tr key={s.code + i} onClick={() => setDialog({kind: 'state', index: i})}>
										<td><Badge value={s.label} color={s.color} /> {s.initial ? t('initial') : (s.final ? t('final') : '')}</td>
										<td><code>{s.code}</code></td>
										<td>{s.edit_groups.length ? s.edit_groups.map(groupName).join(', ') : t('Locked')}</td>
										<td>{s.sla_hours > 0 ? `${s.sla_hours} h` : '-'}</td>
										<td style={{textAlign: 'right'}}>{s.count ?? 0}</td>
									</tr>
								))}
							</tbody>
						</Box>
					</Box>
				</Box>
				<Box sx={{bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', borderRadius: '8px', overflow: 'hidden'}}>
					<Box sx={{px: 2, pt: 1.5, pb: 1, fontWeight: 600, fontSize: 14}}>{t('Transitions')}</Box>
					<Box sx={{overflowX: 'auto'}}>
						<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', fontSize: 13, '& th, & td': {px: 2, py: 0.9, borderTop: '1px solid var(--u-border-soft)', textAlign: 'left', whiteSpace: 'nowrap'}, '& th': {fontSize: 11.5, fontWeight: 500, color: 'var(--u-text3)', textTransform: 'uppercase'}, '& tbody tr': {cursor: 'pointer'}, '& tbody tr:hover': {bgcolor: 'var(--u-hover)'}}}>
							<thead><tr><th>{t('From - to')}</th><th>{t('Button')}</th><th>{t('Who')}</th><th>{t('Effects')}</th></tr></thead>
							<tbody>
								{transitions.map((x, i) => (
									<tr key={x.code + i} onClick={() => setDialog({kind: 'transition', index: i})}>
										<td><Badge value={labelOf(x.from)} color={stateOf(x.from)?.color} /> → <Badge value={labelOf(x.to)} color={stateOf(x.to)?.color} /></td>
										<td>{x.label} <code style={{fontSize: 11}}>{x.code}</code></td>
										<td>{x.system ? t('System') : (x.groups.length ? x.groups.map(groupName).join(', ') : t('Edit right'))}</td>
										<td>{x.effects.length || '-'}</td>
									</tr>
								))}
							</tbody>
						</Box>
					</Box>
				</Box>
			</Box>

			{ds && dialog && (
				<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && setDialog(null)} maxWidth="sm" fullWidth>
					<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, pr: 1.5, fontSize: 18, fontWeight: 600}}>
						<Box component="span" sx={{flexGrow: 1}}>{t('State')} <Badge value={ds.label} color={ds.color} /></Box>
						<IconButton aria-label={t('Close')} onClick={() => setDialog(null)}><CloseOutlined /></IconButton>
					</DialogTitle>
					<DialogContent sx={{display: 'flex', flexDirection: 'column', gap: 1.5}}>
						<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, gap: 1.5, pt: 0.5}}>
							<Row label={t('Label (English)')}><OutlinedInput size="small" value={ds.label} onChange={(e) => setState(dialog.index, {label: e.target.value})} inputProps={{maxLength: 100}} /></Row>
							<Row label={t('Key')}>
								<OutlinedInput size="small" value={ds.code} disabled={(ds.count ?? 0) > 0} onChange={(e) => {

									const old = ds.code
									const code = key(e.target.value)
									setState(dialog.index, {code})
									setTransitions((list) => list.map((x) => ({...x, from: x.from === old ? code : x.from, to: x.to === old ? code : x.to})))

								}} inputProps={{spellCheck: false, style: {fontFamily: 'monospace'}}} />
							</Row>
						</Box>
						<Row label={t('Color')}>
							<Box role="radiogroup" sx={{display: 'flex', gap: 1}}>
								{def.colors.map((c) => <ButtonBase key={c} role="radio" aria-checked={ds.color === c} aria-label={t(COLOR_NAMES[c] ?? c)} title={t(COLOR_NAMES[c] ?? c)} onClick={() => setState(dialog.index, {color: c})} sx={{width: 22, height: 22, borderRadius: '50%', bgcolor: (BADGES[c] ?? BADGES.grey).bg, border: '2px solid', borderColor: (BADGES[c] ?? BADGES.grey).fg, boxShadow: ds.color === c ? '0 0 0 2px var(--u-paper), 0 0 0 4px var(--mui-palette-primary-main)' : 'none'}} />)}
							</Box>
						</Row>
						<Box sx={{display: 'flex', gap: 2, flexWrap: 'wrap'}}>
							<FormControlLabel control={<Checkbox size="small" checked={ds.initial} onChange={(e) => setState(dialog.index, {initial: e.target.checked})} />} label={t('State of a new record')} />
							<FormControlLabel control={<Checkbox size="small" checked={ds.final} onChange={(e) => setState(dialog.index, {final: e.target.checked})} />} label={t('Final state')} />
						</Box>
						<Section>{t('Record editable by')}</Section>
						<Picks options={def.groups.map((g) => ({value: g.id, label: g.name}))} value={ds.edit_groups} onChange={(v) => setState(dialog.index, {edit_groups: v as number[]})} />
						<Box sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Nobody selected: the record is locked in this state. The super admin always edits it.')}</Box>
						<Section>{t('Deadline')}</Section>
						<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '120px 1fr 1fr'}, gap: 1.5}}>
							<Row label={t('After (hours)')}><OutlinedInput size="small" type="number" value={ds.sla_hours || ''} placeholder="0" onChange={(e) => setState(dialog.index, {sla_hours: Math.max(0, Math.min(8760, Number(e.target.value) || 0))})} /></Row>
							<Row label={t('Then')}>
								<Select size="small" value={ds.sla_action} disabled={!ds.sla_hours} onChange={(e) => setState(dialog.index, {sla_action: e.target.value})} sx={{fontSize: 14}}>
									<MenuItem value="notify">{t('Notify a group')}</MenuItem>
									<MenuItem value="remind">{t('Remind the creator')}</MenuItem>
									<MenuItem value="transition">{t('Run a system transition')}</MenuItem>
								</Select>
							</Row>
							{ds.sla_action === 'transition' ? (
								<Row label={t('Transition')}>
									<Select size="small" value={ds.sla_transition} displayEmpty disabled={!ds.sla_hours} onChange={(e) => setState(dialog.index, {sla_transition: e.target.value})} sx={{fontSize: 14}}>
										<MenuItem value="" disabled>{t('System transition from this state')}</MenuItem>
										{transitions.filter((x) => x.system && x.from === ds.code).map((x) => <MenuItem key={x.code} value={x.code}>{x.label}</MenuItem>)}
									</Select>
								</Row>
							) : (
								<Row label={ds.sla_action === 'remind' ? t('Group, when the creator is not found') : t('Group')}>
									<Select size="small" value={ds.sla_group} disabled={!ds.sla_hours} onChange={(e) => setState(dialog.index, {sla_group: Number(e.target.value)})} sx={{fontSize: 14}}>
										<MenuItem value={0}>{t('Super admins')}</MenuItem>
										{def.groups.filter((g) => g.id !== 1).map((g) => <MenuItem key={g.id} value={g.id}>{g.name}</MenuItem>)}
									</Select>
								</Row>
							)}
						</Box>
						<Box sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Checked every 15 minutes, once per entry in the state. A change of the deadline applies to the records that enter the state afterwards.')}</Box>
					</DialogContent>
					<DialogActions sx={{px: 3, pb: 2.5, gap: 1}}>
						<Box sx={{flexGrow: 1, fontSize: 12.5, color: 'var(--u-text3)'}}>{(ds.count ?? 0) > 0 ? t('[COUNT] records are in this state: move them before deleting it.', {COUNT: ds.count ?? 0}) : ''}</Box>
						<Button color="error" variant="outlined" disabled={(ds.count ?? 0) > 0 || states.length <= 1} startIcon={<DeleteOutlineOutlined />} onClick={() => {

							const code = ds.code
							setStates((list) => list.filter((_, i) => i !== dialog.index))
							setTransitions((list) => list.filter((x) => x.from !== code && x.to !== code))
							setDialog(null)
							change()

						}}>{t('Delete the state')}</Button>
						<Button variant="contained" startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <SaveOutlined />} disabled={saving} onClick={saveDialog}>{t('Save')}</Button>
					</DialogActions>
				</Dialog>
			)}

			{dt && dialog && (
				<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && setDialog(null)} maxWidth="md" fullWidth>
					<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, pr: 1.5, fontSize: 18, fontWeight: 600}}>
						<Box component="span" sx={{flexGrow: 1, display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap'}}>{t('Transition')} <Badge value={labelOf(dt.from)} color={stateOf(dt.from)?.color} /> → <Badge value={labelOf(dt.to)} color={stateOf(dt.to)?.color} /></Box>
						<IconButton aria-label={t('Close')} onClick={() => setDialog(null)}><CloseOutlined /></IconButton>
					</DialogTitle>
					<DialogContent sx={{display: 'flex', flexDirection: 'column', gap: 1.5}}>
						<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '1fr 1fr'}, gap: 1.5, pt: 0.5}}>
							<Row label={t('Button label (English)')}><OutlinedInput size="small" value={dt.label} onChange={(e) => setTransition(dialog.index, {label: e.target.value})} inputProps={{maxLength: 100}} /></Row>
							<Row label={t('Key')}><OutlinedInput size="small" value={dt.code} onChange={(e) => setTransition(dialog.index, {code: key(e.target.value)})} inputProps={{spellCheck: false, style: {fontFamily: 'monospace'}}} /></Row>
							<Row label={t('From')}>
								<Select size="small" value={dt.from} onChange={(e) => setTransition(dialog.index, {from: e.target.value})} sx={{fontSize: 14}}>
									<MenuItem value="">{t('New record')}</MenuItem>
									{states.map((s) => <MenuItem key={s.code} value={s.code}>{s.label}</MenuItem>)}
								</Select>
							</Row>
							<Row label={t('To')}>
								<Select size="small" value={dt.to} onChange={(e) => setTransition(dialog.index, {to: e.target.value})} sx={{fontSize: 14}}>
									{states.map((s) => <MenuItem key={s.code} value={s.code}>{s.label}</MenuItem>)}
								</Select>
							</Row>
						</Box>
						<Row label={t('Curve of the arrow')}>
							<Box component="input" type="range" min={-300} max={300} step={10} value={dt.bend} onChange={(e: React.ChangeEvent<HTMLInputElement>) => setTransition(dialog.index, {bend: Number(e.target.value)})} sx={{width: 260}} />
						</Row>
						<Section>{t('Who can run it')}</Section>
						<Box sx={{display: 'flex', gap: 0.75}}>
							<ButtonBase aria-pressed={!dt.system} onClick={() => setTransition(dialog.index, {system: false})} sx={pill(!dt.system)}>{t('Groups')}</ButtonBase>
							<ButtonBase aria-pressed={dt.system} disabled={dt.from === ''} onClick={() => setTransition(dialog.index, {system: true})} sx={pill(dt.system)}>{t('System (code, cron, deadline)')}</ButtonBase>
						</Box>
						{!dt.system && <Picks options={def.groups.map((g) => ({value: g.id, label: g.name}))} value={dt.groups} onChange={(v) => setTransition(dialog.index, {groups: v as number[]})} />}
						<Box sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{dt.system ? t('Never shown as a button: fired by the code (Workflow::fire) or a deadline.') : t('No group selected: the users with the edit right (the add right for the creation).')}</Box>
						<Section>{t('Conditions')}</Section>
						<Row label={t('Columns filled before the transition')}>
							<Picks options={def.columns.map((c) => ({value: c, label: c}))} value={dt.fields} onChange={(v) => setTransition(dialog.index, {fields: v as string[]})} />
						</Row>
						<Box sx={{display: 'flex', gap: 2.5, alignItems: 'flex-end', flexWrap: 'wrap'}}>
							<FormControlLabel control={<Checkbox size="small" checked={dt.comment} onChange={(e) => setTransition(dialog.index, {comment: e.target.checked})} />} label={t('Comment required')} />
							<Row label={t('Approvals of different users')}>
								<Select size="small" value={dt.approvals} disabled={dt.system} onChange={(e) => setTransition(dialog.index, {approvals: Number(e.target.value)})} sx={{width: 120, fontSize: 14}}>
									{[1, 2, 3, 4, 5].map((n) => <MenuItem key={n} value={n}>{n}</MenuItem>)}
								</Select>
							</Row>
						</Box>
						<Section>{t('Effects')}</Section>
						{dt.effects.map((e, k) => (
							<EffectEditor key={`${dialog.index}-${k}-${e.type}`} def={def} effect={e} onChange={(next) => setTransition(dialog.index, {effects: dt.effects.map((x, j) => j === k ? next : x)})} onRemove={() => setTransition(dialog.index, {effects: dt.effects.filter((_, j) => j !== k)})} />
						))}
						<Select size="small" value="" displayEmpty onChange={(e) => {

							const type = e.target.value
							const base: Record<string, WfEffect> = {set: {type, field: def.columns[0] ?? '', value: '@now'}, email: {type, template: def.templates[0] ?? '', to: 'creator'}, notify: {type, to: 'creator', title: '[[record]]: [[transition]]', message: '[[from]] → [[to]]', level: 'info'}, webhook: {type, url: ''}, job: {type, job: def.jobs[0] ?? '', payload: {}}}
							if(base[type])setTransition(dialog.index, {effects: [...dt.effects, base[type]]})

						}} inputProps={{'aria-label': t('Add an effect')}} sx={{maxWidth: 320, fontSize: 14}}>
							<MenuItem value="" disabled>{t('+ Add an effect')}</MenuItem>
							{Object.entries(EFFECTS).map(([k, l]) => <MenuItem key={k} value={k}>{t(l)}</MenuItem>)}
						</Select>
						<Box sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Set a field runs with the change of state, the other effects in the background (job queue, retried). Texts: [[column]] of the record, [[record]], [[transition]], [[from]], [[to]], [[comment]], [[author]], [[url]].')}</Box>
						<Box component="code" sx={{flexShrink: 0, fontSize: 12, px: 1.25, py: 1, borderRadius: '6px', bgcolor: 'var(--u-hover)', overflowX: 'auto', whiteSpace: 'nowrap'}}>POST /api/{def.workflow.module}/{'{id}'}/workflow/{dt.code}/{dt.comment ? '  {"comment": "..."}' : ''}</Box>
					</DialogContent>
					<DialogActions sx={{px: 3, pb: 2.5, justifyContent: 'space-between'}}>
						<Button color="error" variant="outlined" startIcon={<DeleteOutlineOutlined />} onClick={() => {

							const code = dt.code
							setTransitions((list) => list.filter((_, i) => i !== dialog.index))
							setStates((list) => list.map((s) => s.sla_transition === code ? {...s, sla_transition: ''} : s))
							setDialog(null)
							change()

						}}>{t('Delete the transition')}</Button>
						<Button variant="contained" startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <SaveOutlined />} disabled={saving} onClick={saveDialog}>{t('Save')}</Button>
					</DialogActions>
				</Dialog>
			)}

			{blocker.state === 'blocked' && (
				<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && blocker.reset()} maxWidth="xs" fullWidth>
					<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, pr: 1.5, fontSize: 17, fontWeight: 600}}>
						<Box component="span" sx={{flexGrow: 1}}>{t('Unsaved changes')}</Box>
						<IconButton aria-label={t('Close')} onClick={() => blocker.reset()}><CloseOutlined /></IconButton>
					</DialogTitle>
					<DialogContent sx={{fontSize: 14, color: 'text.secondary'}}>{t('The changes of the workflow are lost when leaving without saving.')}</DialogContent>
					<DialogActions sx={{px: 3, pb: 2.5, gap: 1}}>
						<Button color="error" onClick={() => blocker.proceed()}>{t('Leave without saving')}</Button>
						<Button variant="contained" disabled={saving} startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <SaveOutlined />} onClick={async () => { if(await save())blocker.proceed(); else blocker.reset() }}>{t('Save')}</Button>
					</DialogActions>
				</Dialog>
			)}
		</Box>
	)

}
