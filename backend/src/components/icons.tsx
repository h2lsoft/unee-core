import { Box } from '@mui/material'
import '../icons.css'

/**
 * material symbol by name (xcore_menu.icon, xcore_plugin.icon...), https://fonts.google.com/icons, invalid name = extension
 */
export function MenuIcon({name, fontSize = 20, color}: {name: string, fontSize?: number, color?: string})
{

	const icon = /^[a-z0-9_]+$/.test(name) ? name : 'extension'
	return <Box component="span" className="material-symbols" aria-hidden="true" sx={{fontSize, width: fontSize, height: fontSize, color}}>{icon}</Box>

}
