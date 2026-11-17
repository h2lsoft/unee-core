import { useState } from 'react'
import { Box, ButtonBase, Checkbox, Chip, FormControlLabel, InputBase, Popover } from '@mui/material'
import { ArrowDropDownOutlined, SearchOutlined } from '@mui/icons-material'
import type { Option } from '../api'
import { t } from '../i18n'

// chips shown in the field, the others counted
const CHIPS_MAX = 8
// search box above the checkboxes from this count of options
const SEARCH_FROM = 24

/**
 * multiselect shown as checkboxes in columns in a popup (->set('display', 'checkboxes')->set('columns', 3)): long lists of short values (ex: file types)
 * the field shows the chosen values as chips; All / None and a search box (long lists) on top of the popup
 */
export default function CheckboxesSelect({id, options, value, columns = 3, error = false, readonly = false, placeholder, onChange}: {id: string, options: Option[], value: string[], columns?: number, error?: boolean, readonly?: boolean, placeholder?: string, onChange: (value: string[]) => void})
{

	const [anchor, setAnchor] = useState<HTMLElement | null>(null)
	const [search, setSearch] = useState('')

	const chosen = options.filter((o) => value.includes(o.value))
	const needle = search.trim().toLowerCase()
	const shown = needle ? options.filter((o) => o.label.toLowerCase().includes(needle) || o.value.toLowerCase().includes(needle)) : options

	// order of the options kept whatever the order of the clicks
	const toggle = (v: string) => {

		const next = value.includes(v) ? value.filter((x) => x !== v) : [...value, v]
		onChange(options.map((o) => o.value).filter((x) => next.includes(x)))

	}

	const link = {fontSize: 13, fontWeight: 500, color: 'var(--u-accent-text)', px: 0.75, py: 0.25, borderRadius: 1, '&:hover': {bgcolor: 'var(--u-accent-hover)'}}

	return (
		<>
			<ButtonBase id={id} disabled={readonly} aria-haspopup="dialog" aria-expanded={!!anchor} onClick={(e) => setAnchor(e.currentTarget)}
				sx={{width: '100%', minHeight: 34, display: 'flex', alignItems: 'center', justifyContent: 'flex-start', gap: 0.5, flexWrap: 'wrap', pl: '10px', pr: '30px', py: '4px', position: 'relative', textAlign: 'left',
					border: '1px solid', borderColor: error ? 'error.main' : (anchor ? 'primary.main' : 'var(--u-border-strong)'), borderRadius: '4px', bgcolor: readonly ? 'var(--u-head)' : 'transparent', '&:hover': {borderColor: error ? 'error.main' : (anchor ? 'primary.main' : 'var(--u-text3)')}}}>
				{!chosen.length && <Box component="span" sx={{fontSize: 14, color: 'var(--u-faint)'}}>{placeholder ?? ''}</Box>}
				{chosen.slice(0, CHIPS_MAX).map((o) => <Chip key={o.value} label={o.label} size="small" sx={{height: 22, fontSize: 12.5}} />)}
				{chosen.length > CHIPS_MAX && <Chip label={`+${chosen.length - CHIPS_MAX}`} size="small" sx={{height: 22, fontSize: 12.5}} title={chosen.slice(CHIPS_MAX).map((o) => o.label).join(', ')} />}
				<ArrowDropDownOutlined sx={{position: 'absolute', right: 6, top: '50%', transform: anchor ? 'translateY(-50%) rotate(180deg)' : 'translateY(-50%)', color: 'var(--u-text3)'}} />
			</ButtonBase>

			<Popover open={!!anchor} anchorEl={anchor} onClose={() => { setAnchor(null); setSearch('') }} anchorOrigin={{vertical: 'bottom', horizontal: 'left'}} transformOrigin={{vertical: 'top', horizontal: 'left'}}
				slotProps={{paper: {role: 'dialog', 'aria-label': t('Choice'), sx: {width: anchor ? Math.max(anchor.offsetWidth, 420) : 420, maxWidth: 'calc(100vw - 32px)', mt: 0.5, borderRadius: '10px', border: '1px solid var(--u-border)', boxShadow: '0 8px 24px var(--u-shadow)'}} as object}}>
				<Box sx={{display: 'flex', alignItems: 'center', gap: 0.5, px: 1.5, py: 1, borderBottom: '1px solid var(--u-border-soft)'}}>
					{options.length >= SEARCH_FROM && (
						<Box sx={{display: 'flex', alignItems: 'center', gap: 0.75, flex: 1, height: 30, px: 1, mr: 1, border: '1px solid var(--u-border-strong)', borderRadius: 1.5, color: 'var(--u-muted)'}}>
							<SearchOutlined sx={{fontSize: 16}} />
							<InputBase autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search')} inputProps={{'aria-label': t('Search')}} sx={{flex: 1, fontSize: 13}} />
						</Box>
					)}
					<Box component="span" sx={{flex: (options.length >= SEARCH_FROM) ? 'none' : 1, fontSize: 12.5, color: 'var(--u-muted)', mr: 1}}>{t('[N] / [TOTAL]', {N: chosen.length, TOTAL: options.length})}</Box>
					<ButtonBase onClick={() => onChange(options.map((o) => o.value))} sx={link}>{t('All')}</ButtonBase>
					<ButtonBase onClick={() => onChange([])} sx={link}>{t('None')}</ButtonBase>
				</Box>
				<Box sx={{display: 'grid', gridTemplateColumns: {xs: 'repeat(2, minmax(0, 1fr))', sm: `repeat(${columns}, minmax(0, 1fr))`}, columnGap: 1, px: 1.5, py: 1, maxHeight: 340, overflowY: 'auto'}}>
					{shown.map((o) => (
						<FormControlLabel key={o.value} label={o.label} control={<Checkbox size="small" checked={value.includes(o.value)} onChange={() => toggle(o.value)} sx={{p: 0.5}} />}
							sx={{m: 0, minWidth: 0, '& .MuiFormControlLabel-label': {fontSize: 13.5, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}} />
					))}
					{!shown.length && <Box sx={{gridColumn: '1 / -1', py: 2, textAlign: 'center', fontSize: 13, color: 'var(--u-muted)'}}>{t('No result')}</Box>}
				</Box>
			</Popover>
		</>
	)

}
