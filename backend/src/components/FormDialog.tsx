import { useEffect, useState, type FormEvent } from 'react'
import { Autocomplete, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, FormHelperText, IconButton, InputAdornment, InputLabel, MenuItem, OutlinedInput, Select, Switch, TextField, useMediaQuery, useTheme } from '@mui/material'
import { CloseOutlined, ErrorOutlineOutlined, VisibilityOffOutlined, VisibilityOutlined } from '@mui/icons-material'
import { api, ApiException, type FormField, type FormPayload } from '../api'
import { t } from '../i18n'
import { useToast } from './Toast'

// datetime-local works with "Y-m-dTH:i", the api with "Y-m-d H:i:s"
const toInput = (field: FormField, value: string) => (field.type === 'datetime' && value) ? value.replace(' ', 'T').slice(0, 16) : value
const toApi = (field: FormField, value: string) => (field.type === 'datetime' && value) ? `${value.replace('T', ' ')}:00`.slice(0, 19) : value

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
function Input({field, value, error, onChange}: {field: FormField, value: string, error: boolean, onChange: (value: string) => void})
{

	const id = `field-${field.name}`

	if(field.type === 'boolean')
		return <Switch id={id} checked={value === 'yes'} onChange={(e) => onChange(e.target.checked ? 'yes' : 'no')} disabled={field.readonly} sx={{ml: -1}} />

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

	const multiline = (field.type === 'textarea' || field.type === 'html')
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

/**
 * add (id = 0) or edit form of a module, fields from the api, one row per field (label + control), errors listed on top
 */
export default function FormDialog({module, id, label, open, onClose, onSaved}: {module: string, id: number, label: string, open: boolean, onClose: () => void, onSaved: () => void})
{

	const theme = useTheme()
	const full_screen = useMediaQuery(theme.breakpoints.down('md'))
	const toast = useToast()
	const [data, setData] = useState<FormPayload | null>(null)
	const [values, setValues] = useState<Record<string, string>>({})
	const [errors, setErrors] = useState<Record<string, string[]>>({})
	const [error, setError] = useState('')
	const [saving, setSaving] = useState(false)

	useEffect(() => {

		if(!open)return
		setData(null)
		setErrors({})
		setError('')

		api<FormPayload>(id ? `${module}/${id}/` : `${module}/form/`)
			.then((payload) => {

				setData(payload)
				setValues(Object.fromEntries(payload.form.fields.map((f) => [f.name, toInput(f, String(payload.record[f.name] ?? ''))])))

			})
			.catch((e) => setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))

	}, [open, module, id])

	// field errors on top: "label: message"
	const summary = Object.entries(errors).flatMap(([name, messages]) => {

		const label = data?.form.fields.find((f) => f.name === name)?.label ?? name
		return messages.map((message) => t('[FIELD]: [MESSAGE]', {FIELD: label, MESSAGE: message}))

	})

	const submit = async (e: FormEvent) => {

		e.preventDefault()
		if(!data)return
		setSaving(true)
		setErrors({})
		setError('')

		try
		{

			const body = Object.fromEntries(data.form.fields.filter((f) => !f.readonly).map((f) => [f.name, toApi(f, values[f.name] ?? '')]))
			await api(id ? `${module}/${id}/` : `${module}/`, {method: id ? 'PUT' : 'POST', body})
			toast(id ? t('Saved') : t('Created'))
			onSaved()

		}
		catch(err)
		{

			const api_error = (err instanceof ApiException) ? err.error : null
			if(api_error?.fields)setErrors(api_error.fields)
			else setError(api_error?.message ?? t('network error, please retry'))

		}
		finally
		{

			setSaving(false)

		}

	}

	return (
		<Dialog open={open} onClose={onClose} fullScreen={full_screen} maxWidth="md" fullWidth slotProps={{paper: {component: 'form', onSubmit: submit, noValidate: true, sx: {maxWidth: {md: 760}}} as object}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 18, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{id ? t('Edit') : t('Add')} {label}</Box>
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
						{data.form.fields.map((f) => {

							// label on the first line of the control (40px), the help text stays under the control
							return (
								<Box key={f.name} sx={{display: 'grid', gridTemplateColumns: {xs: '1fr', sm: '150px 1fr'}, columnGap: 2.5, rowGap: 0.5, alignItems: 'start'}}>
									<InputLabel id={`field-${f.name}-label`} htmlFor={`field-${f.name}`} sx={{fontSize: 14, fontWeight: 500, color: '#3a3f55', textAlign: {xs: 'left', sm: 'right'}, whiteSpace: 'normal', lineHeight: {sm: '40px'}}}>
										{f.label}{f.required && <Box component="span" aria-hidden="true" sx={{color: 'error.main', ml: 0.5}}>*</Box>}
									</InputLabel>
									<Box sx={{minWidth: 0}}>
										<Input field={f} value={values[f.name] ?? ''} error={!!errors[f.name]} onChange={(v) => setValues({...values, [f.name]: v})} />
										{f.help && <FormHelperText sx={{m: 0, mt: 0.5}}>{f.help}</FormHelperText>}
									</Box>
								</Box>
							)

						})}
					</Box>
				)}
			</DialogContent>

			<DialogActions sx={{px: 3, pt: 1, pb: 2.5}}>
				<Button type="submit" variant="contained" disabled={!data || saving}>{saving ? <CircularProgress size={20} color="inherit" /> : (id ? t('Save') : t('Create'))}</Button>
			</DialogActions>
		</Dialog>
	)

}
