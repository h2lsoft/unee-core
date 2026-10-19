import { createContext, useContext, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router'
import {
	Box, Button, ButtonBase, Checkbox, Chip, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, Divider, FormControlLabel, IconButton, InputBase,
	ListItemIcon, ListItemText, Menu, MenuItem, OutlinedInput, Popover, Select, Switch, Typography, useMediaQuery, useTheme,
} from '@mui/material'
import {
	AddCircleOutlineOutlined, ArrowDropDownOutlined, ArrowDropUpOutlined, ChevronLeftOutlined, ChevronRightOutlined,
	CheckBoxOutlined, CloseOutlined, DragIndicatorOutlined, DeleteOutlineOutlined, EditOutlined, FilterAltOutlined, GroupOutlined, KeyboardArrowDownOutlined, MoreVertOutlined, SaveOutlined,
	SearchOutlined, ViewColumnOutlined,
} from '@mui/icons-material'
import { api, ApiException, dataChanged, type Grid, type GridAction, type GridColumn, type GridFilter, type Row, type RowAction, type SavedFilter } from '../api'
import { getLanguage, t } from '../i18n'
import { MenuIcon } from './icons'
import BookmarkButton from './BookmarkButton'
import DetailDialog from './DetailDialog'
import MassEditDialog from './MassEditDialog'
import { useToast } from './Toast'

const BORDER = 'var(--u-border)'
const ROW_BORDER = 'var(--u-border-soft)'
const HEAD_BG = 'var(--u-head)'

// badge palette (DataGrid::addColBadge colors)
export const BADGES: Record<string, {bg: string, fg: string}> = {
	grey: {bg: 'var(--u-border-soft)', fg: 'var(--u-text2)'},
	orange: {bg: 'var(--u-orange-bg)', fg: 'var(--u-orange)'},
	red: {bg: 'var(--u-red-bg)', fg: 'var(--u-red)'},
	green: {bg: 'var(--u-green-bg)', fg: 'var(--u-green)'},
	blue: {bg: 'var(--u-blue-bg)', fg: 'var(--u-blue)'},
	purple: {bg: 'var(--u-accent-bg)', fg: 'var(--u-accent-text)'},
	yellow: {bg: 'var(--u-yellow-bg)', fg: 'var(--u-yellow)'},
	cyan: {bg: '#1fb3e6', fg: '#ffffff'},
}

const OPERATOR_LABELS: Record<string, string> = {
	'contains': 'contains',
	'eq': 'equals',
	'!eq': 'not equal',
	'begins': 'begins with',
	'ends': 'ends with',
	'empty': 'is empty',
	'!empty': 'is not empty',
	'lte': 'lower or equal',
	'gte': 'greater or equal',
	'in': 'in (comma separated)',
}

type Condition = {f: string, o: string, v: string}

const no_value = (operator: string) => (operator === 'empty' || operator === '!empty')

// search[] entries: column||value (equal), column||operator||value, q||text (search box), short form on the tabs column = active tab
const entry = (f: string, o: string, v: string) => (o === 'eq') ? `${f}||${v}` : `${f}||${o}||${no_value(o) ? '' : v}`
const isShort = (raw: string) => raw.split('||').length === 2
const qOf = (params: URLSearchParams) => params.getAll('search[]').find((raw) => raw.startsWith('q||'))?.slice(3) ?? ''

// readable url: search[] and || are not encoded
function serialize(params: URLSearchParams): string
{

	return [...params.entries()].map(([k, v]) => `${k === 'search[]' ? k : encodeURIComponent(k)}=${encodeURIComponent(v).replace(/%7C/gi, '|')}`).join('&')

}

const tab_sx = (on: boolean) => ({
	height: 32,
	px: 1.25,
	gap: 0.75,
	flexShrink: 0,
	border: '1px solid',
	borderColor: on ? 'var(--u-accent-border)' : 'var(--u-border)',
	borderRadius: 1,
	bgcolor: on ? 'var(--u-accent-bg)' : HEAD_BG,
	color: on ? 'var(--u-accent-text)' : 'var(--u-text2)',
	fontWeight: on ? 500 : 400,
	fontSize: 13,
	'&:hover': {bgcolor: on ? 'var(--u-accent-bg)' : 'var(--u-border)'},
})

const menu_paper = {paper: {sx: {minWidth: 230, mt: 0.5, border: `1px solid ${BORDER}`, boxShadow: '0 12px 32px rgba(16, 20, 40, 0.14)'}}}

// hidden columns, per browser and module (storage may be blocked)
function readHidden(module: string): string[]
{

	try
	{

		const value = JSON.parse(localStorage.getItem(`unee.grid.${module}.hidden`) ?? '[]')
		return Array.isArray(value) ? value : []

	}
	catch
	{

		return []

	}

}

function writeHidden(module: string, hidden: string[])
{

	try
	{

		localStorage.setItem(`unee.grid.${module}.hidden`, JSON.stringify(hidden))

	}
	catch
	{

		// private mode: not remembered

	}

}

export function Badge({value, color}: {value: string, color?: string})
{

	const c = BADGES[color ?? 'grey'] ?? BADGES.grey
	return <Box component="span" sx={{display: 'inline-block', minWidth: 34, px: 1, py: '1px', borderRadius: 1, fontSize: 10.5, fontWeight: 500, textAlign: 'center', bgcolor: c.bg, color: c.fg, whiteSpace: 'nowrap'}}>{value}</Box>

}

function formatDate(value: string, with_time: boolean): string
{

	const date = new Date(value.replace(' ', 'T'))
	if(isNaN(date.getTime()))return value
	return new Intl.DateTimeFormat(getLanguage(), with_time ? {dateStyle: 'short', timeStyle: 'short', hourCycle: 'h23'} : {dateStyle: 'short'}).format(date)

}

// inline css of a list value (property: value; pairs) to a style object
function parseStyle(css: string): Record<string, string>
{

	const style: Record<string, string> = {}
	for(const pair of css.split(';'))
	{

		const [property, ...rest] = pair.split(':')
		const value = rest.join(':').trim()
		if(property?.trim() && value)style[property.trim().toLowerCase().replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = value

	}

	return style

}

const CHIP_COLORS = ['default', 'primary', 'secondary', 'error', 'warning', 'info', 'success'] as const

/**
 * value of a list shown as a mui chip: class = color and variant (ex: success outlined), style = css added, icon = material symbol
 */
export function ListChip({label, className = '', css = '', icon = ''}: {label: string, className?: string, css?: string, icon?: string})
{

	const [color, variant] = className.split(' ')
	const chip_color = (CHIP_COLORS as readonly string[]).includes(color) ? color as typeof CHIP_COLORS[number] : 'default'

	return <Chip size="small" label={label} color={chip_color} variant={variant === 'outlined' ? 'outlined' : 'filled'} icon={icon ? <MenuIcon name={icon} fontSize={14} /> : undefined} sx={{height: 22, fontSize: 11.5, fontWeight: 500, '& .MuiChip-icon': {ml: '6px', mr: '-2px', color: 'inherit'}, ...parseStyle(css)}} />

}

const alignOf = (column: GridColumn) => column.align ?? (column.name === 'id' ? 'right' : (column.type === 'badge' ? 'center' : 'left'))

// cell content by column type
// tags of a row, clickable when the grid has the tags filter
function TagChips({tags}: {tags: string[]})
{

	const {active, toggle} = useContext(TagFilter)
	return (
		<Box component="span" sx={{display: 'inline-flex', flexWrap: 'wrap', gap: 0.5}}>
			{tags.map((tag) => {

				const on = active.includes(tag)
				return <Chip key={tag} label={tag} size="small" title={toggle ? (on ? t('Remove this filter') : t('Filter on this tag')) : undefined}
					onClick={toggle ? (e: React.MouseEvent) => { e.stopPropagation(); toggle(tag) } : undefined}
					sx={{height: 20, fontSize: 11, bgcolor: on ? 'var(--u-accent-bg)' : 'var(--u-border-soft)', color: on ? 'var(--u-accent-text)' : undefined, border: '1px solid', borderColor: on ? 'var(--u-accent-border)' : 'transparent', '&:hover': toggle ? {bgcolor: 'var(--u-accent-bg)'} : {}}} />

			})}
		</Box>
	)

}

// tags filtered in the grid (conditions _tags contains), a click on a tag of a row toggles its filter
const TagFilter = createContext<{active: string[], toggle: ((tag: string) => void) | null}>({active: [], toggle: null})

function Cell({column, row, can_edit, onToggle}: {column: GridColumn, row: Row, can_edit: boolean, onToggle: (row: Row, column: string, value: string) => void})
{

	const value = row[column.name]
	if(column.type === 'boolean')
	{

		if(column.toggle && can_edit)return <Switch size="small" checked={value === 'yes'} onClick={(e) => e.stopPropagation()} onChange={(e) => onToggle(row, column.name, e.target.checked ? 'yes' : 'no')} slotProps={{input: {'aria-label': column.label}}} />
		return <>{value === 'yes' ? t('Yes') : t('No')}</>

	}

	if(value === null || value === undefined || value === '')return <Box component="span" sx={{color: 'var(--u-faint)'}}>-</Box>

	const text = String(value)

	// link to a page of the backend, {id} and {column} replaced by the values of the row
	if(column.link)
	{

		const to = '/' + column.link.replace(/\{(\w+)\}/g, (_, key) => encodeURIComponent(String(row[key] ?? '')))
		return <Box component={RouterLink} to={to} onClick={(e: React.MouseEvent) => e.stopPropagation()} sx={{textDecoration: 'none', color: 'inherit', '& > span': {cursor: 'pointer'}, '&:hover > span': {borderColor: 'primary.main', color: 'primary.main'}}}><Cell column={{...column, link: undefined}} row={row} can_edit={can_edit} onToggle={onToggle} /></Box>

	}

	if(column.type === 'chip')return <ListChip label={text} className={String(row[column.class_column ?? ''] ?? '')} css={String(row[column.style_column ?? ''] ?? '')} icon={String(row[column.icon_column ?? ''] ?? '')} />
	if(column.type === 'badge')return <Badge value={column.labels?.[text] ?? text} color={column.color_column ? String(row[column.color_column] ?? '') : column.colors?.[text]} />
	if(column.type === 'tags')return <TagChips tags={text.split('|')} />
	if(column.type === 'icon')return <MenuIcon name={text} color={column.color_column ? String(row[column.color_column] ?? '') || undefined : undefined} />
	if(column.type === 'color')return <Box component="span" title={text} sx={{display: 'inline-block', width: 52, height: 14, borderRadius: 0.5, bgcolor: text, border: '1px solid rgba(0, 0, 0, 0.08)', verticalAlign: 'middle'}} />
	if(column.type === 'count')return <Box component="span" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5, px: 0.75, py: '1px', borderRadius: 1, bgcolor: HEAD_BG, border: `1px solid ${BORDER}`, fontSize: 12, fontWeight: 500, color: 'var(--u-text2)'}}>{column.icon && <MenuIcon name={column.icon} fontSize={14} />}{text}</Box>
	if(column.type === 'date' || column.type === 'datetime')return <>{formatDate(text, column.type === 'datetime')}</>
	return <>{text}</>

}

