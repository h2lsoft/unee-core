import { useState, type ComponentProps } from 'react'
import { Box, FormHelperText, IconButton, InputAdornment, InputLabel, OutlinedInput } from '@mui/material'
import { VisibilityOffOutlined, VisibilityOutlined } from '@mui/icons-material'
import { t } from '../i18n'

type FieldProps = {id: string, label: string, errors?: string[]} & Omit<ComponentProps<typeof OutlinedInput>, 'error'>

// label above the input (corporate form), errors under it
export function Field({id, label, errors, ...input}: FieldProps)
{

	return (
		<Box sx={{display: 'flex', flexDirection: 'column', gap: 0.75}}>
			<InputLabel htmlFor={id} sx={{fontSize: 13, fontWeight: 500, color: 'var(--u-text2)'}}>{label}</InputLabel>
			<OutlinedInput id={id} fullWidth error={!!errors} {...input} />
			{errors && <FormHelperText error sx={{m: 0}}>{errors.join(', ')}</FormHelperText>}
		</Box>
	)

}

// password input with the show / hide button
export function PasswordField(props: Omit<FieldProps, 'type' | 'endAdornment'>)
{

	const [visible, setVisible] = useState(false)

	return (
		<Field
			{...props}
			type={visible ? 'text' : 'password'}
			endAdornment={
				<InputAdornment position="end">
					<IconButton size="small" aria-label={visible ? t('hide password') : t('show password')} onClick={() => setVisible(!visible)} edge="end">
						{visible ? <VisibilityOffOutlined fontSize="small" /> : <VisibilityOutlined fontSize="small" />}
					</IconButton>
				</InputAdornment>
			}
		/>
	)

}
