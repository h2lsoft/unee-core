import { useEffect, useState, type FormEvent } from 'react'
import { Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, FormHelperText, IconButton, InputLabel, MenuItem, Select, Switch, Typography, useMediaQuery, useTheme } from '@mui/material'
import { CloseOutlined } from '@mui/icons-material'
import { api, ApiException, type FormField, type FormPayload } from '../api'
import { t } from '../i18n'
import { Field, PasswordField } from './Field'
import { useToast } from './Toast'

// datetime-local works with "Y-m-dTH:i", the api with "Y-m-d H:i:s"
const toInput = (field: FormField, value: string) => (field.type === 'datetime' && value) ? value.replace(' ', 'T').slice(0, 16) : value
const toApi = (field: FormField, value: string) => (field.type === 'datetime' && value) ? `${value.replace('T', ' ')}:00`.slice(0, 19) : value

function Input({field, value, errors, onChange}: {field: FormField, value: string, errors?: string[], onChange: (value: string) => void})
{

	const id = `field-${field.name}`
	const label = field.label + (field.required ? ' *' : '')
	const help = !errors && field.help && <FormHelperText sx={{m: 0, mt: -0.25}}>{field.help}</FormHelperText>

	if(field.type === 'boolean')
	{

		return (
			<Box>
				<FormControlLabel control={<Switch checked={value === 'yes'} onChange={(e) => onChange(e.target.checked ? 'yes' : 'no')} disabled={field.readonly} />} label={field.label} slotProps={{typography: {sx: {fontSize: 14}}}} />
				{help}
			</Box>
		)

	}

	if(field.type === 'select')
	{

		return (
			<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}>
				<InputLabel id={`${id}-label`} sx={{fontSize: 13, fontWeight: 500, color: '#3a3f55'}}>{label}</InputLabel>
				<Select labelId={`${id}-label`} id={id} value={value} displayEmpty disabled={field.readonly} error={!!errors} onChange={(e) => onChange(e.target.value)} sx={{height: 40, fontSize: 14}}>
					{!field.required && <MenuItem value="">-</MenuItem>}
					{field.options?.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
				</Select>
				{errors && <FormHelperText error sx={{m: 0}}>{errors.join(', ')}</FormHelperText>}
				{help}
			</Box>
		)

	}

	const common = {
		id,
		label,
		value,
		errors,
		disabled: field.readonly,
		placeholder: field.placeholder,
		onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => onChange(e.target.value),
		inputProps: {maxLength: field.max_length},
	}

	if(field.type === 'password')return <Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}><PasswordField {...common} autoComplete="new-password" />{help}</Box>

	const multiline = (field.type === 'textarea' || field.type === 'html')
	const type = {email: 'email', number: 'number', integer: 'number', date: 'date', datetime: 'datetime-local'}[field.type as string] ?? 'text'

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}>
			<Field {...common} type={type} multiline={multiline} minRows={multiline ? field.rows ?? 4 : undefined} sx={multiline ? {height: 'auto', alignItems: 'flex-start'} : undefined} />
			{help}
		</Box>
	)

}

/**
 * add (id = 0) or edit form of a module, fields from the api, errors under the fields
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
		<Dialog open={open} onClose={onClose} fullScreen={full_screen} maxWidth="sm" fullWidth slotProps={{paper: {component: 'form', onSubmit: submit, noValidate: true} as object}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 18, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{id ? t('Edit') : t('Add')} {label}</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>

			<DialogContent dividers sx={{borderColor: '#eceef3'}}>
				{error && <Typography color="error" sx={{fontSize: 14, mb: 2}}>{error}</Typography>}
				{!data && !error && <Box sx={{display: 'flex', justifyContent: 'center', py: 6}}><CircularProgress /></Box>}
				{data && (
					<Box sx={{display: 'grid', gridTemplateColumns: 'repeat(12, 1fr)', columnGap: 2, rowGap: 2.25, py: 0.5}}>
						{data.form.fields.map((f) => (
							<Box key={f.name} sx={{gridColumn: {xs: 'span 12', md: `span ${f.width}`}}}>
								<Input field={f} value={values[f.name] ?? ''} errors={errors[f.name]} onChange={(v) => setValues({...values, [f.name]: v})} />
							</Box>
						))}
					</Box>
				)}
			</DialogContent>

			<DialogActions sx={{px: 3, py: 2}}>
				<Button onClick={onClose} color="inherit">{t('Cancel')}</Button>
				<Button type="submit" variant="contained" disabled={!data || saving}>{saving ? <CircularProgress size={20} color="inherit" /> : (id ? t('Save') : t('Create'))}</Button>
			</DialogActions>
		</Dialog>
	)

}
