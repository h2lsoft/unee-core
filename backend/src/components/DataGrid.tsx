import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router'
import {
	Box, Button, ButtonBase, Checkbox, CircularProgress, Dialog, DialogActions, DialogTitle, IconButton, InputBase,
	ListItemIcon, ListItemText, Menu, MenuItem, OutlinedInput, Popover, Select, Switch, Typography, useMediaQuery, useTheme,
} from '@mui/material'
import { AddOutlined, ChevronLeftOutlined, ChevronRightOutlined, DeleteOutlineOutlined, EditOutlined, FilterListOutlined, MoreVertOutlined, SearchOutlined } from '@mui/icons-material'
import { api, ApiException, type Grid, type GridColumn, type GridFilter, type Row } from '../api'
import { getLanguage, t } from '../i18n'
import { PageHeader } from './BackendLayout'
import { useToast } from './Toast'

const BORDER = '#eceef3'
const ROW_BORDER = '#f1f2f6'

// badge palette (DataGrid::addColBadge colors)
const BADGES: Record<string, {bg: string, fg: string}> = {
	grey: {bg: '#eef0f5', fg: '#3a3f55'},
	red: {bg: '#fde8ec', fg: '#9b1c3a'},
	green: {bg: '#e3f4f1', fg: '#0f5e52'},
	purple: {bg: '#efe9ff', fg: '#4b2fd6'},
	blue: {bg: '#e6f0fd', fg: '#1d4f91'},
	orange: {bg: '#fdf0e1', fg: '#8a4b0f'},
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

const chip_sx = (on: boolean) => ({
	height: 30,
	px: 1.5,
	flexShrink: 0,
	border: '1px solid',
	borderColor: on ? '#d9cfff' : '#e3e5ec',
	borderRadius: 999,
	bgcolor: on ? '#f0ecff' : '#fff',
	color: on ? '#4b2fd6' : '#3a3f55',
	fontWeight: on ? 500 : 400,
	fontSize: 13,
})

// rows of a chip, with the search and the filters applied
function ChipCount({value, on}: {value: number, on: boolean})
{

	return <Box component="span" sx={{ml: 0.75, minWidth: 18, px: 0.6, borderRadius: 999, fontSize: 11, fontWeight: 600, lineHeight: '18px', textAlign: 'center', bgcolor: on ? '#e2d9ff' : '#f2f3f7', color: on ? '#4b2fd6' : '#5b6178'}}>{value}</Box>

}

export function Badge({value, color}: {value: string, color?: string})
{

	const c = BADGES[color ?? 'grey'] ?? BADGES.grey
	return <Box component="span" sx={{display: 'inline-block', px: 1, py: '2px', borderRadius: 1.5, fontSize: 12, fontWeight: 500, bgcolor: c.bg, color: c.fg, whiteSpace: 'nowrap'}}>{value}</Box>

}

function formatDate(value: string, with_time: boolean): string
{

	const date = new Date(value.replace(' ', 'T'))
	if(isNaN(date.getTime()))return value
	return new Intl.DateTimeFormat(getLanguage(), with_time ? {dateStyle: 'short', timeStyle: 'short'} : {dateStyle: 'short'}).format(date)

}

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

// filters popover: operator and value per filter, applied together
function FiltersButton({filters, active, onApply}: {filters: GridFilter[], active: Grid['active_filters'], onApply: (values: Record<string, {value: string, operator: string}>) => void})
{

	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	const [draft, setDraft] = useState<Record<string, {value: string, operator: string}>>({})
	const count = Object.keys(active).length

	const open = (e: React.MouseEvent<HTMLElement>) => {

		setDraft(Object.fromEntries(filters.map((f) => [f.name, active[f.name] ?? {value: '', operator: f.operators[0]}])))
		setAnchor(e.currentTarget)

	}

	const apply = (values: typeof draft) => {

		setAnchor(null)
		onApply(values)

	}

	return (
		<>
			<ButtonBase onClick={open} sx={{...chip_sx(count > 0), display: 'inline-flex', gap: 0.75}}><FilterListOutlined sx={{fontSize: 16}} />{t('Filters')}{count > 0 && ` (${count})`}</ButtonBase>
			<Popover open={!!anchor} anchorEl={anchor} onClose={() => setAnchor(null)} anchorOrigin={{vertical: 'bottom', horizontal: 'left'}} slotProps={{paper: {sx: {mt: 0.75, p: 2, width: 340, maxWidth: 'calc(100vw - 32px)', display: 'flex', flexDirection: 'column', gap: 2}}}}>
				{filters.map((f) => {

					const d = draft[f.name] ?? {value: '', operator: f.operators[0]}
					const set = (patch: Partial<typeof d>) => setDraft({...draft, [f.name]: {...d, ...patch}})
					const no_value = (d.operator === 'empty' || d.operator === '!empty')

					return (
						<Box key={f.name} sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}>
							<Typography component="label" sx={{fontSize: 13, fontWeight: 500, color: '#3a3f55'}}>{f.label}</Typography>
							<Box sx={{display: 'flex', gap: 1}}>
								{f.operators.length > 1 && (
									<Select size="small" value={d.operator} onChange={(e) => set({operator: e.target.value})} sx={{width: 130, flexShrink: 0, fontSize: 13}} slotProps={{input: {'aria-label': `${f.label} operator`}}}>
										{f.operators.map((op) => <MenuItem key={op} value={op} sx={{fontSize: 13}}>{t(OPERATOR_LABELS[op] ?? op)}</MenuItem>)}
									</Select>
								)}
								{!no_value && ((f.options.length && d.operator !== 'in')
									? (
										<Select size="small" displayEmpty value={d.value} onChange={(e) => set({value: e.target.value})} sx={{flexGrow: 1, minWidth: 0, fontSize: 13}} slotProps={{input: {'aria-label': f.label}}}>
											<MenuItem value="" sx={{fontSize: 13}}>-</MenuItem>
											{f.options.map((o) => <MenuItem key={o.value} value={o.value} sx={{fontSize: 13}}>{o.label}</MenuItem>)}
										</Select>
									)
									: <OutlinedInput size="small" type={f.type === 'date' ? 'date' : (f.type === 'number' ? 'number' : 'text')} value={d.value} onChange={(e) => set({value: e.target.value})} sx={{flexGrow: 1, minWidth: 0, fontSize: 13}} inputProps={{'aria-label': f.label}} />)}
							</Box>
						</Box>
					)

				})}
				<Box sx={{display: 'flex', justifyContent: 'space-between', gap: 1}}>
					<Button color="inherit" onClick={() => apply({})}>{t('Clear filters')}</Button>
					<Button variant="contained" onClick={() => apply(draft)}>{t('Apply')}</Button>
				</Box>
			</Popover>
		</>
	)

}

