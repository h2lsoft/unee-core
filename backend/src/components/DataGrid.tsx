import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { useSearchParams } from 'react-router'
import {
	Box, Button, ButtonBase, Checkbox, CircularProgress, Dialog, DialogActions, DialogTitle, Divider, IconButton, InputBase,
	ListItemIcon, ListItemText, Menu, MenuItem, OutlinedInput, Popover, Select, Switch, Typography, useMediaQuery, useTheme,
} from '@mui/material'
import {
	AddCircleOutlineOutlined, ArrowDropDownOutlined, ArrowDropUpOutlined, BookmarkBorderOutlined, ChevronLeftOutlined, ChevronRightOutlined,
	CloseOutlined, DeleteOutlineOutlined, EditOutlined, FilterAltOutlined, KeyboardArrowDownOutlined, MoreVertOutlined, SaveOutlined,
	SearchOutlined, ViewColumnOutlined,
} from '@mui/icons-material'
import { api, ApiException, type Grid, type GridColumn, type GridFilter, type Row } from '../api'
import { getLanguage, t } from '../i18n'
import { MenuIcon } from './icons'
import { useToast } from './Toast'

const BORDER = '#e6e8ee'
const ROW_BORDER = '#eef0f4'
const HEAD_BG = '#f5f6f8'

// badge palette (DataGrid::addColBadge colors)
const BADGES: Record<string, {bg: string, fg: string}> = {
	grey: {bg: '#eef0f5', fg: '#3a3f55'},
	orange: {bg: '#fbf1e6', fg: '#9a5a1e'},
	red: {bg: '#fde4e8', fg: '#b0164e'},
	green: {bg: '#e3f4ef', fg: '#0f6a55'},
	blue: {bg: '#e6f0fd', fg: '#1d4f91'},
	purple: {bg: '#efe9ff', fg: '#4b2fd6'},
	yellow: {bg: '#fff6d6', fg: '#7a5b00'},
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

const tab_sx = (on: boolean) => ({
	height: 32,
	px: 1.25,
	gap: 0.75,
	flexShrink: 0,
	border: '1px solid',
	borderColor: on ? '#c9bdff' : '#e3e5ec',
	borderRadius: 1,
	bgcolor: on ? '#f0ecff' : HEAD_BG,
	color: on ? '#4b2fd6' : '#3a3f55',
	fontWeight: on ? 500 : 400,
	fontSize: 13,
	'&:hover': {bgcolor: on ? '#f0ecff' : '#eceef3'},
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
	return new Intl.DateTimeFormat(getLanguage(), with_time ? {dateStyle: 'short', timeStyle: 'short'} : {dateStyle: 'short'}).format(date)

}

const alignOf = (column: GridColumn) => column.align ?? (column.name === 'id' ? 'right' : (column.type === 'badge' ? 'center' : 'left'))

// cell content by column type
function Cell({column, row, can_edit, onToggle}: {column: GridColumn, row: Row, can_edit: boolean, onToggle: (row: Row, column: string, value: string) => void})
{

	const value = row[column.name]
	if(column.type === 'boolean')
	{

		if(column.toggle && can_edit)return <Switch size="small" checked={value === 'yes'} onClick={(e) => e.stopPropagation()} onChange={(e) => onToggle(row, column.name, e.target.checked ? 'yes' : 'no')} slotProps={{input: {'aria-label': column.label}}} />
		return <>{value === 'yes' ? t('Yes') : t('No')}</>

	}

	if(value === null || value === undefined || value === '')return <Box component="span" sx={{color: '#9a9fb3'}}>-</Box>

	const text = String(value)
	if(column.type === 'badge')return <Badge value={text} color={column.colors?.[text]} />
	if(column.type === 'date' || column.type === 'datetime')return <>{formatDate(text, column.type === 'datetime')}</>
	return <>{text}</>

}

function ConfirmDialog({open, title, onCancel, onConfirm, busy}: {open: boolean, title: string, onCancel: () => void, onConfirm: () => void, busy: boolean})
{

	return (
		<Dialog open={open} onClose={onCancel} maxWidth="xs" fullWidth>
			<DialogTitle sx={{fontSize: 17, fontWeight: 600}}>{title}</DialogTitle>
			<DialogActions sx={{px: 3, pb: 2.5}}>
				<Button onClick={onCancel} color="inherit">{t('Cancel')}</Button>
				<Button onClick={onConfirm} variant="contained" color="error" disabled={busy}>{busy ? <CircularProgress size={20} color="inherit" /> : t('Delete')}</Button>
			</DialogActions>
		</Dialog>
	)

}

// advanced filters: list of conditions (column, operator, value) matched all or any
function FiltersPanel({anchor, filters, conditions, match, onClose, onApply}: {anchor: HTMLElement | null, filters: GridFilter[], conditions: Condition[], match: 'all' | 'any', onClose: () => void, onApply: (conditions: Condition[], match: 'all' | 'any') => void})
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

				<Box sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 13, color: '#5b6178'}}>
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
							<IconButton aria-label={t('Remove condition')} onClick={() => setDraft(draft.length > 1 ? draft.filter((_, j) => j !== i) : [empty()])} sx={{width: 34, height: 34, color: '#5b6178'}}><DeleteOutlineOutlined sx={{fontSize: 18}} /></IconButton>
						</Box>
					)

				})}

				<Button type="button" startIcon={<AddCircleOutlineOutlined />} onClick={() => setDraft([...draft, empty()])} sx={{alignSelf: 'flex-start', height: 30, px: 0.75, fontWeight: 500}}>{t('Add condition')}</Button>

				<Box sx={{display: 'flex', alignItems: 'center', gap: 1, pt: 1.5, borderTop: `1px solid ${ROW_BORDER}`}}>
					{/* saved filters: Filters module (personal or shared with the group) */}
					<Button type="button" color="inherit" startIcon={<SaveOutlined />} disabled sx={{fontWeight: 500}}>{t('Save filter')}</Button>
					<Box sx={{flexGrow: 1}} />
					<Button type="button" color="inherit" onClick={() => onApply([], 'all')} sx={{fontWeight: 500}}>{t('Reset')}</Button>
					<Button type="submit" variant="contained">{t('Search')}</Button>
				</Box>
			</Box>
		</Popover>
	)

}

