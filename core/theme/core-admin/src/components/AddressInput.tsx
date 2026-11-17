import { useEffect, useRef, useState } from 'react'
import { Autocomplete, Box, Button, CircularProgress, OutlinedInput, TextField } from '@mui/material'
import { MyLocationOutlined } from '@mui/icons-material'
import type { FormField } from '../api'
import { t } from '../i18n'

export type Address = {street: string, zip: string, city: string, country: string, lat: number | null, lng: number | null}

type Suggestion = {label: string, street: string, zip: string, city: string, lat: number, lng: number}

const EMPTY: Address = {street: '', zip: '', city: '', country: '', lat: null, lng: null}

// value of the form state (json) to an address
function parseAddress(value: string): Address
{

	try
	{

		const a = JSON.parse(value || '{}')
		return {...EMPTY, ...(a && typeof a === 'object' ? a : {})}

	}
	catch
	{

		return {...EMPTY}

	}

}

// the geocoder handles france only: country empty or france
const isFrance = (country: string) => ['', 'fr', 'fra', 'france'].includes(country.trim().toLowerCase())

// coordinate typed: number or null, a comma becomes a point
const coordinate = (text: string): number | null => {

	const clean = text.replace(',', '.').trim()
	return (clean === '' || Number.isNaN(Number(clean))) ? null : Number(clean)

}

// search of the ign geoplateforme (url of the field), called by the browser
async function search(url: string, q: string, limit: number, signal?: AbortSignal): Promise<Suggestion[]>
{

	const params = new URLSearchParams({q: q.slice(0, 200), index: 'address', limit: String(limit), autocomplete: '1'})
	const response = await fetch(`${url}?${params}`, {signal})
	if(!response.ok)return []

	const data = await response.json()
	return (data?.features ?? []).map((f: {geometry: {coordinates: [number, number]}, properties: Record<string, string>}) => ({
		label: f.properties.label ?? '',
		street: f.properties.name ?? '',
		zip: f.properties.postcode ?? '',
		city: f.properties.city ?? '',
		lng: f.geometry.coordinates[0],
		lat: f.geometry.coordinates[1],
	}))

}

/**
 * address field (\Component\Form::addAddress): street with the suggestions of the ign geoplateforme,
 * zip, city, country, then lat / lng (editable) and a button that sets them from the address
 */