function RowMenu({row, can_edit, can_delete, onEdit, onDelete}: {row: Row, can_edit: boolean, can_delete: boolean, onEdit: (row: Row) => void, onDelete: (row: Row) => void})
{

	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	if(!can_edit && !can_delete)return null

	return (
		<>
			<IconButton size="small" aria-label={t('More actions')} onClick={(e) => { e.stopPropagation(); setAnchor(e.currentTarget) }} sx={{width: 36, height: 36, color: '#5b6178'}}><MoreVertOutlined sx={{fontSize: 18}} /></IconButton>
			<Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)} onClick={(e) => e.stopPropagation()} anchorOrigin={{vertical: 'bottom', horizontal: 'right'}} transformOrigin={{vertical: 'top', horizontal: 'right'}}>
				{can_edit && <MenuItem onClick={() => { setAnchor(null); onEdit(row) }}><ListItemIcon><EditOutlined fontSize="small" /></ListItemIcon><ListItemText>{t('Edit')}</ListItemText></MenuItem>}
				{can_delete && <MenuItem onClick={() => { setAnchor(null); onDelete(row) }} sx={{color: 'error.main'}}><ListItemIcon><DeleteOutlineOutlined fontSize="small" color="error" /></ListItemIcon><ListItemText>{t('Delete')}</ListItemText></MenuItem>}
			</Menu>
		</>
	)

}

