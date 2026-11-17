import { useRef, type KeyboardEvent, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react'
import { Box, ButtonBase, Tooltip, Typography } from '@mui/material'
import { AccessTimeOutlined, DeleteOutlineOutlined, FolderOutlined, HomeOutlined, LinkOutlined, StorageOutlined, StarBorderOutlined } from '@mui/icons-material'
import { t } from '../../i18n'
import { driveSize, SIDEBAR_DEFAULT, SIDEBAR_MAX, SIDEBAR_MIN, type Pin, type Usage } from './driveApi'

export type Place = 'folder' | 'recent' | 'favorites' | 'trash' | 'search' | 'links'

// entry of the sidebar
function Entry({icon, label, active, onClick, title}: {icon: ReactNode, label: string, active: boolean, onClick: () => void, title?: string})
{

	return (
		<ButtonBase onClick={onClick} title={title} aria-current={active ? 'page' : undefined}
			sx={{width: '100%', height: 34, display: 'flex', justifyContent: 'flex-start', gap: 1.25, px: 1.25, borderRadius: 2, fontSize: 13.5, color: active ? 'var(--u-accent-text)' : 'var(--u-text3)', fontWeight: active ? 600 : 400, bgcolor: active ? 'var(--u-accent-bg)' : 'transparent', '&:hover': {bgcolor: active ? 'var(--u-accent-bg)' : 'var(--u-hover)'}}}>
			{icon}
			<Box component="span" sx={{overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{label}</Box>
		</ButtonBase>
	)

}

/**
 * storage of the instance: one line (icon, bar, percent), the details in the tooltip (orange from 80 %, red at 100 %)
 */
function Quota({usage}: {usage: Usage})
{

	const color = usage.full ? '#c62f4a' : (usage.percent >= 80 ? '#e08a2e' : 'var(--mui-palette-primary-main)')
	const text = usage.full ? '#c62f4a' : (usage.percent >= 80 ? '#b5651d' : 'var(--u-text3)')
	const lines = [
		usage.full ? t('Storage full, contact your administrator') : '',
		usage.quota ? t('Storage: [USED] of [TOTAL] ([PERCENT] %)', {USED: driveSize(usage.used), TOTAL: driveSize(usage.quota), PERCENT: usage.percent}) : t('Storage: [USED] (unlimited)', {USED: driveSize(usage.used)}),
		t('Files: [SIZE]', {SIZE: driveSize(usage.files)}),
		t('DB: [SIZE]', {SIZE: driveSize(usage.database)}),
	].filter(Boolean)

	return (
		<Tooltip placement="top-start" title={<Box sx={{whiteSpace: 'pre-line', lineHeight: 1.6}}>{lines.join('\n')}</Box>}>
			<Box tabIndex={0} aria-label={lines.join(', ')} sx={{mt: 1.5, pt: 1.25, px: 1.25, pb: 0.25, borderTop: '1px solid var(--u-border)', display: 'flex', alignItems: 'center', gap: 1, color: 'var(--u-text3)', outline: 0, '&:focus-visible': {boxShadow: '0 0 0 2px var(--u-accent-border)', borderRadius: 1}}}>
				<StorageOutlined sx={{fontSize: 16, flexShrink: 0}} />
				<Box role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={usage.percent} sx={{flex: 1, height: 4, borderRadius: 2, bgcolor: 'var(--u-border-strong)', overflow: 'hidden'}}>
					<Box sx={{height: 4, width: `${usage.quota ? usage.percent : 0}%`, bgcolor: color}} />
				</Box>
				<Typography component="span" sx={{fontSize: 12, fontWeight: 600, color: text, minWidth: 34, textAlign: 'right'}}>{usage.quota ? `${usage.percent} %` : '∞'}</Typography>
			</Box>
		</Tooltip>
	)

}

/**
 * sidebar of the File Manager: places, pinned folders, quota; its right edge resizes it (mouse, arrow keys, double click resets)
 */
export default function DriveSidebar({place, folder, pins, usage, width, locked, picker, onPlace, onFolder, onWidth}: {
	place: Place
	folder: number
	pins: Pin[]
	usage: Usage | null
	width: number
	locked: number | null
	// picker: no trash
	picker: boolean
	onPlace: (place: Place) => void
	onFolder: (id: number) => void
	onWidth: (width: number, save: boolean) => void
})
{

	const start = useRef<{x: number, w: number} | null>(null)
	const clamp = (w: number) => Math.max(SIDEBAR_MIN, Math.min(SIDEBAR_MAX, Math.round(w)))

	// drag of the handle: the width follows the mouse, saved on release
	const down = (e: ReactMouseEvent) => {

		e.preventDefault()
		start.current = {x: e.clientX, w: width}
		let last = width
		const move = (ev: MouseEvent) => { if(start.current)onWidth(last = clamp(start.current.w + ev.clientX - start.current.x), false) }
		const up = () => {

			document.removeEventListener('mousemove', move)
			document.removeEventListener('mouseup', up)
			document.body.style.cursor = ''
			start.current = null
			onWidth(last, true)

		}

		document.body.style.cursor = 'col-resize'
		document.addEventListener('mousemove', move)
		document.addEventListener('mouseup', up)

	}

	const key = (e: KeyboardEvent) => {

		const step = (e.key === 'ArrowLeft') ? -16 : ((e.key === 'ArrowRight') ? 16 : 0)
		if(!step)return
		e.preventDefault()
		e.stopPropagation()
		onWidth(clamp(width + step), true)

	}

	const icon = {fontSize: 18}

	return (
		<Box component="aside" aria-label={t('Places')} sx={{width, flex: `0 0 ${width}px`, boxSizing: 'border-box', position: 'relative', display: {xs: 'none', md: 'flex'}, flexDirection: 'column', gap: 0.25, p: '12px 10px', borderRight: '1px solid var(--u-border)', minHeight: 0, overflowY: 'auto'}}>
			<Entry icon={<HomeOutlined sx={icon} />} label={t('Files')} active={place === 'folder' && folder === (locked ?? 0)} onClick={() => onFolder(locked ?? 0)} />
			{locked === null && <Entry icon={<AccessTimeOutlined sx={icon} />} label={t('Recent')} active={place === 'recent'} onClick={() => onPlace('recent')} />}
			<Entry icon={<StarBorderOutlined sx={icon} />} label={t('Favorites')} active={place === 'favorites'} onClick={() => onPlace('favorites')} />
			{!picker && <Entry icon={<LinkOutlined sx={icon} />} label={t('Public links')} active={place === 'links'} onClick={() => onPlace('links')} />}
			{!picker && <Entry icon={<DeleteOutlineOutlined sx={icon} />} label={t('Trash')} active={place === 'trash'} onClick={() => onPlace('trash')} />}

			{pins.length > 0 && <Typography sx={{px: 1.25, pt: 2, pb: 0.75, fontSize: 11.5, fontWeight: 600, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--u-muted)'}}>{t('Pinned')}</Typography>}
			{pins.map((pin) => <Entry key={pin.id} icon={<FolderOutlined sx={{...icon, color: 'primary.main'}} />} label={pin.name} title={pin.path} active={place === 'folder' && folder === pin.id} onClick={() => onFolder(pin.id)} />)}

			<Box sx={{flex: 1}} />
			{usage && <Quota usage={usage} />}

			<Box role="separator" aria-orientation="vertical" aria-label={t('Resize the sidebar')} aria-valuemin={SIDEBAR_MIN} aria-valuemax={SIDEBAR_MAX} aria-valuenow={width} tabIndex={0}
				title={t('Drag to resize, double click to reset')} onMouseDown={down} onKeyDown={key} onDoubleClick={() => onWidth(SIDEBAR_DEFAULT, true)}
				sx={{position: 'absolute', top: 0, right: 0, width: 6, height: '100%', cursor: 'col-resize', zIndex: 2, outline: 0, '&::after': {content: '""', position: 'absolute', top: 0, bottom: 0, right: 0, width: 2, transition: 'background .12s'}, '&:hover::after, &:focus-visible::after': {bgcolor: 'primary.main'}}} />
		</Box>
	)

}
