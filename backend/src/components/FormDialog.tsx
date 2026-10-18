import { useEffect, useState, type FormEvent } from 'react'
import { Autocomplete, Box, Button, ButtonBase, Chip, CircularProgress, FormControlLabel, Radio, RadioGroup, Dialog, DialogActions, DialogContent, DialogTitle, FormHelperText, IconButton, InputAdornment, InputLabel, MenuItem, OutlinedInput, Select, Switch, TextField, useMediaQuery, useTheme } from '@mui/material'
import { CloseOutlined, ErrorOutlineOutlined, VisibilityOffOutlined, VisibilityOutlined } from '@mui/icons-material'
import CodeEditor from './Code'
import RichText from './RichText'
import { Flag } from './Flag'
import { api, ApiException, type FormAction, type FormField, type FormPayload } from '../api'
import { t } from '../i18n'
import { MenuIcon } from './icons'
import { useToast } from './Toast'

// datetime-local works with "Y-m-dTH:i", the api with "Y-m-d H:i:s", tags are kept as a json array in the state
export function toInput(field: FormField, value: unknown): string
{

	if(field.type === 'tags')return JSON.stringify(Array.isArray(value) ? value : [])
	const text = String(value ?? '')
	return (field.type === 'datetime' && text) ? text.replace(' ', 'T').slice(0, 16) : text

}

export function toApi(field: FormField, value: string): unknown
{

	if(field.type === 'tags')return words(value)
	return (field.type === 'datetime' && value) ? `${value.replace('T', ' ')}:00`.slice(0, 19) : value

}

function words(value: string): string[]
{

	try
	{

		const list = JSON.parse(value || '[]')
		return Array.isArray(list) ? list.map(String) : []

	}
	catch
	{

		return []

	}

}

// field shown only when another field has one of the values (\Component\Form::showIf)
export const isVisible = (field: FormField, values: Record<string, string>) => !field.show_if || field.show_if.values.includes(values[field.show_if.field] ?? '')

function PasswordInput(props: React.ComponentProps<typeof OutlinedInput>)
{

	const [visible, setVisible] = useState(false)

	return (
		<OutlinedInput
			{...props}
			type={visible ? 'text' : 'password'}
			autoComplete="new-password"
			endAdornment={
				<InputAdornment position="end">
					<IconButton size="small" edge="end" aria-label={visible ? t('hide password') : t('show password')} onClick={() => setVisible(!visible)}>
						{visible ? <VisibilityOffOutlined fontSize="small" /> : <VisibilityOutlined fontSize="small" />}
					</IconButton>
				</InputAdornment>
			}
		/>
	)

}