function ConfirmDialog({open, title, onCancel, onConfirm, busy, label}: {open: boolean, title: string, onCancel: () => void, onConfirm: () => void, busy: boolean, label?: string})
{

	return (
		<Dialog open={open} onClose={(_, reason) => reason !== 'backdropClick' && onCancel()} maxWidth="xs" fullWidth>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 17, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{title}</Box>
				<IconButton aria-label={t('Close')} onClick={onCancel}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{fontSize: 14, color: 'text.secondary'}}>{t('This action cannot be undone.')}</DialogContent>
			<DialogActions sx={{px: 3, pb: 2.5}}>
				<Button onClick={onConfirm} variant="contained" color="error" disabled={busy}>{busy ? <CircularProgress size={20} color="inherit" /> : (label ?? t('Delete'))}</Button>
			</DialogActions>
		</Dialog>
	)

}

// advanced filters: list of conditions (column, operator, value) matched all or any
function FiltersPanel({anchor, filters, conditions, match, onClose, onApply, onSave}: {anchor: HTMLElement | null, filters: GridFilter[], conditions: Condition[], match: 'all' | 'any', onClose: () => void, onApply: (conditions: Condition[], match: 'all' | 'any') => void, onSave: (conditions: Condition[], match: 'all' | 'any') => void})
{

	const empty = (): Condition => ({f: filters[0]?.name ?? '', o: filters[0]?.operators[0] ?? 'eq', v: ''})
	const [draft, setDraft] = useState<Condition[]>([])
	const [draft_match, setDraftMatch] = useState<'all' | 'any'>('all')

	// draft taken from the active conditions each time the panel opens
	useEffect(() => {

		if(!anchor)return
		setDraft(conditions.length ? conditions : [empty()])
		setDraftMatch(match)

	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [anchor])

	const set = (index: number, patch: Partial<Condition>) => setDraft(draft.map((c, i) => {

		if(i !== index)return c
		const next = {...c, ...patch}

		// another column: its first operator, value cleared
		if(patch.f !== undefined && patch.f !== c.f)
		{

			next.o = filters.find((f) => f.name === patch.f)?.operators[0] ?? 'eq'
			next.v = ''

		}

		return next

	}))

	const submit = (e: FormEvent) => {

		e.preventDefault()
		onApply(draft, draft_match)

	}

	const control = {height: 38, fontSize: 14}

	return (
		<Popover open={!!anchor} anchorEl={anchor} onClose={onClose} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}} slotProps={{paper: {sx: {mt: 0.75, width: 640, maxWidth: 'calc(100vw - 32px)', border: `1px solid ${BORDER}`, borderRadius: '10px', boxShadow: '0 12px 32px rgba(16, 20, 40, 0.14)'}}}}>
			<Box component="form" onSubmit={submit} sx={{p: '16px 16px 14px', display: 'flex', flexDirection: 'column', gap: 1.5}}>
				<Box sx={{display: 'flex', alignItems: 'center', justifyContent: 'space-between'}}>
					<Typography sx={{fontSize: 14, fontWeight: 600}}>{t('Filters')}</Typography>
					<IconButton size="small" aria-label={t('Close')} onClick={onClose}><CloseOutlined fontSize="small" /></IconButton>
				</Box>

				<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 13, color: 'var(--u-text3)'}}>
					{t('Match')}
					<Select size="small" value={draft_match} onChange={(e) => setDraftMatch(e.target.value as 'all' | 'any')} sx={{...control, width: 170}} inputProps={{'aria-label': t('Match')}}>
						<MenuItem value="all">{t('all conditions')}</MenuItem>
						<MenuItem value="any">{t('any condition')}</MenuItem>
					</Select>
				</Box>

				{draft.map((c, i) => {

					const filter = filters.find((f) => f.name === c.f) ?? filters[0]
					if(!filter)return null

					return (
						<Box key={i} sx={{display: 'flex', flexWrap: {xs: 'wrap', sm: 'nowrap'}, alignItems: 'center', gap: 1}}>
							<Select size="small" value={filter.name} onChange={(e) => set(i, {f: e.target.value})} sx={{...control, width: {xs: 'calc(50% - 4px)', sm: 160}}} inputProps={{'aria-label': t('Column')}}>
								{filters.map((f) => <MenuItem key={f.name} value={f.name}>{f.label}</MenuItem>)}
							</Select>
							<Select size="small" value={c.o} onChange={(e) => set(i, {o: e.target.value})} sx={{...control, width: {xs: 'calc(50% - 4px)', sm: 170}}} inputProps={{'aria-label': t('Operator')}}>
								{filter.operators.map((op) => <MenuItem key={op} value={op}>{t(OPERATOR_LABELS[op] ?? op)}</MenuItem>)}
							</Select>
							{(filter.options.length > 0 && c.o !== 'in' && !no_value(c.o))
								? (
									<Select size="small" displayEmpty value={c.v} onChange={(e) => set(i, {v: e.target.value})} sx={{...control, flexGrow: 1, minWidth: 0}} inputProps={{'aria-label': t('Value')}}>
										<MenuItem value="">-</MenuItem>
										{filter.options.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
									</Select>
								)
								: <OutlinedInput size="small" disabled={no_value(c.o)} type={filter.type === 'date' ? 'date' : (filter.type === 'number' && c.o !== 'in' ? 'number' : 'text')} value={no_value(c.o) ? '' : c.v} onChange={(e) => set(i, {v: e.target.value})} sx={{...control, flexGrow: 1, minWidth: 0}} inputProps={{'aria-label': t('Value')}} />}
							<IconButton aria-label={t('Remove condition')} onClick={() => setDraft(draft.length > 1 ? draft.filter((_, j) => j !== i) : [empty()])} sx={{width: 34, height: 34, color: 'var(--u-text3)'}}><DeleteOutlineOutlined sx={{fontSize: 18}} /></IconButton>
						</Box>
					)

				})}

				<Button type="button" startIcon={<AddCircleOutlineOutlined />} onClick={() => setDraft([...draft, empty()])} sx={{alignSelf: 'flex-start', height: 30, px: 0.75, fontWeight: 500}}>{t('Add condition')}</Button>

				<Box sx={{display: 'flex', alignItems: 'center', gap: 1, pt: 1.5, borderTop: `1px solid ${ROW_BORDER}`}}>
					<Button type="button" color="inherit" startIcon={<SaveOutlined />} disabled={!draft.some((c) => no_value(c.o) || c.v !== '')} onClick={() => onSave(draft.filter((c) => no_value(c.o) || c.v !== ''), draft_match)} sx={{fontWeight: 500}}>{t('Save filter')}</Button>
					<Box sx={{flexGrow: 1}} />
					{/* reset clears the form only, Search applies it */}
					<Button type="button" color="inherit" onClick={() => { setDraft([empty()]); setDraftMatch('all') }} sx={{fontWeight: 500}}>{t('Reset')}</Button>
					<Button type="submit" variant="contained">{t('Search')}</Button>
				</Box>
			</Box>
		</Popover>
	)

}