function RowMenu({row, can_edit, can_delete, onEdit, onDelete}: {row: Row, can_edit: boolean, can_delete: boolean, onEdit: (row: Row) => void, onDelete: (row: Row) => void})
{

	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	if(!can_edit && !can_delete)return null

	return (
		<>
			<IconButton size="small" aria-label={t('More actions')} onClick={(e) => { e.stopPropagation(); setAnchor(e.currentTarget) }} sx={{width: 34, height: 34, color: '#5b6178'}}><MoreVertOutlined sx={{fontSize: 18}} /></IconButton>
			<Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} onClick={(e) => e.stopPropagation()} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}>
				{can_edit && <MenuItem onClick={() => { setAnchor(null); onEdit(row) }}><ListItemIcon><EditOutlined fontSize="small" /></ListItemIcon><ListItemText>{t('Edit')}</ListItemText></MenuItem>}
				{can_delete && <MenuItem onClick={() => { setAnchor(null); onDelete(row) }} sx={{color: 'error.main'}}><ListItemIcon><DeleteOutlineOutlined fontSize="small" color="error" /></ListItemIcon><ListItemText>{t('Delete')}</ListItemText></MenuItem>}
			</Menu>
		</>
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
			onBlur={commit}
			onKeyDown={(e) => e.key === 'Enter' && commit()}
			inputProps={{min: 1, max: last, 'aria-label': t('Page number')}}
			sx={{
				width: 52,
				height: 30,
				border: '1px solid #d5d8e3',
				borderRadius: 1,
				bgcolor: '#fff',
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
	const [params, setParams] = useSearchParams()
	const [grid, setGrid] = useState<Grid | null>(null)
	const [error, setError] = useState('')
	const [loading, setLoading] = useState(true)
	const [search, setSearch] = useState(params.get('q') ?? '')
	const [selected, setSelected] = useState<number[]>([])
	const [to_delete, setToDelete] = useState<Row[] | null>(null)
	const [deleting, setDeleting] = useState(false)
	const [reload, setReload] = useState(0)
	const [hidden, setHidden] = useState<string[]>(() => readHidden(module))
	const [filters_anchor, setFiltersAnchor] = useState<HTMLElement | null>(null)
	const [grid_menu, setGridMenu] = useState<HTMLElement | null>(null)
	const [columns_menu, setColumnsMenu] = useState<HTMLElement | null>(null)
	const [selection_menu, setSelectionMenu] = useState<HTMLElement | null>(null)

	const query = params.toString()

	useEffect(() => {

		let cancelled = false
		setLoading(true)

		api<Grid>(`${module}/${query ? `?${query}` : ''}`)
			.then((data) => {

				if(cancelled)return
				setGrid(data)
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

	// search typed: applied after a short pause
	useEffect(() => {

		if(search === (params.get('q') ?? ''))return
		const timer = setTimeout(() => update({q: search, page: ''}), 350)
		return () => clearTimeout(timer)

	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [search])

	// set or remove query parameters, empty value = removed
	function update(values: Record<string, string>)
	{

		const next = new URLSearchParams(params)
		for(const [key, value] of Object.entries(values))
		{

			if(value === '')next.delete(key)
			else next.set(key, value)

		}
		setParams(next, {replace: true})

	}

	const rights = useMemo(() => new Set(grid?.rights ?? []), [grid])
	const can_add = rights.has('add')
	const can_edit = rights.has('edit')
	const can_delete = rights.has('delete')
	const batch = !!grid?.batch && can_delete

	const applyConditions = (conditions: Condition[], match: 'all' | 'any') => {

		const next = new URLSearchParams(params)
		for(const key of [...next.keys()])if(key.startsWith('c['))next.delete(key)
		next.delete('m')
		conditions.filter((c) => no_value(c.o) || c.v !== '').forEach((c, i) => {

			next.set(`c[${i}][f]`, c.f)
			next.set(`c[${i}][o]`, c.o)
			if(!no_value(c.o))next.set(`c[${i}][v]`, c.v)

		})
		if(match === 'any')next.set('m', 'any')
		next.delete('page')
		setParams(next, {replace: true})
		setFiltersAnchor(null)

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

	if(!grid)
	{

		return (
			<Box sx={{display: 'flex', justifyContent: 'center', py: 8}}>
				{error ? <Typography color="error">{error}</Typography> : <CircularProgress />}
			</Box>
		)

	}

	const {pagination: p} = grid
	const columns = grid.columns.filter((c) => !hidden.includes(c.name))
	const from = (p.total === 0) ? 0 : (p.page - 1) * p.per_page + 1
	const to = Math.min(p.page * p.per_page, p.total)
	const all_selected = grid.rows.length > 0 && grid.rows.every((r) => selected.includes(r.id))
	const chip = params.get('chip') ?? ''
	const mobile_title = grid.columns.find((c) => c.name === grid.mobile.title) ?? grid.columns.find((c) => c.name !== 'id') ?? grid.columns[0]
	const mobile_subtitle = grid.columns.find((c) => c.name === grid.mobile.subtitle)
	const mobile_badge = grid.columns.find((c) => c.type === 'badge')
	const selectRow = (id: number) => setSelected(selected.includes(id) ? selected.filter((s) => s !== id) : [...selected, id])
	const filters_on = grid.conditions.length > 0

	// search with the grid menu and the advanced filters
	const search_group = (
		<Box sx={{height: 40, display: 'flex', alignItems: 'stretch', border: '1px solid #d5d8e3', borderRadius: 2, bgcolor: '#fff', width: {xs: '100%', md: 'auto'}, order: {xs: 3, md: 0}}}>
			<Box component="label" sx={{width: {xs: 'auto', md: 300}, flexGrow: {xs: 1, md: 0}, display: 'flex', alignItems: 'center', gap: 1, px: 1.5, color: '#6b7088'}}>
				<SearchOutlined sx={{fontSize: 18}} />
				<InputBase type="search" placeholder={grid.search?.placeholder ?? t('Search')} disabled={!grid.search} value={search} onChange={(e) => setSearch(e.target.value)} inputProps={{'aria-label': t('Search')}} sx={{flexGrow: 1, fontSize: 14}} />
			</Box>
			<ButtonBase aria-label={t('Grid menu')} onClick={(e) => setGridMenu(e.currentTarget)} sx={{width: 40, borderLeft: '1px solid #e3e5ec', color: '#5b6178'}}><MoreVertOutlined sx={{fontSize: 18}} /></ButtonBase>
			{grid.filters.length > 0 && (
				<ButtonBase aria-label={t('Advanced filters')} aria-pressed={filters_on} onClick={(e) => setFiltersAnchor(e.currentTarget.parentElement)} sx={{width: 40, borderLeft: '1px solid #e3e5ec', borderRadius: '0 8px 8px 0', bgcolor: (filters_on || filters_anchor) ? '#f0ecff' : 'transparent', color: (filters_on || filters_anchor) ? '#4b2fd6' : '#5b6178'}}>
					{filters_on ? <FilterAltOutlined sx={{fontSize: 18}} /> : <KeyboardArrowDownOutlined sx={{fontSize: 20}} />}
				</ButtonBase>
			)}
		</Box>
	)

	const sort_icon = (c: GridColumn) => {

		if(grid.sort.column !== c.name)return <ArrowDropDownOutlined sx={{fontSize: 18, color: '#c4c7d4'}} />
		return (grid.sort.dir === 'asc') ? <ArrowDropUpOutlined sx={{fontSize: 18, color: 'primary.main'}} /> : <ArrowDropDownOutlined sx={{fontSize: 18, color: '#1f2335'}} />

	}

	let body: ReactNode
	if(grid.rows.length === 0)
	{

		body = <Box sx={{py: 6, textAlign: 'center', color: '#6b7088', fontSize: 14}}>{t('No results')}</Box>

	}
	else if(is_mobile)
	{

		body = grid.rows.map((row) => (
			<Box key={row.id} onClick={() => can_edit && onEdit(row)} sx={{display: 'flex', alignItems: 'center', gap: 1.5, p: '12px 8px 12px 16px', borderBottom: `1px solid ${ROW_BORDER}`, cursor: can_edit ? 'pointer' : 'default', bgcolor: selected.includes(row.id) ? '#f7f5ff' : 'transparent'}}>
				{batch && <Checkbox size="small" checked={selected.includes(row.id)} onClick={(e) => e.stopPropagation()} onChange={() => selectRow(row.id)} slotProps={{input: {'aria-label': t('Select row')}}} sx={{ml: -1}} />}
				<Box sx={{flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 0.5}}>
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
						<Typography component="span" noWrap sx={{fontSize: 14, fontWeight: 500}}>{String(row[mobile_title.name] ?? '')}</Typography>
						{mobile_badge && mobile_badge !== mobile_title && row[mobile_badge.name] ? <Badge value={String(row[mobile_badge.name])} color={mobile_badge.colors?.[String(row[mobile_badge.name])]} /> : null}
					</Box>
					{mobile_subtitle && <Typography component="span" noWrap sx={{fontSize: 13, color: '#5b6178'}}><Cell column={mobile_subtitle} row={row} can_edit={can_edit} onToggle={toggle} /></Typography>}
				</Box>
				<RowMenu row={row} can_edit={can_edit} can_delete={can_delete} onEdit={onEdit} onDelete={(r) => setToDelete([r])} />
			</Box>
		))

	}
	else
	{

		const th = {height: 38, p: '0 12px', borderBottom: `1px solid ${BORDER}`, fontSize: 11, fontWeight: 600, color: '#5b6178', textTransform: 'uppercase' as const, letterSpacing: '0.04em', whiteSpace: 'nowrap' as const}
		const td = {height: 40, p: '0 12px', borderBottom: `1px solid ${ROW_BORDER}`}

		body = (
			<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', fontSize: 13.5, minWidth: 760}}>
				<thead>
					<Box component="tr" sx={{bgcolor: HEAD_BG}}>
						{batch && (
							<Box component="th" sx={{...th, width: 56, p: '0 0 0 10px', textAlign: 'left'}}>
								<Box sx={{height: 30, display: 'inline-flex', alignItems: 'center', border: '1px solid #e3e5ec', borderRadius: 1.5, bgcolor: '#fff'}}>
									<Checkbox size="small" checked={all_selected} indeterminate={!all_selected && selected.length > 0} onChange={() => setSelected(all_selected ? [] : grid.rows.map((r) => r.id))} slotProps={{input: {'aria-label': t('Select page')}}} sx={{p: '0 2px 0 6px'}} />
									<ButtonBase aria-label={t('Selection menu')} onClick={(e) => setSelectionMenu(e.currentTarget)} sx={{height: '100%', pr: 0.25, color: '#5b6178'}}><ArrowDropDownOutlined sx={{fontSize: 18}} /></ButtonBase>
								</Box>
							</Box>
						)}
						{columns.map((c, i) => (
							<Box component="th" key={c.name} sx={{...th, width: c.width, textAlign: alignOf(c), pl: (i === 0 && !batch) ? 2 : 1.5}} aria-sort={grid.sort.column === c.name ? (grid.sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
								{c.sortable
									? <ButtonBase onClick={() => sortBy(c)} sx={{font: 'inherit', color: grid.sort.column === c.name ? '#1f2335' : 'inherit', textTransform: 'inherit', letterSpacing: 'inherit', gap: 0.25}}>{sort_icon(c)}{c.label}</ButtonBase>
									: c.label}
							</Box>
						))}
						<Box component="th" sx={{...th, width: 84, pr: 1.5, textAlign: 'right'}}>
							<Box component="span" sx={{position: 'absolute', left: -9999}}>{t('Actions')}</Box>
							<IconButton aria-label={t('Columns')} title={t('Columns')} onClick={(e) => setColumnsMenu(e.currentTarget)} sx={{width: 30, height: 30, color: columns_menu || hidden.length ? 'primary.main' : '#5b6178'}}><ViewColumnOutlined sx={{fontSize: 18}} /></IconButton>
						</Box>
					</Box>
				</thead>
				<tbody>
					{grid.rows.map((row) => (
						<Box component="tr" key={row.id} onDoubleClick={() => can_edit && onEdit(row)} sx={{bgcolor: selected.includes(row.id) ? '#f7f5ff' : 'transparent', '&:hover': {bgcolor: selected.includes(row.id) ? '#f2eeff' : '#f8f9fb'}}}>
							{batch && <Box component="td" sx={{...td, p: '0 0 0 16px'}}><Checkbox size="small" checked={selected.includes(row.id)} onChange={() => selectRow(row.id)} slotProps={{input: {'aria-label': t('Select row')}}} sx={{p: 0}} /></Box>}
							{columns.map((c, i) => (
								<Box component="td" key={c.name} sx={{...td, pl: (i === 0 && !batch) ? 2 : 1.5, textAlign: alignOf(c), color: c.name === 'id' ? '#5b6178' : '#1f2335', fontWeight: c.name === grid.mobile.title ? 500 : 400, maxWidth: c.width ? undefined : 420, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>
									<Cell column={c} row={row} can_edit={can_edit} onToggle={toggle} />
								</Box>
							))}
							<Box component="td" sx={{...td, pr: 1.5}}>
								<Box sx={{display: 'flex', justifyContent: 'flex-end', gap: 0.25}}>
									{can_edit && <IconButton size="small" aria-label={t('Edit')} onClick={() => onEdit(row)} sx={{width: 34, height: 34, color: 'primary.main'}}><EditOutlined sx={{fontSize: 18}} /></IconButton>}
									<RowMenu row={row} can_edit={false} can_delete={can_delete} onEdit={onEdit} onDelete={(r) => setToDelete([r])} />
								</Box>
							</Box>
						</Box>
					))}
				</tbody>
			</Box>
		)

	}

	const pg_btn = {width: 30, height: 30, border: '1.5px solid #5b6178', borderRadius: '50%', color: '#3a3f55', '&:hover': {borderColor: 'primary.main', color: 'primary.main'}, '&.Mui-disabled': {borderColor: '#d5d8e3', color: '#c4c7d4'}}

	const footer = (
		<Box sx={{display: {xs: 'flex', md: 'grid'}, flexDirection: 'column', gridTemplateColumns: '1fr auto 1fr', alignItems: 'center', gap: {xs: 1.25, md: 2}, px: 2, py: 1.25, bgcolor: HEAD_BG, borderTop: `1px solid ${BORDER}`, fontSize: 13, color: '#5b6178'}}>
			<Box sx={{display: {xs: 'none', md: 'block'}}} />
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25}}>
				<IconButton aria-label={t('Previous page')} disabled={p.page <= 1} onClick={() => update({page: p.page === 2 ? '' : String(p.page - 1)})} sx={pg_btn}><ChevronLeftOutlined sx={{fontSize: 18}} /></IconButton>
				<Box component="label" sx={{display: 'flex', alignItems: 'center', gap: 0.75, color: '#3a3f55'}}>
					{t('Page')} <PageInput page={p.page} last={p.last_page} onChange={(n) => update({page: n === 1 ? '' : String(n)})} /> / {p.last_page}
				</Box>
				<IconButton aria-label={t('Next page')} disabled={p.page >= p.last_page} onClick={() => update({page: String(p.page + 1)})} sx={pg_btn}><ChevronRightOutlined sx={{fontSize: 18}} /></IconButton>
			</Box>
			<Box component="label" sx={{justifySelf: 'end', display: 'flex', alignItems: 'center', gap: 1}}>
				{t('Rows per page')}
				<Select size="small" value={p.per_page} onChange={(e) => update({per_page: String(e.target.value), page: ''})} sx={{height: 30, fontSize: 13, bgcolor: '#fff'}} inputProps={{'aria-label': t('Rows per page')}}>
					{grid.per_page_options.map((n) => <MenuItem key={n} value={n} sx={{fontSize: 13}}>{n}</MenuItem>)}
				</Select>
			</Box>
		</Box>
	)

	return (
		<>
			<Box sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1.5}}>
				<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, flexGrow: 1, minWidth: 0}}>
					<Box sx={{width: 36, height: 36, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: 2, bgcolor: '#f0ecff', color: 'primary.main'}}><MenuIcon name={icon} /></Box>
					<Typography component="h1" noWrap sx={{m: 0, fontSize: {xs: 20, md: 22}, fontWeight: 600, letterSpacing: '-0.01em'}}>{grid.title}</Typography>
					{/* bookmarks: placeholder until the bookmarks module */}
					<IconButton aria-label={t('Bookmark')} sx={{width: 34, height: 34, color: 'primary.main'}}><BookmarkBorderOutlined sx={{fontSize: 22}} /></IconButton>
				</Box>
				{search_group}
				{can_add && <Button variant="contained" startIcon={<AddCircleOutlineOutlined />} onClick={onAdd}>{is_mobile ? t('Add') : `${t('Add')} ${grid.label}`}</Button>}
			</Box>

			<Box sx={{display: 'flex', flexWrap: {xs: 'nowrap', md: 'wrap'}, alignItems: 'flex-end', gap: 0.75, overflowX: {xs: 'auto', md: 'visible'}, mt: -0.5}}>
				{grid.chips && (
					<>
						<ButtonBase sx={tab_sx(chip === '')} onClick={() => update({chip: '', page: ''})}>{t('All')}<TabCount value={grid.chips.total} on={chip === ''} /></ButtonBase>
						{grid.chips.options.map((o) => <ButtonBase key={o.value} sx={tab_sx(chip === o.value)} onClick={() => update({chip: o.value, page: ''})}>{o.label}<TabCount value={o.count} on={chip === o.value} /></ButtonBase>)}
					</>
				)}
				<Box component="span" sx={{ml: 'auto', flexShrink: 0, pl: 1, fontSize: 13, color: '#5b6178', display: 'flex', alignItems: 'center', gap: 1}}>
					{loading && <CircularProgress size={12} />}
					{t('[FROM] to [TO] (total [TOTAL])', {FROM: from, TO: to, TOTAL: p.total})}
				</Box>
			</Box>

			<Box sx={{bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '6px', overflow: 'hidden'}}>
				{selected.length > 0 && (
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5, px: 2, py: 0.75, bgcolor: '#f7f5ff', borderBottom: `1px solid ${BORDER}`, fontSize: 13}}>
						<Box component="span" sx={{fontWeight: 600, color: '#4b2fd6'}}>{t('[COUNT] selected', {COUNT: selected.length})}</Box>
						<Button size="small" color="error" startIcon={<DeleteOutlineOutlined />} onClick={() => setToDelete(grid.rows.filter((r) => selected.includes(r.id)))} sx={{height: 30}}>{t('Delete')}</Button>
					</Box>
				)}
				<Box sx={{overflowX: 'auto', opacity: loading ? 0.6 : 1, transition: 'opacity .15s'}}>{body}</Box>
				{p.total > 0 && footer}
			</Box>

			<Menu anchorEl={grid_menu} open={!!grid_menu} onClose={() => setGridMenu(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}} slotProps={menu_paper}>
				<Typography component="li" sx={{listStyle: 'none', px: 2, pt: 0.75, pb: 0.5, fontSize: 11, fontWeight: 600, letterSpacing: '.06em', textTransform: 'uppercase', color: '#6b7088'}}>{t('Saved filters')}</Typography>
				<MenuItem disabled sx={{fontSize: 14}}>{t('No saved filter')}</MenuItem>
			</Menu>

			<Menu anchorEl={columns_menu} open={!!columns_menu} onClose={() => setColumnsMenu(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}} slotProps={menu_paper}>
				{grid.columns.map((c) => (
					<MenuItem key={c.name} disabled={!hidden.includes(c.name) && columns.length === 1} onClick={() => toggleColumn(c.name)} sx={{py: 0.25}}>
						<Checkbox size="small" checked={!hidden.includes(c.name)} tabIndex={-1} sx={{p: 0.5, mr: 1}} />
						<ListItemText>{c.label}</ListItemText>
					</MenuItem>
				))}
			</Menu>

			<Menu anchorEl={selection_menu} open={!!selection_menu} onClose={() => setSelectionMenu(null)} slotProps={menu_paper}>
				<MenuItem onClick={() => { setSelected(grid.rows.map((r) => r.id)); setSelectionMenu(null) }}>{t('Select page')} ({grid.rows.length})</MenuItem>
				<MenuItem onClick={() => { setSelected([]); setSelectionMenu(null) }}>{t('Clear selection')}</MenuItem>
				<Divider />
				<MenuItem disabled={!selected.length} onClick={() => { setSelectionMenu(null); setToDelete(grid.rows.filter((r) => selected.includes(r.id))) }} sx={{color: 'error.main'}}>
					<ListItemIcon><DeleteOutlineOutlined fontSize="small" color="error" /></ListItemIcon>
					<ListItemText>{t('Delete selection')} ({selected.length})</ListItemText>
				</MenuItem>
			</Menu>

			<FiltersPanel anchor={filters_anchor} filters={grid.filters} conditions={grid.conditions} match={grid.match} onClose={() => setFiltersAnchor(null)} onApply={applyConditions} />

			<ConfirmDialog
				open={!!to_delete}
				busy={deleting}
				title={(to_delete?.length ?? 0) > 1 ? t('Delete [COUNT] records?', {COUNT: to_delete?.length ?? 0}) : t('Delete this [LABEL]?', {LABEL: grid.label})}
				onCancel={() => setToDelete(null)}
				onConfirm={confirmDelete}
			/>
		</>
	)

}

// rows of a tab, with the search and the conditions applied
function TabCount({value, on}: {value: number, on: boolean})
{

	return <Box component="span" sx={{minWidth: 16, px: 0.5, borderRadius: 0.75, fontSize: 10.5, fontWeight: 600, lineHeight: '16px', textAlign: 'center', bgcolor: on ? '#e2d9ff' : '#e6e8ee', color: on ? '#4b2fd6' : '#5b6178'}}>{value}</Box>

}
