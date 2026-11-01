import { useRef, type ClipboardEvent, type KeyboardEvent } from 'react'
import { Box, InputBase } from '@mui/material'
import { t } from '../i18n'

/**
 * one box per digit (2fa code): typing moves to the next box, backspace to the previous one, a pasted code fills every box
 * onComplete is called once every box is filled
 */
export default function CodeInput({value, onChange, onComplete, length = 6, disabled = false, autoFocus = false, labelledBy}: {value: string, onChange: (value: string) => void, onComplete?: (value: string) => void, length?: number, disabled?: boolean, autoFocus?: boolean, labelledBy?: string})
{

	const refs = useRef<(HTMLInputElement | null)[]>([])
	const digits = Array.from({length}, (_, i) => value[i] ?? '')

	const update = (next: string, focus: number) => {

		next = next.replace(/\D/g, '').slice(0, length)
		onChange(next)
		refs.current[Math.min(focus, length - 1)]?.focus()
		if(next.length === length)onComplete?.(next)

	}

	const onInput = (i: number, typed: string) => {

		const digit = typed.replace(/\D/g, '').slice(-1)
		if(!digit)return
		update(value.slice(0, i) + digit + value.slice(i + 1), i + 1)

	}

	const onKeyDown = (i: number, e: KeyboardEvent<HTMLInputElement>) => {

		if(e.key === 'Backspace')
		{

			e.preventDefault()
			// empty box: clears and goes back to the previous one
			const at = (digits[i] === '' && i > 0) ? i - 1 : i
			update(value.slice(0, at) + value.slice(at + 1), at)

		}
		else if(e.key === 'ArrowLeft' && i > 0)refs.current[i - 1]?.focus()
		else if(e.key === 'ArrowRight' && i < length - 1)refs.current[i + 1]?.focus()

	}

	const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {

		e.preventDefault()
		const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, length)
		if(pasted)update(pasted, pasted.length)

	}

	return (
		<Box role="group" aria-labelledby={labelledBy} sx={{display: 'flex', alignItems: 'center', gap: {xs: 0.75, sm: 1}}}>
			{digits.map((digit, i) => (
				<Box key={i} sx={{display: 'contents'}}>
					{i === length / 2 && <Box aria-hidden="true" sx={{width: 10, height: 2, flexShrink: 0, borderRadius: 1, bgcolor: 'var(--u-border-strong)'}} />}
					<InputBase
						inputRef={(el: HTMLInputElement | null) => { refs.current[i] = el }}
						value={digit}
						disabled={disabled}
						autoFocus={autoFocus && i === 0}
						onChange={(e) => onInput(i, e.target.value)}
						onKeyDown={(e) => onKeyDown(i, e as KeyboardEvent<HTMLInputElement>)}
						onPaste={onPaste}
						onFocus={(e) => e.target.select()}
						inputProps={{inputMode: 'numeric', autoComplete: i === 0 ? 'one-time-code' : 'off', maxLength: 1, 'aria-label': t('Digit [N]', {N: i + 1})}}
						sx={{
							width: {xs: 42, sm: 48}, height: 56, border: '1px solid', borderColor: digit ? 'var(--u-accent-border)' : 'var(--u-border-strong)', borderRadius: '10px', bgcolor: 'var(--u-paper)',
							'&.Mui-focused': {borderColor: 'primary.main', boxShadow: '0 0 0 3px var(--u-accent-bg)'},
							'& input': {p: 0, textAlign: 'center', fontFamily: 'ui-monospace, Menlo, Consolas, monospace', fontSize: 24, fontWeight: 500},
						}}
					/>
				</Box>
			))}
		</Box>
	)

}