// control of a field, its label is in the row
export function Input({field, value, error, onChange}: {field: FormField, value: string, error: boolean, onChange: (value: string) => void})
{

	const id = `field-${field.name}`

	if(field.type === 'html')return <RichText id={id} value={value} error={error} readOnly={field.readonly} onChange={onChange} />

	// radio buttons on one line
	if(field.type === 'radio')
	{

		return (
			<RadioGroup row id={id} value={value} onChange={(e) => onChange(e.target.value)} sx={{minHeight: 40, columnGap: 1}}>
				{field.options?.map((o) => <FormControlLabel key={o.value} value={o.value} disabled={field.readonly} control={<Radio size="small" />} label={o.label} slotProps={{typography: {sx: {fontSize: 14}}}} sx={{mr: 1}} />)}
			</RadioGroup>
		)

	}

	if(field.type === 'code')return <CodeEditor id={id} value={value} language={field.language} error={error} readOnly={field.readonly} onChange={onChange} />

	// words typed as chips, enter or comma adds one
	if(field.type === 'tags')
	{

		return (
			<Autocomplete
				id={id}
				multiple
				freeSolo
				disabled={field.readonly}
				options={field.suggestions ?? []}
				value={words(value)}
				onChange={(_, list) => onChange(JSON.stringify([...new Set(list.map((w) => String(w).trim()).filter(Boolean))]))}
				renderValue={(list, getItemProps) => list.map((w, index) => <Chip {...getItemProps({index})} key={w} label={w} size="small" />)}
				renderInput={(params) => <TextField {...params} error={error} placeholder={field.placeholder} sx={{'& .MuiOutlinedInput-root': {py: '3px', fontSize: 14}}} />}
			/>
		)

	}

	if(field.type === 'boolean')
		return <Switch id={id} checked={value === 'yes'} onChange={(e) => onChange(e.target.checked ? 'yes' : 'no')} disabled={field.readonly} sx={{ml: -1}} />

	// material symbol name with its preview and a link to the icon library
	if(field.type === 'icon')
	{

		return (
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5}}>
				<OutlinedInput id={id} value={value} error={error} fullWidth disabled={field.readonly} placeholder="settings" onChange={(e) => onChange(e.target.value.trim().toLowerCase())} inputProps={{maxLength: field.max_length, spellCheck: false}}
					startAdornment={<InputAdornment position="start"><MenuIcon name={value || 'extension'} fontSize={22} color="#3a3f55" /></InputAdornment>} />
				<Box component="a" href="https://fonts.google.com/icons?icon.style=Outlined" target="_blank" rel="noopener noreferrer" sx={{flexShrink: 0, fontSize: 13, fontWeight: 500, whiteSpace: 'nowrap'}}>{t('Browse icons')}</Box>
			</Box>
		)

	}

	// color picker and its hex value
	if(field.type === 'color')
	{

		const hex = /^#[0-9a-f]{6}$/i.test(value) ? value : '#000000'

		return (
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1.5}}>
				<Box component="input" type="color" value={hex} disabled={field.readonly} onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value)} aria-label={field.label} sx={{width: 56, height: 40, p: '4px', border: '1px solid #d5d8e3', borderRadius: 2, bgcolor: '#fff', cursor: 'pointer'}} />
				<OutlinedInput id={id} value={value} error={error} disabled={field.readonly} onChange={(e) => onChange(e.target.value.trim())} inputProps={{maxLength: 7, spellCheck: false}} sx={{width: 140}} />
			</Box>
		)

	}

	// select with a search field, free = the options are suggestions
	if(field.type === 'select' && field.autocomplete)
	{

		const options = field.options ?? []
		const option = options.find((o) => o.value === value) ?? null

		return (
			<Autocomplete
				id={id}
				options={options}
				freeSolo={!!field.free}
				disabled={field.readonly}
				value={field.free ? (option ?? (value || null)) : option}
				inputValue={field.free ? (option?.label ?? value) : undefined}
				getOptionLabel={(o) => (typeof o === 'string') ? o : o.label}
				isOptionEqualToValue={(o, v) => o.value === ((typeof v === 'string') ? v : v.value)}
				onChange={(_, v) => onChange((v === null) ? '' : (typeof v === 'string') ? v : v.value)}
				onInputChange={field.free ? (_, v, reason) => reason === 'input' && onChange(v) : undefined}
				renderInput={(params) => <TextField {...params} error={error} placeholder={field.placeholder} slotProps={{...params.slotProps, htmlInput: {...params.slotProps.htmlInput, maxLength: field.max_length}}} sx={{'& .MuiOutlinedInput-root': {py: 0, fontSize: 14}}} />}
			/>
		)

	}

	if(field.type === 'select')
	{

		return (
			<Select id={id} value={value} displayEmpty fullWidth disabled={field.readonly} error={error} onChange={(e) => onChange(e.target.value)} sx={{height: 40, fontSize: 14}} inputProps={{'aria-labelledby': `${id}-label`}}>
				{!field.required && <MenuItem value="">-</MenuItem>}
				{field.options?.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
			</Select>
		)

	}

	const multiline = (field.type === 'textarea')
	const common = {
		id,
		value,
		error,
		fullWidth: true,
		disabled: field.readonly,
		placeholder: field.placeholder,
		onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => onChange(e.target.value),
		inputProps: {maxLength: field.max_length},
		endAdornment: (error && !multiline && field.type !== 'password') ? <InputAdornment position="end"><ErrorOutlineOutlined color="error" fontSize="small" /></InputAdornment> : undefined,
	}

	if(field.type === 'password')return <PasswordInput {...common} />

	const type = {email: 'email', number: 'number', integer: 'number', date: 'date', datetime: 'datetime-local'}[field.type as string] ?? 'text'
	// textareas: 150px high at least, they grow with the content
	return <OutlinedInput {...common} type={type} multiline={multiline} minRows={multiline ? field.rows ?? 4 : undefined} sx={multiline ? {height: 'auto', minHeight: 150, alignItems: 'flex-start'} : undefined} />

}

