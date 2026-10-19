import { useEffect, useState } from 'react'
import { Autocomplete, Box, TextField } from '@mui/material'

const minutes = (v: string) => /^\d{2}:\d{2}$/.test(v) ? Number(v.slice(0, 2)) * 60 + Number(v.slice(3)) : NaN
const hhmm = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`

/**
 * typed text to hh:mm (like outlook): 9 -> 09:00, 930 -> 09:30, 1415 -> 14:15, 9h30 / 9:30 / 9.30 -> 09:30, '' when invalid
 */
export function parseTime(text: string): string
{

	const s = text.trim().toLowerCase()
	const sep = s.match(/^(\d{1,2})\s*[:h.]\s*(\d{0,2})$/)
	let h: number, m: number
	if(sep)[h, m] = [Number(sep[1]), Number(sep[2] || 0)]
	else if(/^\d{1,2}$/.test(s))[h, m] = [Number(s), 0]
	else if(/^\d{3,4}$/.test(s))[h, m] = [Number(s.slice(0, -2)), Number(s.slice(-2))]
	else return ''

	return (h < 24 && m < 60) ? hhmm(h * 60 + m) : ''

}

// beginning of a time typed, compared with the start of the options: 9 -> 09, 93 -> 09:3, 14 -> 14, 143 -> 14:3
function prefix(text: string): string
{

	const s = text.trim().toLowerCase().replace(/[h.]/, ':')
	const sep = s.match(/^(\d{1,2}):(\d{0,2})$/)
	if(sep)return `${sep[1].padStart(2, '0')}:${sep[2]}`
	if(!/^\d{1,4}$/.test(s))return s
	if(s.length === 1)return `0${s}`
	if(s.length === 2)return (Number(s) > 23) ? `0${s[0]}:${s[1]}` : s
	return (s.length === 3) ? `0${s[0]}:${s.slice(1)}` : `${s.slice(0, 2)}:${s.slice(2)}`

}

// duration of the end time: 30 min, 1 h, 1,5 h
function duration(start: number, end: number): string
{

	const d = end - start
	if(d < 60)return `${d} min`
	const h = d / 60
	return `${Number.isInteger(h) ? h : h.toLocaleString(undefined, {maximumFractionDigits: 2})} h`

}

/**
 * time field like outlook: free typing (9, 930, 9h30), list of times by step, durations in the list of an end time (from = start time)
 */
export default function TimeInput({id, value, step = 30, from = '', error = false, readOnly = false, onChange}: {id?: string, value: string, step?: number, from?: string, error?: boolean, readOnly?: boolean, onChange: (value: string) => void})
{

	const [text, setText] = useState(value)
	useEffect(() => setText(value), [value])

	const start = minutes(from)
	// end time: the times after the start, with their duration
	const first = Number.isNaN(start) ? 0 : start + step
	const options = Array.from({length: Math.max(0, Math.ceil((24 * 60 - first) / step))}, (_, i) => hhmm(first + i * step))

	const commit = (raw: string) => {

		const time = parseTime(raw)
		if(time && time !== value)onChange(time)
		setText(time || value)

	}

	return (
		<Autocomplete
			id={id}
			freeSolo
			disableClearable
			disabled={readOnly}
			options={options}
			value={value}
			inputValue={text}
			onInputChange={(_, v, reason) => reason === 'input' && setText(v)}
			onChange={(_, v) => typeof v === 'string' && commit(v)}
			// enter validates the time, it never submits the form
			onKeyDown={(e) => { if(e.key === 'Enter')e.preventDefault() }}
			filterOptions={(list, state) => {

				// opened without typing: the whole list (scrolled to the value), else the times starting with the text
				const typed = (state.inputValue === value) ? '' : prefix(state.inputValue)
				return typed ? list.filter((o) => o.startsWith(typed)) : list

			}}
			renderOption={({key, ...props}, option) => (
				<Box component="li" key={key} {...props} sx={{fontSize: 13.5, justifyContent: 'space-between !important', gap: 1.5}}>
					<span>{option}</span>
					{!Number.isNaN(start) && <Box component="span" sx={{fontSize: 12, color: 'var(--u-muted)'}}>{duration(start, minutes(option))}</Box>}
				</Box>
			)}
			slotProps={{paper: {sx: {minWidth: Number.isNaN(start) ? 100 : 140}}, listbox: {sx: {maxHeight: 260}}}}
			renderInput={(params) => (
				<TextField {...params} error={error} onBlur={(e) => commit(e.target.value)}
					slotProps={{...params.slotProps, htmlInput: {...params.slotProps.htmlInput, maxLength: 5, inputMode: 'numeric', style: {textAlign: 'center'}}}}
					sx={{'& .MuiOutlinedInput-root': {py: 0, px: '5px !important', fontSize: 14}}} />
			)}
		/>
	)

}
