import { useEffect, useRef, useState } from 'react'
import { Box, CircularProgress } from '@mui/material'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
import { api, ApiException } from '../api'
import { t } from '../i18n'
import { BADGES } from './DataGrid'

type Marker = {id: number, lat: number, lng: number, title: string, color: string, popup: {label: string, value: string}[]}
type MapData = {markers: Marker[], total: number, truncated: boolean, colors: Record<string, string>, tiles: {url: string, attribution: string, max_zoom: number}}

// leaflet.markercluster extends the global L
const cluster = async (): Promise<L.MarkerClusterGroup> => {

	(window as unknown as {L: typeof L}).L = L
	await import('leaflet.markercluster')
	return L.markerClusterGroup({showCoverageOnHover: false, maxClusterRadius: 50})

}

// pin of a marker, colored like the badges
function pin(color: string): L.DivIcon
{

	const html = `<span style="display:block;width:18px;height:18px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:${color};border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4)"></span>`
	return L.divIcon({html, className: '', iconSize: [18, 18], iconAnchor: [9, 18], popupAnchor: [0, -18]})

}

// popup built from dom nodes: the values of the records are never parsed as html
function popup(m: Marker, onOpen: (id: number) => void): HTMLElement
{

	const box = document.createElement('div')
	box.style.cssText = 'display:flex;flex-direction:column;gap:4px;min-width:180px;font:13px/1.4 inherit'

	const title = document.createElement('strong')
	title.textContent = m.title || `#${m.id}`
	box.append(title)

	for(const line of m.popup)
	{

		if(line.value === '')continue
		const row = document.createElement('div')
		const label = document.createElement('span')
		label.style.color = 'var(--u-text3)'
		label.textContent = `${line.label}: `
		row.append(label, document.createTextNode(line.value))
		box.append(row)

	}

	const open = document.createElement('button')
	open.type = 'button'
	open.textContent = t('Open record')
	open.style.cssText = 'margin-top:4px;align-self:flex-start;padding:0;border:0;background:none;color:var(--mui-palette-primary-main);font:inherit;font-weight:600;cursor:pointer'
	open.addEventListener('click', () => onOpen(m.id))
	box.append(open)

	return box

}

/**
 * map view of a grid ($grid->map()): markers of the records with a position, same search, filters and chip as the grid
 * leaflet (tiles of config map/tiles), markers grouped when close, fitted to the markers
 */
export default function MapView({module, query, version, onOpen}: {module: string, query: string, version: number, onOpen: (id: number) => void})
{

	const node = useRef<HTMLDivElement>(null)
	const map = useRef<L.Map | null>(null)
	const layer = useRef<L.MarkerClusterGroup | null>(null)
	const tiles = useRef('')
	const open = useRef(onOpen)
	const [data, setData] = useState<MapData | null>(null)
	const [loading, setLoading] = useState(true)
	const [error, setError] = useState('')

	useEffect(() => { open.current = onOpen }, [onOpen])

	// the map is created once, removed with the view
	useEffect(() => {

		if(!node.current)return
		map.current = L.map(node.current, {center: [46.6, 2.4], zoom: 6, worldCopyJump: true})
		return () => { map.current?.remove(); map.current = null; layer.current = null; tiles.current = '' }

	}, [])

	useEffect(() => {

		let cancelled = false
		setLoading(true)
		const params = new URLSearchParams(query)
		params.delete('page')
		params.delete('view')
		const search = params.toString()

		api<MapData>(`${module}/map/${search ? `?${search}` : ''}`)
			.then((d) => { if(!cancelled){ setData(d); setError('') } })
			.catch((e) => !cancelled && setError(e instanceof ApiException ? e.error.message : t('network error, please retry')))
			.finally(() => !cancelled && setLoading(false))

		return () => { cancelled = true }

	}, [module, query, version])

	// markers of the data, fitted once loaded
	useEffect(() => {

		if(!data || !map.current)return
		let cancelled = false
		const m = map.current

		if(tiles.current !== data.tiles.url)
		{

			tiles.current = data.tiles.url
			L.tileLayer(data.tiles.url, {attribution: data.tiles.attribution, maxZoom: data.tiles.max_zoom}).addTo(m)

		}

		cluster().then((group) => {

			if(cancelled)return
			layer.current?.remove()
			layer.current = group

			for(const marker of data.markers)
			{

				const badge = data.colors[marker.color]
				const color = (badge && BADGES[badge]?.fg) || 'var(--mui-palette-primary-main)'
				L.marker([marker.lat, marker.lng], {icon: pin(color), title: marker.title}).bindPopup(() => popup(marker, (id) => open.current(id))).addTo(group)

			}

			group.addTo(m)
			if(data.markers.length)m.fitBounds(group.getBounds(), {padding: [30, 30], maxZoom: 15})

		})

		return () => { cancelled = true }

	}, [data])

	return (
		<Box sx={{position: 'relative'}}>
			<Box ref={node} role="region" aria-label={t('Map')} sx={{height: {xs: 'calc(100vh - 230px)', md: 'calc(100vh - 250px)'}, minHeight: 380, opacity: loading ? 0.6 : 1, transition: 'opacity .15s', '& .leaflet-popup-content-wrapper': {borderRadius: '6px'}}} />
			{loading && !data && <CircularProgress size={28} sx={{position: 'absolute', top: '45%', left: '50%', ml: '-14px', zIndex: 500}} />}
			{(error || data) && (
				<Box role="status" sx={{position: 'absolute', left: 10, bottom: 10, zIndex: 500, px: 1.25, py: 0.5, borderRadius: '6px', bgcolor: 'var(--u-paper)', boxShadow: 1, fontSize: 12.5, color: error ? 'var(--u-error)' : 'var(--u-text2)'}}>
					{error || (data?.truncated ? t('[SHOWN] of [TOTAL] records shown', {SHOWN: data.markers.length, TOTAL: data.total}) : t('[COUNT] records on the map', {COUNT: data?.total ?? 0}))}
				</Box>
			)}
		</Box>
	)

}
