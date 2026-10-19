import { Box } from '@mui/material'

// shortcut: flags of the common languages only (the others show no flag), add a country code here when a language needs it
const FLAGS = import.meta.glob('../../node_modules/flag-icons/flags/4x3/{gb,us,fr,de,es,it,pt,nl,be,ch,pl,cz,se,dk,no,fi,gr,ro,hu,tr,ru,ua,cn,jp,kr,sa,il,br,ca}.svg', {eager: true, query: '?url', import: 'default'}) as Record<string, string>

/**
 * flag of a country (lowercase iso code, ex: gb, fr), nothing when unknown
 */
export function Flag({code, width = 20}: {code: string, width?: number})
{

	const url = Object.entries(FLAGS).find(([path]) => path.endsWith(`/${code}.svg`))?.[1]
	if(!url)return null

	return <Box component="img" src={url} alt="" aria-hidden="true" sx={{width, height: Math.round(width * 0.75), borderRadius: '2px', boxShadow: '0 0 0 1px rgba(0, 0, 0, 0.08)', objectFit: 'cover', flexShrink: 0}} />

}
