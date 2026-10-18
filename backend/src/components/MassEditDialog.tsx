import { useEffect, useState, type FormEvent } from 'react'
import { Box, Button, Checkbox, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, FormHelperText, IconButton, InputLabel, Typography } from '@mui/material'
import { CloseOutlined } from '@mui/icons-material'
import { api, ApiException, type FormField } from '../api'
import { t } from '../i18n'
import { Input } from './FormDialog'
import { useToast } from './Toast'

/**
 * mass edit of the selected records: the checked fields are written, the others stay unchanged
 */
export default function MassEditDialog({module, title, ids, onClose, onSaved}: {module: string, title: string, ids: number[] | null, onClose: () => void, onSaved: () => void})
{

	const toast = useToast()
	const [fields, setFields] = useState<FormField[] | null>(null)
	const [values, setValues] = useState<Record<string, string>>({})
	const [checked, setChecked] = useState<string[]>([])
	const [errors, setErrors] = useState<Record<string, string[]>>({})
	const [error, setError] = useState('')
	const [saving, setSaving] = useState(false)

	useEffect(() => {

		if(!ids)return
		setFields(null)
		setValues({})
		setChecked([])
		setErrors({})
		setError('')

		api<{form: {fields: FormField[]}}>(`${module}/batch/form/`)
			.then((data) => setFields(data.form.fields))
			.catch((e) => setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))

	}, [ids, module])

	const summary = [...(error ? [error] : []), ...Object.values(errors).flat()]

	const submit = async (e: FormEvent) => {

		e.preventDefault()
		if(!ids || !checked.length)return
		setSaving(true)
		setErrors({})
		setError('')

		try
		{

			const data = await api<{updated: number}>(`${module}/batch/update/`, {method: 'POST', body: {ids, values: Object.fromEntries(checked.map((name) => [name, values[name] ?? '']))}})
			toast(t('[COUNT] updated', {COUNT: data.updated}))
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

	const count = ids?.length ?? 0

	return (
		<Dialog open={!!ids} onClose={(_, reason) => reason !== 'backdropClick' && onClose()} maxWidth="sm" fullWidth slotProps={{paper: {component: 'form', onSubmit: submit, noValidate: true} as object}}>
			<DialogTitle sx={{display: 'flex', alignItems: 'center', gap: 1, fontSize: 17, fontWeight: 600, pr: 1.5}}>
				<Box component="span" sx={{flexGrow: 1}}>{t('Mass edit')} - {title} ({count})</Box>
				<IconButton aria-label={t('Close')} onClick={onClose}><CloseOutlined /></IconButton>
			</DialogTitle>

			<DialogContent sx={{display: 'flex', flexDirection: 'column', gap: 1.75}}>
				{summary.length > 0 && (
					<Box role="alert" sx={{px: 2, py: 1.5, borderRadius: '6px', bgcolor: '#fdecee', color: '#b3261e', fontSize: 14}}>
						<Box component="ul" sx={{m: 0, pl: 2.25}}>{summary.map((line) => <li key={line}>{line}</li>)}</Box>
					</Box>
				)}
				<Typography sx={{fontSize: 14, color: 'text.secondary'}}>{t('Check the fields to change, the others stay unchanged.')}</Typography>
				{!fields && !error && <Box sx={{display: 'flex', justifyContent: 'center', py: 4}}><CircularProgress /></Box>}
				{fields?.map((f) => {

					const on = checked.includes(f.name)
					return (
						<Box key={f.name} sx={{display: 'grid', gridTemplateColumns: {xs: '28px 1fr', sm: '28px 130px 1fr'}, columnGap: 1.5, rowGap: 0.5, alignItems: 'start'}}>
							<Checkbox size="small" checked={on} onChange={() => setChecked(on ? checked.filter((n) => n !== f.name) : [...checked, f.name])} slotProps={{input: {'aria-label': `${t('Change')} ${f.label}`}}} sx={{p: 0, mt: '10px'}} />
							<InputLabel htmlFor={`field-${f.name}`} sx={{fontSize: 14, fontWeight: 500, color: on ? '#3a3f55' : '#a3a7b7', lineHeight: '40px', whiteSpace: 'normal'}}>
								{f.label}{f.required && <Box component="span" aria-hidden="true" sx={{color: 'error.main', ml: 0.5}}>*</Box>}
							</InputLabel>
							<Box sx={{minWidth: 0, gridColumn: {xs: '2', sm: 'auto'}}}>
								<Input field={{...f, readonly: !on}} value={values[f.name] ?? ''} error={!!errors[f.name]} onChange={(v) => setValues({...values, [f.name]: v})} />
								{f.help && on && <FormHelperText sx={{m: 0, mt: 0.5}}>{f.help}</FormHelperText>}
							</Box>
						</Box>
					)

				})}
			</DialogContent>

			<DialogActions sx={{px: 3, pt: 1, pb: 2.5}}>
				<Button type="submit" variant="contained" disabled={!fields || !checked.length || saving}>{saving ? <CircularProgress size={20} color="inherit" /> : t('Apply to [COUNT] records', {COUNT: count})}</Button>
			</DialogActions>
		</Dialog>
	)

}