function RowMenu({row, can_edit, can_delete, actions, onEdit, onDelete, onAction}: {row: Row, can_edit: boolean, can_delete: boolean, actions: RowAction[], onEdit: (row: Row) => void, onDelete: (row: Row) => void, onAction: (row: Row, action: RowAction) => void})
{

	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	if(!can_edit && !can_delete && !actions.length)return null

	return (
		<>
			<IconButton size="small" aria-label={t('More actions')} onClick={(e) => { e.stopPropagation(); setAnchor(e.currentTarget) }} sx={{width: 34, height: 34, color: 'var(--u-text3)'}}><MoreVertOutlined sx={{fontSize: 18}} /></IconButton>
			<Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} onClick={(e) => e.stopPropagation()} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}>
				{can_edit && <MenuItem onClick={() => { setAnchor(null); onEdit(row) }}><ListItemIcon><EditOutlined fontSize="small" /></ListItemIcon><ListItemText>{t('Edit')}</ListItemText></MenuItem>}
				{actions.map((a) => <MenuItem key={a.name} onClick={() => { setAnchor(null); onAction(row, a) }}><ListItemIcon><MenuIcon name={a.icon} fontSize={20} /></ListItemIcon><ListItemText>{a.label}</ListItemText></MenuItem>)}
				{can_delete && <MenuItem onClick={() => { setAnchor(null); onDelete(row) }} sx={{color: 'error.main'}}><ListItemIcon><DeleteOutlineOutlined fontSize="small" color="error" /></ListItemIcon><ListItemText>{t('Delete')}</ListItemText></MenuItem>}
			</Menu>
		</>
	)

}