// row of a field: label on the first line of the control (40px), the help text stays under the control
function FieldRow({field, value, errors, onChange}: {field: FormField, value: string, errors: Record<string, string[]>, onChange: (value: string) => void})
{

	return (
		<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '150px 1fr'}, columnGap: 2.5, rowGap: 0.5, alignItems: 'start'}}>
			<InputLabel id={`field-${field.name}-label`} htmlFor={`field-${field.name}`} sx={{fontSize: 14, fontWeight: 500, color: '#3a3f55', textAlign: {xs: 'left', sm: 'right'}, whiteSpace: 'normal', lineHeight: {sm: '40px'}}}>
				{field.label}{field.required && <Box component="span" aria-hidden="true" sx={{color: 'error.main', ml: 0.5}}>*</Box>}
			</InputLabel>
			<Box sx={{minWidth: 0}}>
				<Input field={field} value={value} error={!!errors[field.name]} onChange={onChange} />
				{field.help && <FormHelperText sx={{m: 0, mt: 0.5}}>{field.help}</FormHelperText>}
			</Box>
		</Box>
	)

}

/**
 * add (id = 0) or edit form of a module, fields from the api, one row per field (label + control), errors listed on top
 * fields with a tab are shown under a tab bar, the active tab goes to the first field in error
 */