/**
 * grid of a module (GET /api/<module>/), state kept in the url (search, chip, filters, sort, page)
 */
export default function DataGrid({module, version, onAdd, onEdit, onTitle}: {module: string, version: number, onAdd: () => void, onEdit: (row: Row) => void, onTitle?: (title: string, label: string) => void})
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

	const applyFilters = (values: Record<string, {value: string, operator: string}>) => {

		const next = new URLSearchParams(params)
		for(const key of [...next.keys()])if(key.startsWith('f[') || key.startsWith('o['))next.delete(key)
		for(const [name, f] of Object.entries(values))
		{

			const no_value = (f.operator === 'empty' || f.operator === '!empty')
			if(!no_value && f.value === '')continue
			if(!no_value)next.set(`f[${name}]`, f.value)
			next.set(`o[${name}]`, f.operator)

		}
		next.delete('page')
		setParams(next, {replace: true})

	}

	const sortBy = (column: GridColumn) => {

		if(!column.sortable || !grid)return
		const dir = (grid.sort.column === column.name && grid.sort.dir === 'asc') ? 'desc' : 'asc'
		update({sort: column.name, dir, page: ''})

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
	const from = (p.total === 0) ? 0 : (p.page - 1) * p.per_page + 1
	const to = Math.min(p.page * p.per_page, p.total)
	const all_selected = grid.rows.length > 0 && grid.rows.every((r) => selected.includes(r.id))
	const chip = params.get('chip') ?? ''
	const mobile_title = grid.columns.find((c) => c.name === grid.mobile.title) ?? grid.columns.find((c) => c.name !== 'id') ?? grid.columns[0]
	const mobile_subtitle = grid.columns.find((c) => c.name === grid.mobile.subtitle)
	const mobile_badge = grid.columns.find((c) => c.type === 'badge')

	const search_box = grid.search && (
		<Box component="label" sx={{width: {xs: '100%', md: 280}, height: 38, display: 'flex', alignItems: 'center', gap: 1, px: 1.5, border: '1px solid #e3e5ec', borderRadius: 2, bgcolor: '#fff', color: '#6b7088', order: {xs: 3, md: 0}}}>
			<SearchOutlined sx={{fontSize: 18}} />
			<InputBase type="search" placeholder={grid.search.placeholder} value={search} onChange={(e) => setSearch(e.target.value)} inputProps={{'aria-label': t('Search')}} sx={{flexGrow: 1, fontSize: 14}} />
		</Box>
	)

	const pager = (
		<Box sx={{display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 1.5, px: {xs: 2, md: 2.5}, py: 1.5, fontSize: 13, color: '#5b6178'}}>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1}}>
				{t('Rows per page')}
				<Select variant="standard" disableUnderline value={p.per_page} onChange={(e) => update({per_page: String(e.target.value), page: ''})} sx={{fontSize: 13, fontWeight: 500, color: '#1f2335'}} inputProps={{'aria-label': t('Rows per page')}}>
					{grid.per_page_options.map((n) => <MenuItem key={n} value={n} sx={{fontSize: 13}}>{n}</MenuItem>)}
				</Select>
			</Box>
			<Box sx={{display: 'flex', alignItems: 'center', gap: 0.5}}>
				<IconButton size="small" aria-label={t('Previous page')} disabled={p.page <= 1} onClick={() => update({page: String(p.page - 1)})}><ChevronLeftOutlined /></IconButton>
				{pages(p.page, p.last_page).map((n, i) => (n === 0)
					? <Box key={`gap${i}`} component="span" sx={{px: 0.5}}>...</Box>
					: <ButtonBase key={n} aria-current={n === p.page ? 'page' : undefined} onClick={() => update({page: n === 1 ? '' : String(n)})} sx={{width: 36, height: 36, borderRadius: 2, fontSize: 13, fontWeight: n === p.page ? 600 : 400, bgcolor: n === p.page ? '#f0ecff' : 'transparent', color: n === p.page ? '#4b2fd6' : '#3a3f55'}}>{n}</ButtonBase>)}
				<IconButton size="small" aria-label={t('Next page')} disabled={p.page >= p.last_page} onClick={() => update({page: String(p.page + 1)})}><ChevronRightOutlined /></IconButton>
			</Box>
		</Box>
	)

	let body: ReactNode
	if(grid.rows.length === 0)
	{

		body = <Box sx={{py: 6, textAlign: 'center', color: '#6b7088', fontSize: 14}}>{t('No results')}</Box>

	}
	else if(is_mobile)
	{

		body = grid.rows.map((row) => (
			<Box key={row.id} onClick={() => can_edit && onEdit(row)} sx={{display: 'flex', alignItems: 'center', gap: 1.5, p: '12px 8px 12px 16px', borderBottom: `1px solid ${ROW_BORDER}`, cursor: can_edit ? 'pointer' : 'default'}}>
				{batch && <Checkbox size="small" checked={selected.includes(row.id)} onClick={(e) => e.stopPropagation()} onChange={() => setSelected(selected.includes(row.id) ? selected.filter((id) => id !== row.id) : [...selected, row.id])} slotProps={{input: {'aria-label': t('Select row')}}} sx={{ml: -1}} />}
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

		const th = {p: '12px', borderBottom: `1px solid ${BORDER}`, textAlign: 'left' as const, fontSize: 12, fontWeight: 600, color: '#5b6178', textTransform: 'uppercase' as const, letterSpacing: '0.04em', whiteSpace: 'nowrap' as const}
		const td = {height: 48, p: '0 12px', borderBottom: `1px solid ${ROW_BORDER}`}

		body = (
			<Box component="table" sx={{width: '100%', borderCollapse: 'collapse', fontSize: 14, minWidth: 760}}>
				<thead>
					<tr>
						{batch && <Box component="th" sx={{...th, width: 44, p: '12px 0 12px 20px'}}><Checkbox size="small" checked={all_selected} indeterminate={!all_selected && selected.length > 0} onChange={() => setSelected(all_selected ? [] : grid.rows.map((r) => r.id))} slotProps={{input: {'aria-label': t('Select all')}}} sx={{p: 0}} /></Box>}
						{grid.columns.map((c, i) => (
							<Box component="th" key={c.name} sx={{...th, width: c.width, textAlign: c.align ?? 'left', pl: (i === 0 && !batch) ? 2.5 : 1.5}} aria-sort={grid.sort.column === c.name ? (grid.sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
								{c.sortable
									? <ButtonBase onClick={() => sortBy(c)} sx={{font: 'inherit', color: 'inherit', textTransform: 'inherit', letterSpacing: 'inherit', gap: 0.5}}>{c.label}{grid.sort.column === c.name && <span aria-hidden="true">{grid.sort.dir === 'asc' ? '↑' : '↓'}</span>}</ButtonBase>
									: c.label}
							</Box>
						))}
						<Box component="th" sx={{...th, width: 96, pr: 2.5}}><Box component="span" sx={{position: 'absolute', left: -9999}}>{t('Actions')}</Box></Box>
					</tr>
				</thead>
				<tbody>
					{grid.rows.map((row) => (
						<Box component="tr" key={row.id} onDoubleClick={() => can_edit && onEdit(row)} sx={{'&:hover': {bgcolor: '#fafbfd'}}}>
							{batch && <Box component="td" sx={{...td, p: '0 0 0 20px'}}><Checkbox size="small" checked={selected.includes(row.id)} onChange={() => setSelected(selected.includes(row.id) ? selected.filter((id) => id !== row.id) : [...selected, row.id])} slotProps={{input: {'aria-label': t('Select row')}}} sx={{p: 0}} /></Box>}
							{grid.columns.map((c, i) => (
								<Box component="td" key={c.name} sx={{...td, pl: (i === 0 && !batch) ? 2.5 : 1.5, textAlign: c.align ?? 'left', color: c.name === 'id' ? '#5b6178' : '#1f2335', fontWeight: c.name === grid.mobile.title ? 500 : 400, maxWidth: c.width ? undefined : 360, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>
									<Cell column={c} row={row} can_edit={can_edit} onToggle={toggle} />
								</Box>
							))}
							<Box component="td" sx={{...td, pr: 2.5}}>
								<Box sx={{display: 'flex', justifyContent: 'flex-end', gap: 0.25}}>
									{can_edit && <IconButton size="small" aria-label={t('Edit')} onClick={() => onEdit(row)} sx={{width: 36, height: 36, color: 'primary.main'}}><EditOutlined sx={{fontSize: 18}} /></IconButton>}
									<RowMenu row={row} can_edit={false} can_delete={can_delete} onEdit={onEdit} onDelete={(r) => setToDelete([r])} />
								</Box>
							</Box>
						</Box>
					))}
				</tbody>
			</Box>
		)

	}

	return (
		<>
			<PageHeader title={grid.title}>
				{!is_mobile && search_box}
				{can_add && (
					<Button variant="contained" startIcon={<AddOutlined />} onClick={onAdd} sx={{height: 38}}>{is_mobile ? t('Add') : `${t('Add')} ${grid.label}`}</Button>
				)}
			</PageHeader>

			{is_mobile && search_box}

			{(grid.chips || grid.filters.length > 0 || !is_mobile) && (
				<Box sx={{display: 'flex', flexWrap: {xs: 'nowrap', md: 'wrap'}, alignItems: 'center', gap: 1, overflowX: {xs: 'auto', md: 'visible'}}}>
					{grid.filters.length > 0 && <FiltersButton filters={grid.filters} active={grid.active_filters} onApply={applyFilters} />}
					{grid.chips && (
						<>
							<ButtonBase sx={chip_sx(chip === '')} onClick={() => update({chip: '', page: ''})}>{t('All')}<ChipCount value={grid.chips.total} on={chip === ''} /></ButtonBase>
							{grid.chips.options.map((o) => <ButtonBase key={o.value} sx={chip_sx(chip === o.value)} onClick={() => update({chip: o.value, page: ''})}>{o.label}<ChipCount value={o.count} on={chip === o.value} /></ButtonBase>)}
						</>
					)}
					{!is_mobile && <Box component="span" sx={{ml: 'auto', fontSize: 13, color: '#5b6178'}}>{loading ? <CircularProgress size={14} /> : t('[FROM] to [TO] of [TOTAL]', {FROM: from, TO: to, TOTAL: p.total})}</Box>}
				</Box>
			)}

			<Box sx={{bgcolor: '#fff', border: `1px solid ${BORDER}`, borderRadius: '10px', overflow: 'hidden'}}>
				{selected.length > 0 && (
					<Box sx={{display: 'flex', alignItems: 'center', gap: 2, px: {xs: 2, md: 2.5}, py: 1, bgcolor: '#f7f5ff', borderBottom: `1px solid ${BORDER}`, fontSize: 13}}>
						<span>{t('[COUNT] selected', {COUNT: selected.length})}</span>
						<Button size="small" color="error" startIcon={<DeleteOutlineOutlined />} onClick={() => setToDelete(grid.rows.filter((r) => selected.includes(r.id)))} sx={{height: 30}}>{t('Delete selection')}</Button>
					</Box>
				)}
				<Box sx={{overflowX: 'auto', opacity: loading ? 0.6 : 1, transition: 'opacity .15s'}}>{body}</Box>
				{p.total > 0 && pager}
			</Box>

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

// page numbers around the current one, 0 = gap
function pages(page: number, last: number): number[]
{

	const set = new Set([1, last, page - 1, page, page + 1].filter((n) => n >= 1 && n <= last))
	const sorted = [...set].sort((a, b) => a - b)
	const out: number[] = []
	sorted.forEach((n, i) => {

		if(i > 0 && n - sorted[i - 1] > 1)out.push(0)
		out.push(n)

	})
	return out

}