// name of a saved filter, personal or shared with the group of the user
function SaveFilterDialog({module, draft, onClose, onSaved}: {module: string, draft: {conditions: Condition[], match: 'all' | 'any'} | null, onClose: () => void, onSaved: () => void})
{

	const toast = useToast()
	const [name, setName] = useState('')
	const [shared, setShared] = useState(false)
	const [error, setError] = useState('')
	const [saving, setSaving] = useState(false)

	useEffect(() => {

		if(!draft)return
		setName('')
		setShared(false)
		setError('')

	}, [draft])

	const submit = async (e: FormEvent) => {

		e.preventDefault()
		if(!draft)return
		setSaving(true)
		setError('')

		try
		{

			await api('backend/filters/', {method: 'POST', body: {module, name, shared, ...draft}})
			toast(t('Filter saved'))
			onSaved()

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			setError(api_error?.fields?.name?.join(', ') ?? api_error?.message ?? t('network error, please retry'))

		}
		finally
		{

			setSaving(false)

		}

	}

	return (
		<Dialog open={!!draft} onClose={(_, reason) => reason !== 'backdropClick' && onClose()} maxWidth="xs" fullWidth slotProps={{paper: {component: 'form', onSubmit: submit, noValidate: true} as object}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 17, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{t('Save filter')}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>
			<DialogContent sx={{display: 'flex', flexDirection: 'column', gap: 1.5}}>
				{error && <Box role="alert" sx={{px: 2, py: 1.25, borderRadius: '6px', bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 14}}>{error}</Box>}
				<OutlinedInput autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder={t('Filter name')} error={!!error} inputProps={{maxLength: 255, 'aria-label': t('Filter name')}} />
				<FormControlLabel control={<Checkbox size="small" checked={shared} onChange={(e) => setShared(e.target.checked)} />} label={t('Share')} slotProps={{typography: {sx: {fontSize: 14}}}} />
			</DialogContent>
			<DialogActions sx={{px: 3, pb: 2.5}}>
				<Button type="submit" variant="contained" disabled={saving || name.trim() === ''}>{saving ? <CircularProgress size={20} color="inherit" /> : t('Save')}</Button>
			</DialogActions>
		</Dialog>
	)

}

// page number typed by the user, applied on enter or blur, kept between 1 and the last page
function PageInput({page, last, onChange}: {page: number, last: number, onChange: (page: number) => void})
{

	const [value, setValue] = useState(String(page))
	useEffect(() => setValue(String(page)), [page])

	const commit = () => {

		const n = parseInt(value, 10)
		if(isNaN(n))
		{

			setValue(String(page))
			return

		}

		const clamped = Math.min(Math.max(n, 1), last)
		setValue(String(clamped))
		if(clamped !== page)onChange(clamped)

	}

	return (
		<InputBase
			type="number"
			value={value}
			onChange={(e) => setValue(e.target.value)}
			onFocus={(e) => e.target.select()}
			onBlur={commit}
			onKeyDown={(e) => e.key === 'Enter' && commit()}
			inputProps={{min: 1, max: last, 'aria-label': t('Page number')}}
			sx={{
				width: 52,
				height: 30,
				border: '1px solid var(--u-border-strong)',
				borderRadius: 1,
				bgcolor: 'var(--u-paper)',
				fontSize: 13,
				'& input': {textAlign: 'center', p: 0, MozAppearance: 'textfield'},
				'& input::-webkit-inner-spin-button, & input::-webkit-outer-spin-button': {WebkitAppearance: 'none', m: 0},
			}}
		/>
	)

}

/**
 * grid of a module (GET /api/<module>/), state kept in the url (search, chip, conditions, sort, page)
 */
export default function DataGrid({module, icon, version, onAdd, onEdit, onTitle}: {module: string, icon: string, version: number, onAdd: () => void, onEdit: (row: Row) => void, onTitle?: (title: string, label: string) => void})
{

	const theme = useTheme()
	const is_mobile = useMediaQuery(theme.breakpoints.down('md'))
	const toast = useToast()
	const [params] = useSearchParams()
	const navigate = useNavigate()
	const [grid, setGrid] = useState<Grid | null>(null)
	const [error, setError] = useState('')
	const [loading, setLoading] = useState(true)
	const [search, setSearch] = useState(qOf(params))
	const [selected, setSelected] = useState<number[]>([])
	const [to_delete, setToDelete] = useState<Row[] | null>(null)
	const [deleting, setDeleting] = useState(false)
	const [reload, setReload] = useState(0)
	const [hidden, setHidden] = useState<string[]>(() => readHidden(module))
	const [filters_anchor, setFiltersAnchor] = useState<HTMLElement | null>(null)
	const [grid_menu, setGridMenu] = useState<HTMLElement | null>(null)
	const [columns_menu, setColumnsMenu] = useState<HTMLElement | null>(null)
	const [selection_menu, setSelectionMenu] = useState<HTMLElement | null>(null)
	const [saved, setSaved] = useState<SavedFilter[]>([])
	const [save_draft, setSaveDraft] = useState<{conditions: Condition[], match: 'all' | 'any'} | null>(null)
	const [saved_version, setSavedVersion] = useState(0)
	const [mass_edit, setMassEdit] = useState<number[] | null>(null)
	const [running, setRunning] = useState('')
	const [drag_rows, setDragRows] = useState<Row[] | null>(null)
	const [drag_id, setDragId] = useState<number | null>(null)
	const [confirm_action, setConfirmAction] = useState<GridAction | null>(null)
	const [detail_id, setDetailId] = useState<number | null>(null)

	const query = params.toString()

	useEffect(() => {

		let cancelled = false
		setLoading(true)

		api<Grid>(`${module}/${query ? `?${query}` : ''}`)
			.then((data) => {

				if(cancelled)return
				setGrid(data)
				setDragRows(null)
				setError('')
				setSelected([])
				onTitle?.(data.title, data.label)

			})
			.catch((e) => !cancelled && setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))
			.finally(() => !cancelled && setLoading(false))

		return () => { cancelled = true }

	// onTitle is a callback of the parent, not a dependency of the data
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [module, query, version, reload])

	// saved filters of the grid (own and shared with the group)
	useEffect(() => {

		api<{filters: SavedFilter[]}>(`backend/filters/?module=${encodeURIComponent(module)}`).then((data) => setSaved(data.filters)).catch(() => setSaved([]))

	}, [module, saved_version])

	// search typed: applied after a short pause
	useEffect(() => {

		if(search === qOf(params))return
		const timer = setTimeout(() => setSearchEntries((list) => [...(search.trim() ? [`q||${search.trim()}`] : []), ...list.filter((raw) => !raw.startsWith('q||'))]), 350)
		return () => clearTimeout(timer)

	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [search])

	function setUrl(next: URLSearchParams)
	{

		const search = serialize(next)
		navigate({search: search ? `?${search}` : ''}, {replace: true})

	}

	// set or remove query parameters, empty value = removed
	function update(values: Record<string, string>)
	{

		const next = new URLSearchParams(params)
		for(const [key, value] of Object.entries(values))
		{

			if(value === '')next.delete(key)
			else next.set(key, value)

		}
		setUrl(next)

	}

	// replace the search[] entries, back to the first page
	function setSearchEntries(change: (list: string[]) => string[], extra: Record<string, string> = {})
	{

		const next = new URLSearchParams(params)
		const list = change(next.getAll('search[]'))
		next.delete('search[]')
		list.forEach((raw) => next.append('search[]', raw))
		next.delete('page')
		for(const [key, value] of Object.entries(extra))
		{

			if(value === '')next.delete(key)
			else next.set(key, value)

		}
		setUrl(next)

	}

	// active tab: short form on the tabs column
	const setTab = (value: string) => {

		const name = grid?.chips?.name ?? ''
		setSearchEntries((list) => {

			const rest = list.filter((raw) => !(isShort(raw) && raw.startsWith(`${name}||`)))
			return value ? [...rest, `${name}||${value}`] : rest

		})

	}

	const rights = useMemo(() => new Set(grid?.rights ?? []), [grid])
	const can_add = rights.has('add')
	const can_edit = rights.has('edit')
	const can_delete = rights.has('delete')
	const batch_actions = (grid?.batch?.actions ?? []).filter((a) => rights.has(a.right))
	const can_mass_edit = can_edit && (grid?.batch?.edit.length ?? 0) > 0
	const batch = !!grid?.batch && (can_delete || can_mass_edit || batch_actions.length > 0)
	const row_actions = (grid?.row_actions ?? []).filter((a) => rights.has(a.right))
	const grid_actions = (grid?.grid_actions ?? []).filter((a) => rights.has(a.right))

	const applyConditions = (conditions: Condition[], match: 'all' | 'any') => {

		const tabs = grid?.chips?.name ?? ''

		// search box kept, the first equal on the tabs column becomes the active tab (always combined with AND), the others stay explicit
		let tab_done = false
		setSearchEntries((list) => [
			...list.filter((raw) => raw.startsWith('q||')),
			...conditions.filter((c) => no_value(c.o) || c.v !== '').map((c) => {

				if(c.o !== 'eq' || c.f !== tabs)return entry(c.f, c.o, c.v)
				if(tab_done)return `${c.f}||eq||${c.v}`
				tab_done = true
				return `${c.f}||${c.v}`

			}),
		], {match: match === 'any' ? 'any' : ''})
		setFiltersAnchor(null)

	}

	// action of the module on the selection, the answer may hold a message
	const runAction = async (name: string) => {

		setRunning(name)

		try
		{

			const data = await api<{message?: string}>(`${module}/batch/${name}/`, {method: 'POST', body: {ids: selected}})
			toast(data?.message ?? t('Done'))
			setSelectionMenu(null)
			dataChanged(module)
			setReload((n) => n + 1)

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}
		finally
		{

			setRunning('')

		}

	}

	// action of a row: link of the backend, or call whose answer may hold a message or a redirect (full reload, ex: connect as)
	const rowAction = async (row: Row, action: RowAction) => {

		if(action.url)
		{

			navigate(`/${action.url.replaceAll('{id}', String(row.id))}`)
			return

		}

		try
		{

			const data = await api<{message?: string, redirect?: string}>(`${module}/${row.id}/action/${action.name}/`, {method: 'POST', body: {}})
			if(data?.redirect)
			{

				window.location.href = data.redirect
				return

			}

			toast(data?.message ?? t('Done'))
			dataChanged(module)
			setReload((n) => n + 1)

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}

	}

	// button of the grid header, confirmed first when it asks a question
	const gridAction = async (action: GridAction) => {

		setRunning(action.name)

		try
		{

			const data = await api<{message?: string}>(`${module}/action/${action.name}/`, {method: 'POST', body: {}})
			toast(data?.message ?? t('Done'))
			setConfirmAction(null)
			dataChanged(module)
			setReload((n) => n + 1)

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}
		finally
		{

			setRunning('')

		}

	}

	const deleteSaved = async (filter: SavedFilter) => {

		try
		{

			await api(`backend/filters/${filter.id}/`, {method: 'DELETE'})
			setSavedVersion((v) => v + 1)
			toast(t('Deleted'))

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}

	}

	const sortBy = (column: GridColumn) => {

		if(!column.sortable || !grid)return
		const dir = (grid.sort.column === column.name && grid.sort.dir === 'asc') ? 'desc' : 'asc'
		update({sort: column.name, dir, page: ''})

	}

	const toggleColumn = (name: string) => {

		const next = hidden.includes(name) ? hidden.filter((h) => h !== name) : [...hidden, name]
		setHidden(next)
		writeHidden(module, next)

	}

	const toggle = async (row: Row, column: string, value: string) => {

		try
		{

			await api(`${module}/${row.id}/toggle/`, {method: 'PATCH', body: {column, value}})
			setGrid((g) => g && {...g, rows: g.rows.map((r) => r.id === row.id ? {...r, [column]: value} : r)})
			dataChanged(module)
			toast(t('Saved'))

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}

	}

	const confirmDelete = async () => {

		if(!to_delete)return
		setDeleting(true)

		try
		{

			if(to_delete.length === 1)
			{

				await api(`${module}/${to_delete[0].id}/`, {method: 'DELETE'})
				toast(t('Deleted'))

			}
			else
			{

				const data = await api<{deleted: number}>(`${module}/batch/delete/`, {method: 'POST', body: {ids: to_delete.map((r) => r.id)}})
				toast(t('[COUNT] deleted', {COUNT: data.deleted}))

			}

			setToDelete(null)
			dataChanged(module)
			setReload((n) => n + 1)

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')

		}
		finally
		{

			setDeleting(false)

		}

	}

	// a tag clicked in a row: condition _tags||<tag> (the row has the tag) added or removed (grids with addFilterTags)
	const active_tags = params.getAll('search[]').filter((raw) => raw.startsWith('_tags||') && isShort(raw)).map((raw) => raw.slice(7))
	const tag_filter = {active: active_tags, toggle: grid?.filters.some((f) => f.name === '_tags') ? (tag: string) => setSearchEntries((list) => active_tags.includes(tag) ? list.filter((raw) => raw !== `_tags||${tag}`) : [...list, `_tags||${tag}`]) : null}

	if(!grid)
	{

		return (
			<Box sx={{display: 'flex', justifyContent: 'center', py: 8}}>
				{error ? <Typography color="error">{error}</Typography> : <CircularProgress />}
			</Box>
		)

	}

	const {pagination: p} = grid
	const columns = grid.columns.filter((c) => c.type !== 'position' && !hidden.includes(c.name))
	const can_drag = !!grid.position && can_edit && !is_mobile && grid.sort.column === grid.position && grid.sort.dir === 'asc'
	const rows = drag_rows ?? grid.rows

	// drag and drop: the rows move live, the new order is saved at the drop
	const endDrag = async () => {

		const ordered = drag_rows
		setDragId(null)
		if(!ordered || ordered.map((r) => r.id).join() === grid.rows.map((r) => r.id).join())
		{

			setDragRows(null)
			return

		}

		try
		{

			await api(`${module}/batch/position/`, {method: 'POST', body: {ids: ordered.map((r) => r.id)}})
			dataChanged(module)
			setReload((n) => n + 1)

		}
		catch(e)
		{

			toast(e instanceof ApiException ? e.error.message : t('network error, please retry'), 'error')
			setDragRows(null)

		}

	}

	const dragOver = (e: React.DragEvent, target: Row) => {

		if(drag_id === null)return
		e.preventDefault()
		if(target.id === drag_id)return

		setDragRows((list) => {

			const current = list ?? grid.rows
			const from = current.findIndex((r) => r.id === drag_id)
			const to = current.findIndex((r) => r.id === target.id)
			if(from < 0 || to < 0)return current
			const next = [...current]
			next.splice(to, 0, next.splice(from, 1)[0])
			return next

		})

	}
	const from = (p.total === 0) ? 0 : (p.page - 1) * p.per_page + 1
	const to = Math.min(p.page * p.per_page, p.total)
	const all_selected = grid.rows.length > 0 && grid.rows.every((r) => selected.includes(r.id))
	const chip = grid.chips?.active ?? ''
	const mobile_title = grid.columns.find((c) => c.name === grid.mobile.title) ?? grid.columns.find((c) => c.name !== 'id') ?? grid.columns[0]
	const mobile_subtitle = grid.columns.find((c) => c.name === grid.mobile.subtitle)
	const mobile_badge = grid.columns.find((c) => c.type === 'badge')
	const selectRow = (id: number) => setSelected(selected.includes(id) ? selected.filter((s) => s !== id) : [...selected, id])
	const filters_on = grid.conditions.length > 0

	// search with the grid menu and the advanced filters
	const search_group = (
		<Box sx={{height: 40, display: 'flex', alignItems: 'stretch', border: '1px solid var(--u-border-strong)', borderRadius: 2, bgcolor: 'var(--u-paper)', width: {xs: '100%', md: 'auto'}, order: {xs: 3, md: 0}}}>
			<Box component="label" sx={{width: {xs: 'auto', md: 300}, flexGrow: {xs: 1, md: 0}, display: 'flex', alignItems: 'center', gap: 1, px: 1.5, color: 'var(--u-muted)'}}>
				<SearchOutlined sx={{fontSize: 18}} />
				<InputBase type="search" placeholder={grid.search?.placeholder ?? t('Search')} disabled={!grid.search} value={search} onChange={(e) => setSearch(e.target.value)} inputProps={{'aria-label': t('Search')}} sx={{flexGrow: 1, fontSize: 14}} />
			</Box>
			<ButtonBase aria-label={t('Grid menu')} onClick={(e) => setGridMenu(e.currentTarget)} sx={{width: 40, borderLeft: '1px solid var(--u-border)', color: 'var(--u-text3)'}}><MoreVertOutlined sx={{fontSize: 18}} /></ButtonBase>
			{grid.filters.length > 0 && (
				<ButtonBase aria-label={t('Advanced filters')} aria-pressed={filters_on} onClick={(e) => setFiltersAnchor(e.currentTarget.parentElement)} sx={{width: 40, borderLeft: '1px solid var(--u-border)', borderRadius: '0 8px 8px 0', bgcolor: (filters_on || filters_anchor) ? 'var(--u-accent-bg)' : 'transparent', color: (filters_on || filters_anchor) ? 'var(--u-accent-text)' : 'var(--u-text3)'}}>
					{filters_on ? <FilterAltOutlined sx={{fontSize: 18}} /> : <KeyboardArrowDownOutlined sx={{fontSize: 20}} />}
				</ButtonBase>
			)}
		</Box>
	)

	const sort_icon = (c: GridColumn) => {

		if(grid.sort.column !== c.name)return <ArrowDropDownOutlined sx={{fontSize: 18, color: 'var(--u-border-strong)'}} />
		return (grid.sort.dir === 'asc') ? <ArrowDropUpOutlined sx={{fontSize: 18, color: 'primary.main'}} /> : <ArrowDropDownOutlined sx={{fontSize: 18, color: 'var(--u-text)'}} />

	}

	// checkbox of the page and the menu of the batch actions
	const selection_control = (
		<Box sx={{height: 30, display: 'inline-flex', alignItems: 'center', border: '1px solid var(--u-border)', borderRadius: 1.5, bgcolor: 'var(--u-paper)'}}>
			<Checkbox size="small" checked={all_selected} indeterminate={!all_selected && selected.length > 0} onChange={() => setSelected(all_selected ? [] : grid.rows.map((r) => r.id))} slotProps={{input: {'aria-label': t('Select page')}}} sx={{p: '0 2px 0 6px'}} />
			<ButtonBase aria-label={t('Selection menu')} onClick={(e) => setSelectionMenu(e.currentTarget)} sx={{height: '100%', pr: 0.25, color: 'var(--u-text3)'}}><ArrowDropDownOutlined sx={{fontSize: 18}} /></ButtonBase>
		</Box>
	)

	let body: ReactNode
	if(grid.rows.length === 0)
	{

		body = <Box sx={{py: 6, textAlign: 'center', color: 'var(--u-muted)', fontSize: 14}}>{t('No results')}</Box>

	}
	else if(is_mobile)
	{

		body = grid.rows.map((row) => (
			<Box key={row.id} onClick={() => grid.detail ? setDetailId(row.id) : (can_edit && onEdit(row))} sx={{display: 'flex', alignItems: 'center', gap: 1.5, p: '12px 8px 12px 16px', borderBottom: `1px solid ${ROW_BORDER}`, cursor: (can_edit || grid.detail) ? 'pointer' : 'default', bgcolor: selected.includes(row.id) ? 'var(--u-accent-hover)' : 'transparent'}}>
				{batch && <Checkbox size="small" checked={selected.includes(row.id)} onClick={(e) => e.stopPropagation()} onChange={() => selectRow(row.id)} slotProps={{input: {'aria-label': t('Select row')}}} sx={{ml: -1}} />}
				<Box sx={{flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 0.5}}>
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
						<Typography component="span" noWrap sx={{fontSize: 14, fontWeight: 500}}>{String(row[mobile_title.name] ?? '')}</Typography>
						{mobile_badge && mobile_badge !== mobile_title && row[mobile_badge.name] ? <Badge value={String(row[mobile_badge.name])} color={mobile_badge.colors?.[String(row[mobile_badge.name])]} /> : null}
					</Box>
					{mobile_subtitle && <Typography component="span" noWrap sx={{fontSize: 13, color: 'var(--u-text3)'}}><Cell column={mobile_subtitle} row={row} can_edit={can_edit} onToggle={toggle} /></Typography>}
				</Box>
				<RowMenu row={row} can_edit={can_edit} can_delete={can_delete} actions={row_actions} onEdit={onEdit} onDelete={(r) => setToDelete([r])} onAction={rowAction} />
			</Box>
		))

	}
	else
	{

		const th = {height: 38, p: '0 12px', borderBottom: `1px solid ${BORDER}`, fontSize: 11, fontWeight: 600, color: 'var(--u-text3)', textTransform: 'uppercase' as const, letterSpacing: '0.04em', whiteSpace: 'nowrap' as const}
		const td = {height: 40, p: '0 12px', borderBottom: `1px solid ${ROW_BORDER}`}

		body = (
			<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', fontSize: 13.5, minWidth: 760}}>
				<thead>
					<Box component="tr" sx={{bgcolor: HEAD_BG}}>
						{grid.position && <Box component="th" sx={{...th, width: 36, p: 0}}><Box component="span" sx={{position: 'absolute', left: -9999}}>{t('Order')}</Box></Box>}
						{batch && (
							<Box component="th" sx={{...th, width: 56, p: '0 0 0 10px', textAlign: 'left'}}>
								{selection_control}
							</Box>
						)}
						{columns.map((c, i) => (
							<Box component="th" key={c.name} sx={{...th, width: c.width, textAlign: alignOf(c), pl: (i === 0 && !batch) ? 2 : 1.5}} aria-sort={grid.sort.column === c.name ? (grid.sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
								{c.sortable
									? <ButtonBase onClick={() => sortBy(c)} sx={{font: 'inherit', color: grid.sort.column === c.name ? 'var(--u-text)' : 'inherit', textTransform: 'inherit', letterSpacing: 'inherit', gap: 0.25}}>{sort_icon(c)}{c.label}</ButtonBase>
									: c.label}
							</Box>
						))}
						<Box component="th" sx={{...th, width: 84, pr: 1.5, textAlign: 'right'}}>
							<Box component="span" sx={{position: 'absolute', left: -9999}}>{t('Actions')}</Box>
							<IconButton aria-label={t('Columns')} title={t('Columns')} onClick={(e) => setColumnsMenu(e.currentTarget)} sx={{width: 30, height: 30, color: columns_menu || hidden.length ? 'primary.main' : 'var(--u-text3)'}}><ViewColumnOutlined sx={{fontSize: 18}} /></IconButton>
						</Box>
					</Box>
				</thead>
				<tbody>
					{rows.map((row) => (
						<Box component="tr" key={row.id} onClick={() => grid.detail && setDetailId(row.id)} onDoubleClick={() => can_edit && onEdit(row)} onDragOver={(e) => dragOver(e, row)} onDrop={(e) => e.preventDefault()} sx={{cursor: grid.detail ? 'pointer' : 'default', opacity: drag_id === row.id ? 0.45 : 1, bgcolor: selected.includes(row.id) ? 'var(--u-accent-hover)' : 'transparent', '&:hover': {bgcolor: selected.includes(row.id) ? 'var(--u-accent-bg)' : 'var(--u-head)'}}}>
							{grid.position && (
								<Box component="td" sx={{...td, p: '0 0 0 10px', width: 36}}>
									{can_drag && (
										<Box
											draggable
											aria-label={t('Drag to reorder')}
											title={t('Drag to reorder')}
											onDragStart={(e: React.DragEvent<HTMLElement>) => {

												e.dataTransfer.setData('text/plain', String(row.id))
												e.dataTransfer.effectAllowed = 'move'
												const tr = e.currentTarget.closest('tr')
												if(tr)e.dataTransfer.setDragImage(tr, 16, 20)
												setDragId(row.id)

											}}
											onDragEnd={endDrag}
											sx={{display: 'flex', cursor: 'grab', color: 'var(--u-faint)', '&:hover': {color: 'var(--u-text2)'}}}
										>
											<DragIndicatorOutlined sx={{fontSize: 18}} />
										</Box>
									)}
								</Box>
							)}
							{batch && <Box component="td" sx={{...td, p: '0 0 0 16px'}}><Checkbox size="small" checked={selected.includes(row.id)} onChange={() => selectRow(row.id)} slotProps={{input: {'aria-label': t('Select row')}}} sx={{p: 0}} /></Box>}
							{columns.map((c, i) => (
								<Box component="td" key={c.name} sx={{...td, pl: (i === 0 && !batch) ? 2 : 1.5, textAlign: alignOf(c), color: c.name === 'id' ? 'var(--u-text3)' : 'var(--u-text)', fontWeight: c.name === grid.mobile.title ? 500 : 400, maxWidth: c.width ? undefined : 420, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>
									<Cell column={c} row={row} can_edit={can_edit} onToggle={toggle} />
								</Box>
							))}
							<Box component="td" sx={{...td, pr: 1.5}}>
								<Box sx={{display: 'flex', justifyContent: 'flex-end', gap: 0.25}}>
									{can_edit && <IconButton size="small" aria-label={t('Edit')} onClick={() => onEdit(row)} sx={{width: 34, height: 34, color: 'primary.main'}}><EditOutlined sx={{fontSize: 18}} /></IconButton>}
									<RowMenu row={row} can_edit={false} can_delete={can_delete} actions={row_actions} onEdit={onEdit} onDelete={(r) => setToDelete([r])} onAction={rowAction} />
								</Box>
							</Box>
						</Box>
					))}
				</tbody>
			</Box>
		)

	}

	const pg_btn = {width: 30, height: 30, borderRadius: 1.5, color: 'var(--u-text2)', '&:hover': {bgcolor: 'var(--u-border)', color: 'primary.main'}, '&.Mui-disabled': {color: 'var(--u-border-strong)'}}

	const footer = (
		<Box sx={{display: {xs: 'flex', md: 'grid'}, flexDirection: 'column', gridTemplateColumns: '1fr auto 1fr', alignItems: 'center', gap: {xs: 1.25, md: 2}, px: 2, py: 1.25, bgcolor: HEAD_BG, borderTop: `1px solid ${BORDER}`, fontSize: 13, color: 'var(--u-text3)'}}>
			<Box>{t('[FROM]-[TO] of [TOTAL]', {FROM: from, TO: to, TOTAL: p.total})}</Box>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25}}>
				<IconButton aria-label={t('Previous page')} disabled={p.page <= 1} onClick={() => update({page: p.page === 2 ? '' : String(p.page - 1)})} sx={pg_btn}><ChevronLeftOutlined sx={{fontSize: 20}} /></IconButton>
				<Box component="label" sx={{display: 'flex', alignItems: 'center', gap: 0.75, color: 'var(--u-text2)'}}>
					{t('Page')} <PageInput page={p.page} last={p.last_page} onChange={(n) => update({page: n === 1 ? '' : String(n)})} /> / {p.last_page}
				</Box>
				<IconButton aria-label={t('Next page')} disabled={p.page >= p.last_page} onClick={() => update({page: String(p.page + 1)})} sx={pg_btn}><ChevronRightOutlined sx={{fontSize: 20}} /></IconButton>
			</Box>
			<Box component="label" sx={{justifySelf: 'end', display: 'flex', alignItems: 'center', gap: 1}}>
				{t('Rows per page')}
				<Select size="small" value={p.per_page} onChange={(e) => update({per_page: String(e.target.value), page: ''})} sx={{height: 30, fontSize: 13, bgcolor: 'var(--u-paper)'}} inputProps={{'aria-label': t('Rows per page')}}>
					{grid.per_page_options.map((n) => <MenuItem key={n} value={n} sx={{fontSize: 13}}>{n}</MenuItem>)}
				</Select>
			</Box>
		</Box>
	)

	return (
		<TagFilter.Provider value={tag_filter}>
			<Box sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1.5}}>
				<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, flexGrow: 1, minWidth: 0}}>
					<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: 'var(--u-accent-bg)', color: 'primary.main'}}><MenuIcon name={icon} /></Box>
					{/* the title goes back to the index of the module: search, tabs, filters, sort and page cleared */}
					<Typography component="h1" noWrap sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>
						<ButtonBase onClick={() => { setSearch(''); navigate({search: ''}) }} sx={{font: 'inherit', letterSpacing: 'inherit', color: 'inherit', '&:hover': {color: 'primary.main'}}}>{grid.title}</ButtonBase>
					</Typography>
					<BookmarkButton module={module} />
				</Box>
				{search_group}
				{grid_actions.map((a) => (
					<Button key={a.name} variant="outlined" color={a.danger ? 'error' : 'primary'} disabled={running !== ''} startIcon={running === a.name ? <CircularProgress size={16} color="inherit" /> : <MenuIcon name={a.icon} fontSize={18} />} onClick={() => a.confirm ? setConfirmAction(a) : gridAction(a)} sx={{bgcolor: 'var(--u-paper)'}}>{a.label}</Button>
				))}
				{can_add && <Button variant="contained" startIcon={<AddCircleOutlineOutlined />} onClick={onAdd}>{is_mobile ? t('Add') : `${t('Add')} ${grid.label}`}</Button>}
			</Box>

			<Box sx={{display: 'flex', flexWrap: {xs: 'nowrap', md: 'wrap'}, alignItems: 'flex-end', gap: 0.75, overflowX: {xs: 'auto', md: 'visible'}, mt: -0.5, mb: {xs: -1, md: -1.25}}}>
				{grid.chips && (
					<>
						{grid.chips.all && <ButtonBase sx={tab_sx(chip === '')} onClick={() => setTab('')}>{t('All')}<TabCount value={grid.chips.total} on={chip === ''} /></ButtonBase>}
						{grid.chips.options.map((o) => <ButtonBase key={o.value} sx={tab_sx(chip === o.value)} onClick={() => setTab(o.value)}>{o.label}<TabCount value={o.count} on={chip === o.value} /></ButtonBase>)}
					</>
				)}
				<Box component="span" sx={{ml: 'auto', flexShrink: 0, pl: 1, fontSize: 13, color: 'var(--u-text3)', display: 'flex', alignItems: 'center', gap: 1}}>
					{loading && <CircularProgress size={12} />}
				</Box>
			</Box>

			<Box sx={{bgcolor: 'var(--u-paper)', border: `1px solid ${BORDER}`, borderRadius: '6px', overflow: 'hidden'}}>
				{/* mobile: no table header, the selection control is on top of the cards */}
				{is_mobile && batch && grid.rows.length > 0 && (
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5, px: 1.5, py: 1, bgcolor: HEAD_BG, borderBottom: `1px solid ${BORDER}`, fontSize: 13, color: 'var(--u-text3)'}}>
						{selection_control}
						{selected.length > 0 && t('[COUNT] selected', {COUNT: selected.length})}
					</Box>
				)}
				<Box sx={{overflowX: 'auto', opacity: loading ? 0.6 : 1, transition: 'opacity .15s'}}>{body}</Box>
				{p.total > 0 && footer}
			</Box>

			<Menu anchorEl={grid_menu} open={!!grid_menu} onClose={() => setGridMenu(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}} slotProps={menu_paper}>
				<Typography component="li" sx={{listStyle: 'none', px: 2, pt: 0.75, pb: 0.5, fontSize: 11, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--u-muted)'}}>{t('Saved filters')}</Typography>
				{saved.length === 0 && <MenuItem disabled sx={{fontSize: 14}}>{t('No saved filter')}</MenuItem>}
				{saved.map((f) => (
					<MenuItem key={f.id} onClick={() => { setGridMenu(null); applyConditions(f.conditions, f.match) }} sx={{pr: 0.5}}>
						<ListItemIcon>{f.shared ? <GroupOutlined fontSize="small" titleAccess={t('Shared with my group')} /> : <FilterAltOutlined fontSize="small" />}</ListItemIcon>
						<ListItemText>{f.name}</ListItemText>
						{f.mine && <IconButton size="small" aria-label={`${t('Delete')} ${f.name}`} onClick={(e) => { e.stopPropagation(); deleteSaved(f) }} sx={{ml: 1, color: 'var(--u-text3)'}}><DeleteOutlineOutlined sx={{fontSize: 16}} /></IconButton>}
					</MenuItem>
				))}
			</Menu>

			<Menu anchorEl={columns_menu} open={!!columns_menu} onClose={() => setColumnsMenu(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}} slotProps={menu_paper}>
				{grid.columns.filter((c) => c.type !== 'position').map((c) => (
					<MenuItem key={c.name} disabled={!hidden.includes(c.name) && columns.length === 1} onClick={() => toggleColumn(c.name)} sx={{py: 0.25}}>
						<Checkbox size="small" checked={!hidden.includes(c.name)} tabIndex={-1} sx={{p: 0.5, mr: 1}} />
						<ListItemText>{c.label}</ListItemText>
					</MenuItem>
				))}
			</Menu>

			<Menu anchorEl={selection_menu} open={!!selection_menu} onClose={() => setSelectionMenu(null)} slotProps={menu_paper}>
				{can_mass_edit && (
					<MenuItem disabled={!selected.length} onClick={() => { setSelectionMenu(null); setMassEdit([...selected]) }}>
						<ListItemIcon><EditOutlined fontSize="small" /></ListItemIcon>
						<ListItemText>{t('Mass edit')} ({selected.length})</ListItemText>
					</MenuItem>
				)}
				{can_delete && (
					<MenuItem disabled={!selected.length} onClick={() => { setSelectionMenu(null); setToDelete(grid.rows.filter((r) => selected.includes(r.id))) }} sx={{color: 'error.main'}}>
						<ListItemIcon><DeleteOutlineOutlined fontSize="small" color="error" /></ListItemIcon>
						<ListItemText>{t('Delete')} ({selected.length})</ListItemText>
					</MenuItem>
				)}
				{batch_actions.map((a) => (
					<MenuItem key={a.name} disabled={!selected.length || running !== ''} onClick={() => runAction(a.name)}>
						<ListItemIcon>{running === a.name ? <CircularProgress size={18} /> : <MenuIcon name={a.icon} fontSize={20} />}</ListItemIcon>
						<ListItemText>{a.label} ({selected.length})</ListItemText>
					</MenuItem>
				))}
				<Divider />
				<MenuItem onClick={() => setSelected(all_selected ? [] : grid.rows.map((r) => r.id))}>
					<ListItemIcon><CheckBoxOutlined fontSize="small" /></ListItemIcon>
					<ListItemText>{t('Check / uncheck all')}</ListItemText>
				</MenuItem>
			</Menu>

			<DetailDialog module={module} id={detail_id} onClose={() => setDetailId(null)} />

			<MassEditDialog module={module} title={grid.title} ids={mass_edit} onClose={() => setMassEdit(null)} onSaved={() => { setMassEdit(null); dataChanged(module); setReload((n) => n + 1) }} />

			<FiltersPanel anchor={filters_anchor} filters={grid.filters} conditions={(grid.chips?.active && grid.filters.some((f) => f.name === grid.chips?.name)) ? [{f: grid.chips.name, o: 'eq', v: grid.chips.active}, ...grid.conditions] : grid.conditions} match={grid.match} onClose={() => setFiltersAnchor(null)} onApply={applyConditions} onSave={(conditions, match) => setSaveDraft({conditions, match})} />

			<SaveFilterDialog module={module} draft={save_draft} onClose={() => setSaveDraft(null)} onSaved={() => { const draft = save_draft; setSaveDraft(null); setSavedVersion((v) => v + 1); if(draft)applyConditions(draft.conditions, draft.match) }} />

			<ConfirmDialog open={!!confirm_action} busy={running !== ''} title={confirm_action?.confirm ?? ''} label={confirm_action?.label} onCancel={() => setConfirmAction(null)} onConfirm={() => confirm_action && gridAction(confirm_action)} />

			<ConfirmDialog
				open={!!to_delete}
				busy={deleting}
				title={(to_delete?.length ?? 0) > 1 ? t('Delete [COUNT] records?', {COUNT: to_delete?.length ?? 0}) : t('Delete this [LABEL]?', {LABEL: grid.label})}
				onCancel={() => setToDelete(null)}
				onConfirm={confirmDelete}
			/>
		</TagFilter.Provider>
	)

}

// rows of a tab, with the search and the conditions applied
function TabCount({value, on}: {value: number, on: boolean})
{

	return <Box component="span" sx={{minWidth: 16, px: 0.5, borderRadius: 0.75, fontSize: 10.5, fontWeight: 600, lineHeight: '16px', textAlign: 'center', bgcolor: on ? 'var(--u-accent-bg2)' : 'var(--u-border)', color: on ? 'var(--u-accent-text)' : 'var(--u-text3)'}}>{value}</Box>

}