export default function AddressInput({id, field, value, error, onChange}: {id: string, field: FormField, value: string, error: boolean, onChange: (value: string) => void})
{

	const address = parseAddress(value)
	const url = field.geocoder ?? ''
	const [options, setOptions] = useState<Suggestion[]>([])
	const [loading, setLoading] = useState(false)
	const [locating, setLocating] = useState(false)
	const [not_found, setNotFound] = useState(false)
	const [lat, setLat] = useState(address.lat === null ? '' : String(address.lat))
	const [lng, setLng] = useState(address.lng === null ? '' : String(address.lng))
	const timer = useRef<number>(0)
	const abort = useRef<AbortController | null>(null)

	// coordinates set from outside (suggestion, button, reload): the typed text follows
	useEffect(() => {

		if(coordinate(lat) !== address.lat)setLat(address.lat === null ? '' : String(address.lat))
		if(coordinate(lng) !== address.lng)setLng(address.lng === null ? '' : String(address.lng))
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [address.lat, address.lng])

	useEffect(() => () => { window.clearTimeout(timer.current); abort.current?.abort() }, [])

	const set = (patch: Partial<Address>) => {

		setNotFound(false)
		onChange(JSON.stringify({...address, ...patch}))

	}

	// suggestions while typing the street (france only, 3 characters at least)
	const suggest = (text: string) => {

		window.clearTimeout(timer.current)
		abort.current?.abort()
		if(!url || !isFrance(address.country) || text.trim().length < 3)
		{

			setOptions([])
			return

		}

		timer.current = window.setTimeout(() => {

			const controller = new AbortController()
			abort.current = controller
			setLoading(true)
			search(url, text.trim(), 6, controller.signal)
				.then(setOptions)
				.catch(() => {})
				.finally(() => setLoading(false))

		}, 300)

	}

	// button: coordinates of the address typed, always overwritten
	const locate = async () => {

		setLocating(true)
		setNotFound(false)

		try
		{

			const found = (await search(url, `${address.street} ${address.zip} ${address.city}`, 1))[0]
			if(found)onChange(JSON.stringify({...address, lat: found.lat, lng: found.lng}))
			else setNotFound(true)

		}
		catch
		{

			setNotFound(true)

		}
		finally
		{

			setLocating(false)

		}

	}

	const readonly = !!field.readonly
	const can_locate = !!url && isFrance(address.country) && `${address.street}${address.zip}${address.city}`.trim().length >= 3

	return (
		<Box id={id} sx={{display: 'flex', flexDirection: 'column', gap: 1}}>
			<Autocomplete
				freeSolo
				disabled={readonly}
				options={options}
				filterOptions={(list) => list}
				loading={loading}
				inputValue={address.street}
				value={null}
				getOptionLabel={(o) => (typeof o === 'string') ? o : o.label}
				onInputChange={(_, text, reason) => {

					if(reason !== 'input')return
					set({street: text})
					suggest(text)

				}}
				onChange={(_, o) => {

					if(!o || typeof o === 'string')return
					set({street: o.street, zip: o.zip, city: o.city, country: address.country || 'France', lat: o.lat, lng: o.lng})
					setOptions([])

				}}
				renderInput={(params) => <TextField {...params} error={error} placeholder={t('Street')} slotProps={{...params.slotProps, htmlInput: {...params.slotProps.htmlInput, maxLength: 255, 'aria-label': `${field.label} - ${t('Street')}`}}} sx={{'& .MuiOutlinedInput-root': {py: 0, fontSize: 14}}} />}
			/>
			<Box sx={{display: 'grid', gridTemplateColumns: {xs: '1fr 2fr', sm: '110px 1fr 1fr'}, gap: 1}}>
				<OutlinedInput value={address.zip} error={error} disabled={readonly} placeholder={t('Zip')} onChange={(e) => set({zip: e.target.value})} inputProps={{maxLength: 16, 'aria-label': `${field.label} - ${t('Zip')}`}} />
				<OutlinedInput value={address.city} error={error} disabled={readonly} placeholder={t('City')} onChange={(e) => set({city: e.target.value})} inputProps={{maxLength: 128, 'aria-label': `${field.label} - ${t('City')}`}} />
				<OutlinedInput value={address.country} error={error} disabled={readonly} placeholder="France" onChange={(e) => set({country: e.target.value})} inputProps={{maxLength: 64, 'aria-label': `${field.label} - ${t('Country')}`}} sx={{gridColumn: {xs: '1 / -1', sm: 'auto'}}} />
			</Box>
			<Box sx={{display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 1}}>
				<OutlinedInput value={lat} error={error} disabled={readonly} placeholder={t('Latitude')} onChange={(e) => { setLat(e.target.value); set({lat: coordinate(e.target.value)}) }} inputProps={{inputMode: 'decimal', 'aria-label': `${field.label} - ${t('Latitude')}`}} sx={{width: 150}} />
				<OutlinedInput value={lng} error={error} disabled={readonly} placeholder={t('Longitude')} onChange={(e) => { setLng(e.target.value); set({lng: coordinate(e.target.value)}) }} inputProps={{inputMode: 'decimal', 'aria-label': `${field.label} - ${t('Longitude')}`}} sx={{width: 150}} />
				{!readonly && (
					<Button variant="outlined" color="inherit" disabled={!can_locate || locating} onClick={locate} startIcon={locating ? <CircularProgress size={16} color="inherit" /> : <MyLocationOutlined fontSize="small" />}
						sx={{height: 34, borderColor: 'var(--u-border-strong)', color: 'var(--u-text2)', fontWeight: 500}}>{t('From the address')}</Button>
				)}
				{not_found && <Box role="status" sx={{fontSize: 13, color: 'var(--u-error)'}}>{t('Address not found')}</Box>}
			</Box>
		</Box>
	)

}