export default function FormDialog({module, id, label, open, onClose, onSaved, url, title}: {module: string, id: number, label: string, open: boolean, onClose: () => void, onSaved: () => void, url?: string, title?: string})
{

	const theme = useTheme()
	const full_screen = useMediaQuery(theme.breakpoints.down('md'))
	const toast = useToast()
	const [data, setData] = useState<FormPayload | null>(null)
	const [values, setValues] = useState<Record<string, string>>({})
	const [errors, setErrors] = useState<Record<string, string[]>>({})
	const [error, setError] = useState('')
	const [saving, setSaving] = useState(false)
	const [tab, setTab] = useState('')
	const [running, setRunning] = useState('')

	useEffect(() => {

		if(!open)return
		setData(null)
		setErrors({})
		setError('')

		// url: a form of its own (ex: profile/), read with GET and saved with PUT
		api<FormPayload>(url ?? (id ? `${module}/${id}/` : `${module}/form/`))
			.then((payload) => {

				setData(payload)
				setTab(payload.form.tabs?.[0]?.key ?? '')
				setValues(Object.fromEntries(payload.form.fields.map((f) => [f.name, toInput(f, payload.record[f.name])])))

			})
			.catch((e) => setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))

	}, [open, module, id, url])

	// field errors on top, messages of h2lsoft/validator (they hold the label of the field)
	const summary = Object.values(errors).flat()
	const body = () => Object.fromEntries((data?.form.fields ?? []).filter((f) => !f.readonly).map((f) => [f.name, toApi(f, values[f.name] ?? '')]))

	const submit = async (e: FormEvent) => {

		e.preventDefault()
		if(!data)return
		setSaving(true)
		setErrors({})
		setError('')

		try
		{

			await api(url ?? (id ? `${module}/${id}/` : `${module}/`), {method: (id || url) ? 'PUT' : 'POST', body: body()})
			toast((id || url) ? t('Saved') : t('Created'))
			onSaved()

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			if(api_error?.fields)
			{

				setErrors(api_error.fields)
				const first = data.form.fields.find((f) => api_error.fields?.[f.name] && f.tab)
				if(first?.tab)setTab(first.tab)

			}
			else setError(api_error?.message ?? t('network error, please retry'))

		}
		finally
		{

			setSaving(false)

		}

	}

	// button of the form: values of the form (not saved) and the active tab are sent
	const runAction = async (action: FormAction) => {

		setRunning(action.name)

		try
		{

			const data = await api<{message?: string}>(`${module}/${id}/action/${action.name}/`, {method: 'POST', body: {...body(), tab}})
			toast(data?.message ?? t('Done'))

		}
		catch(err)
		{

			toast(err instanceof ApiException ? err.error.message : t('network error, please retry'), 'error')

		}
		finally
		{

			setRunning('')

		}

	}

	const fields = (data?.form.fields ?? []).filter((f) => isVisible(f, values))
	const tabs = data?.form.tabs ?? []
	const first_tab = fields.findIndex((f) => f.tab)
	const row = (f: FormField) => <FieldRow key={f.name} field={f} value={values[f.name] ?? ''} errors={errors} onChange={(v) => setValues({...values, [f.name]: v})} />
	const wide = fields.some((f) => f.type === 'code' || f.type === 'html')

	return (
		<Dialog open={open} onClose={(_, reason) => reason !== 'backdropClick' && onClose()} fullScreen={full_screen} maxWidth="lg" fullWidth slotProps={{paper: {component: 'form', onSubmit: submit, noValidate: true, sx: {maxWidth: {md: wide ? 1000 : 760}}} as object}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 18, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{title ?? `${id ? t('Edit') : t('Add')} ${label}`}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>

			<DialogContent sx={{pt: 1}}>
				{!data && !error && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
				{(error || summary.length > 0) && (
					<Box role="alert" sx={{mb: 2.5, px: 2, py: 1.5, borderRadius: '6px', bgcolor: '#fdecee', color: '#b3261e', fontSize: 14}}>
						{error && <div>{error}</div>}
						{summary.length > 0 && <Box component="ul" sx={{m: 0, pl: 2.25}}>{summary.map((line) => <li key={line}>{line}</li>)}</Box>}
					</Box>
				)}
				{data && (
					<Box sx={{display: 'flex', flexDirection: 'column', gap: 1.75, py: 0.5}}>
						{fields.filter((f, i) => !f.tab && (first_tab < 0 || i < first_tab)).map(row)}
						{first_tab >= 0 && (
							<>
								<Box role="tablist" sx={{display: 'flex', gap: 2.75, borderBottom: '1px solid #eceef3', mt: 0.5, overflowX: 'auto'}}>
									{tabs.filter((tb) => fields.some((f) => f.tab === tb.key)).map((tb) => {

										const on = (tb.key === tab)
										const has_error = fields.some((f) => f.tab === tb.key && errors[f.name])
										return (
											<ButtonBase key={tb.key} role="tab" aria-selected={on} onClick={() => setTab(tb.key)} sx={{height: 40, gap: 1, px: 0.25, borderBottom: '2px solid', borderColor: on ? 'primary.main' : 'transparent', color: has_error ? 'error.main' : (on ? '#4b2fd6' : '#5b6178'), fontSize: 14, fontWeight: on ? 600 : 400, whiteSpace: 'nowrap'}}>
												{tb.flag && <Flag code={tb.flag} />}{tb.label}
											</ButtonBase>
										)

									})}
								</Box>
								{fields.filter((f) => f.tab === tab).map(row)}
								{fields.some((f, i) => !f.tab && i > first_tab) && <Box sx={{height: '1px', bgcolor: '#f1f2f6', my: 0.25}} />}
								{fields.filter((f, i) => !f.tab && i > first_tab).map(row)}
							</>
						)}
					</Box>
				)}
			</DialogContent>

			<DialogActions sx={{px: 3, pt: 1, pb: 2.5, gap: 1}}>
				{id > 0 && data?.form.actions?.map((a) => (
					<Button key={a.name} variant="outlined" color="inherit" disabled={running !== ''} onClick={() => runAction(a)} startIcon={running === a.name ? <CircularProgress size={16} color="inherit" /> : <MenuIcon name={a.icon} fontSize={18} />} sx={{borderColor: '#d5d8e3', color: '#3a3f55', fontWeight: 500}}>{a.label}</Button>
				))}
				<Button type="submit" variant="contained" disabled={!data || saving}>{saving ? <CircularProgress size={20} color="inherit" /> : ((id || url) ? t('Save') : t('Create'))}</Button>
			</DialogActions>
		</Dialog>
	)

}
