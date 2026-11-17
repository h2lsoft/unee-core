import { lazy, Suspense, useCallback, useEffect, useLayoutEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { Box, Button, ButtonBase, CircularProgress, Dialog, IconButton, Tooltip, Typography } from '@mui/material'
import {
	AddOutlined, ChevronLeftOutlined, ChevronRightOutlined, CloseOutlined, DownloadOutlined, EditOutlined, FitScreenOutlined, LinkOutlined, MusicNoteOutlined, OpenInNewOutlined,
	RemoveOutlined, StarBorderOutlined, StarRounded,
} from '@mui/icons-material'
import { t } from '../../i18n'
import { FileIcon } from '../FileInput'
import DriveMarkdown from './DriveMarkdown'
import { ConfirmDialog } from './DriveDialogs'
import { driveDate, driveSize, errorMessage, isEditableImage, isText, isViewableImage, readText, type DriveFile } from './driveApi'

// editors loaded on demand (codemirror, canvas tools)
const TextEditor = lazy(() => import('./DriveTextEditor'))
const ImageEditor = lazy(() => import('./DriveImageEditor'))

const ZOOM_MAX = 8

type Info = {width?: number, height?: number, duration?: number}

// 1:05, 1:02:03
const duration = (s: number) => {

	const total = Math.round(s)
	const h = Math.floor(total / 3600)
	const m = Math.floor(total / 60) % 60
	const sec = String(total % 60).padStart(2, '0')
	return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`

}

/**
 * image with zoom: wheel around the pointer, drag to move, double click = real size / fit, buttons - fit +
 */
function ImageViewer({file, onInfo}: {file: DriveFile, onInfo: (info: Info) => void})
{

	const box = useRef<HTMLDivElement>(null)
	const [natural, setNatural] = useState<{w: number, h: number} | null>(null)
	const [room, setRoom] = useState({w: 0, h: 0})
	// null = fit in the room
	const [view, setView] = useState<{s: number, x: number, y: number} | null>(null)
	const drag = useRef<{x: number, y: number} | null>(null)

	useLayoutEffect(() => {

		const el = box.current
		if(!el)return
		const observer = new ResizeObserver(() => setRoom({w: el.clientWidth, h: el.clientHeight}))
		observer.observe(el)
		return () => observer.disconnect()

	}, [])

	const fit = natural && room.w ? Math.min(1, (room.w - 32) / natural.w, (room.h - 32) / natural.h) : 1
	const current = view ?? (natural ? {s: fit, x: (room.w - natural.w * fit) / 2, y: (room.h - natural.h * fit) / 2} : {s: 1, x: 0, y: 0})

	// zoom kept on the point (px, py) of the room
	const zoomAt = useCallback((factor: number, px: number, py: number) => {

		if(!natural)return
		const s = Math.max(fit / 2, Math.min(ZOOM_MAX, current.s * factor))
		setView({s, x: px - (px - current.x) * s / current.s, y: py - (py - current.y) * s / current.s})

	}, [natural, fit, current])

	// wheel listener not passive: the page must not scroll
	useEffect(() => {

		const el = box.current
		if(!el)return
		const wheel = (e: WheelEvent) => {

			e.preventDefault()
			const r = el.getBoundingClientRect()
			zoomAt(Math.exp(-e.deltaY * 0.0015), e.clientX - r.left, e.clientY - r.top)

		}
		el.addEventListener('wheel', wheel, {passive: false})
		return () => el.removeEventListener('wheel', wheel)

	}, [zoomAt])

	const down = (e: ReactPointerEvent) => {

		if(e.button !== 0)return
		drag.current = {x: e.clientX, y: e.clientY}
		;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)

	}

	const move = (e: ReactPointerEvent) => {

		if(!drag.current)return
		const dx = e.clientX - drag.current.x
		const dy = e.clientY - drag.current.y
		drag.current = {x: e.clientX, y: e.clientY}
		setView({...current, x: current.x + dx, y: current.y + dy})

	}

	const double = (e: React.MouseEvent) => {

		const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
		if(view && Math.abs(view.s - fit) > 0.001)setView(null)
		else zoomAt(1 / current.s, e.clientX - r.left, e.clientY - r.top)

	}

	return (
		<Box ref={box} onPointerDown={down} onPointerMove={move} onPointerUp={() => { drag.current = null }} onDoubleClick={double}
			sx={{position: 'absolute', inset: 0, overflow: 'hidden', cursor: drag.current ? 'grabbing' : 'grab', touchAction: 'none', userSelect: 'none'}}>
			{!natural && <Box sx={{position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center'}}><CircularProgress /></Box>}
			<Box component="img" src={`${file.url}?v=${file.hash}`} alt={file.name} draggable={false}
				onLoad={(e) => { const img = e.currentTarget as HTMLImageElement; setNatural({w: img.naturalWidth, h: img.naturalHeight}); setView(null); onInfo({width: img.naturalWidth, height: img.naturalHeight}) }}
				sx={{position: 'absolute', left: current.x, top: current.y, width: natural ? natural.w * current.s : 'auto', height: natural ? natural.h * current.s : 'auto', maxWidth: 'none', visibility: natural ? 'visible' : 'hidden',
					imageRendering: (current.s > 2) ? 'pixelated' : 'auto', boxShadow: '0 2px 16px var(--u-shadow)',
					backgroundImage: 'conic-gradient(var(--u-border) 25%, var(--u-paper) 0 50%, var(--u-border) 0 75%, var(--u-paper) 0)', backgroundSize: '16px 16px'}} />
			{natural && (
				<Box onPointerDown={(e) => e.stopPropagation()} onDoubleClick={(e) => e.stopPropagation()}
					sx={{position: 'absolute', right: 16, bottom: 16, display: 'flex', alignItems: 'center', gap: 0.25, px: 0.5, py: 0.25, borderRadius: 2, bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', boxShadow: '0 2px 8px var(--u-shadow)'}}>
					<IconButton size="small" aria-label={t('Zoom out')} onClick={() => zoomAt(1 / 1.25, room.w / 2, room.h / 2)}><RemoveOutlined sx={{fontSize: 18}} /></IconButton>
					<ButtonBase onClick={() => setView(null)} title={t('Fit to the window')} sx={{minWidth: 52, height: 28, borderRadius: 1, fontSize: 12.5, fontVariantNumeric: 'tabular-nums'}}>{Math.round(current.s * 100)} %</ButtonBase>
					<IconButton size="small" aria-label={t('Zoom in')} onClick={() => zoomAt(1.25, room.w / 2, room.h / 2)}><AddOutlined sx={{fontSize: 18}} /></IconButton>
					<IconButton size="small" aria-label={t('Fit to the window')} onClick={() => setView(null)}><FitScreenOutlined sx={{fontSize: 18}} /></IconButton>
				</Box>
			)}
		</Box>
	)

}

/**
 * beginning of a text file, markdown shown as a page (source on demand)
 */
function TextViewer({file}: {file: DriveFile})
{

	const [text, setText] = useState<string | null>(null)
	const [error, setError] = useState('')
	const [source, setSource] = useState(false)

	useEffect(() => {

		let alive = true
		readText(file).then((v) => alive && setText(v)).catch((e) => alive && setError(errorMessage(e)))
		return () => { alive = false }

	}, [file])

	if(error)return <Box sx={{p: 4, color: 'var(--u-error)'}}>{error}</Box>
	if(text === null)return <Box sx={{display: 'flex', justifyContent: 'center', p: 6}}><CircularProgress /></Box>

	return (
		<Box sx={{position: 'absolute', inset: 0, overflow: 'auto', bgcolor: 'var(--u-paper)'}}>
			{file.extension === 'md' && (
				<Button size="small" variant="outlined" color="inherit" onClick={() => setSource(!source)} sx={{position: 'sticky', top: 12, float: 'right', mr: 2, height: 28, zIndex: 1, bgcolor: 'var(--u-paper)', borderColor: 'var(--u-border-strong)'}}>
					{source ? t('Show the page') : t('Show the source')}
				</Button>
			)}
			{(file.extension === 'md' && !source)
				? <DriveMarkdown text={text} />
				: <Box component="pre" sx={{m: 0, p: '20px 24px', fontSize: 13, lineHeight: 1.6, fontFamily: 'ui-monospace, Menlo, Consolas, monospace', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', color: 'var(--u-text)'}}>{text}</Box>}
		</Box>
	)

}

/**
 * preview lightbox of the files of the list: image (zoom), pdf, video, audio, text and markdown; previous / next (arrows, keys)
 * actions: download, edit (text and images, write right), favorite, open in a new tab; infos at the bottom
 * edit mode: text editor or image editor in the same lightbox, a change not saved is confirmed before leaving
 */
export default function DrivePreview({files, start, edit = false, editable, avif = false, onClose, onFavorite, onDownload, onSaved, onShare}: {
	files: DriveFile[]
	start: number
	// opened in edit mode (menu Edit)
	edit?: boolean
	// write right in the place shown (the api checks it again)
	editable: boolean
	// the image editor can save in avif
	avif?: boolean
	onClose: () => void
	onFavorite: (file: DriveFile) => void
	onDownload: (file: DriveFile) => void
	onSaved: (file: DriveFile, copy: boolean) => void
	// share by link (not in the picker)
	onShare?: (file: DriveFile) => void
})
{

	const [id, setId] = useState(files[start]?.id ?? 0)
	const [mode, setMode] = useState<'view' | 'edit'>(edit ? 'edit' : 'view')
	const [info, setInfo] = useState<Info>({})
	const [dirty, setDirty] = useState(false)
	const [leaving, setLeaving] = useState<(() => void) | null>(null)

	const index = Math.max(0, files.findIndex((f) => f.id === id))
	const file = files[index]
	const can_edit = editable && !!file && (isText(file) || isEditableImage(file))

	// a change not saved: confirmation before closing, going to another file or leaving the page
	const guard = (action: () => void) => { if(mode === 'edit' && dirty)setLeaving(() => action); else action() }
	const go = (delta: number) => guard(() => { const next = files[(index + delta + files.length) % files.length]; if(next){ setId(next.id); setInfo({}); setMode('view'); setDirty(false) } })
	const close = () => guard(onClose)

	useEffect(() => {

		if(!dirty)return
		const unload = (e: BeforeUnloadEvent) => e.preventDefault()
		window.addEventListener('beforeunload', unload)
		return () => window.removeEventListener('beforeunload', unload)

	}, [dirty])

	const onDirty = useCallback((value: boolean) => setDirty(value), [])

	if(!file)return null

	const keyDown = (e: React.KeyboardEvent) => {

		// nothing reaches the File Manager under the lightbox
		e.stopPropagation()
		const target = e.target as HTMLElement
		if(mode === 'edit' || ['INPUT', 'TEXTAREA'].includes(target.tagName) || target.isContentEditable)return
		if(e.key === 'ArrowLeft' && files.length > 1){ e.preventDefault(); go(-1) }
		else if(e.key === 'ArrowRight' && files.length > 1){ e.preventDefault(); go(1) }

	}

	const kind = isViewableImage(file) ? 'image' : ((file.extension === 'pdf') ? 'pdf' : (file.mime.startsWith('video/') ? 'video' : (file.mime.startsWith('audio/') ? 'audio' : (isText(file) ? 'text' : 'other'))))
	const media = (e: React.SyntheticEvent<HTMLVideoElement | HTMLAudioElement>) => {

		const el = e.currentTarget
		setInfo({duration: el.duration, ...((el instanceof HTMLVideoElement && el.videoWidth) ? {width: el.videoWidth, height: el.videoHeight} : {})})

	}

	const action = (label: string, icon: React.ReactNode, onClick: () => void, href?: string) => (
		<Tooltip title={label}>
			<IconButton aria-label={label} onClick={onClick} {...(href ? {component: 'a', href, target: '_blank', rel: 'noopener'} : {})} sx={{color: 'var(--u-text2)'}}>{icon}</IconButton>
		</Tooltip>
	)

	const details = [
		driveSize(file.size),
		(info.width && info.height) ? `${info.width} x ${info.height} px` : '',
		info.duration && Number.isFinite(info.duration) ? duration(info.duration) : '',
		t('modified on [DATE]', {DATE: driveDate(file.date)}) + (file.by ? ` ${t('by [NAME]', {NAME: file.by.name})}` : ''),
	].filter(Boolean).join(' - ')

	return (
		<Dialog open onClose={(_, reason) => reason !== 'backdropClick' && close()} maxWidth={false} fullWidth onKeyDown={keyDown}
			slotProps={{paper: {sx: {width: '96vw', maxWidth: 1600, height: '94vh', m: 0, display: 'flex', flexDirection: 'column', overflow: 'hidden', borderRadius: '10px'}}}}>

			{/* title and actions */}
			<Box sx={{display: 'flex', alignItems: 'center', gap: 1, pl: 2.5, pr: 1, minHeight: 56, borderBottom: '1px solid var(--u-border)'}}>
				<FileIcon name={file.name} size={22} />
				<Typography component="h2" sx={{flex: 1, minWidth: 0, fontSize: 16, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>
					{file.name}
					{mode === 'edit' && <Box component="span" sx={{ml: 1, fontWeight: 400, color: 'var(--u-muted)'}}>- {t('editing')}</Box>}
				</Typography>
				{files.length > 1 && mode === 'view' && <Box component="span" sx={{fontSize: 12.5, color: 'var(--u-muted)', mr: 1, fontVariantNumeric: 'tabular-nums'}}>{index + 1} / {files.length}</Box>}
				{can_edit && mode === 'view' && <Button variant="outlined" startIcon={<EditOutlined />} onClick={() => setMode('edit')} sx={{height: 32, mr: 0.5}}>{isText(file) ? t('Edit') : t('Edit the image')}</Button>}
				{mode === 'edit' && <Button variant="outlined" color="inherit" onClick={() => guard(() => { setMode('view'); setDirty(false) })} sx={{height: 32, mr: 0.5, borderColor: 'var(--u-border-strong)'}}>{t('Back to the preview')}</Button>}
				{action(t('Download'), <DownloadOutlined />, () => onDownload(file))}
				{onShare && action(t('Share by link...'), <LinkOutlined />, () => onShare(file))}
				{action(file.favorite ? t('Remove from favorites') : t('Add to favorites'), file.favorite ? <StarRounded sx={{color: '#e0a400'}} /> : <StarBorderOutlined />, () => onFavorite(file))}
				{action(t('Open in a new tab'), <OpenInNewOutlined />, () => {}, file.url)}
				<IconButton aria-label={t('Close')} onClick={close}><CloseOutlined /></IconButton>
			</Box>

			{/* content */}
			{mode === 'edit'
				? <Suspense fallback={<Box sx={{flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center'}}><CircularProgress /></Box>}>
					{isText(file) ? <TextEditorLoader file={file} onSaved={onSaved} onDirty={onDirty} /> : <ImageEditor key={file.id} file={file} avif={avif} onSaved={onSaved} onDirty={onDirty} />}
				</Suspense>
				: (
					<Box sx={{flex: 1, minHeight: 0, position: 'relative', bgcolor: (kind === 'image' || kind === 'video') ? 'var(--u-head)' : 'var(--u-paper)'}}>
						{kind === 'image' && <ImageViewer key={file.id} file={file} onInfo={setInfo} />}
						{kind === 'pdf' && <Box component="iframe" key={file.id} src={file.url} title={file.name} sx={{position: 'absolute', inset: 0, width: '100%', height: '100%', border: 0}} />}
						{kind === 'video' && (
							<Box sx={{position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', p: 2}}>
								<Box component="video" key={file.id} src={file.url} controls autoPlay preload="metadata" onLoadedMetadata={media} sx={{maxWidth: '100%', maxHeight: '100%', borderRadius: 1, bgcolor: 'common.black'}} />
							</Box>
						)}
						{kind === 'audio' && (
							<Box sx={{position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 3}}>
								<Box sx={{width: 160, height: 160, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', bgcolor: 'var(--u-accent-bg)', color: 'var(--u-accent-text)'}}><MusicNoteOutlined sx={{fontSize: 80}} /></Box>
								<Box component="audio" key={file.id} src={file.url} controls autoPlay preload="metadata" onLoadedMetadata={media} sx={{width: 'min(520px, 90%)'}} />
							</Box>
						)}
						{kind === 'text' && <TextViewer key={file.id} file={file} />}
						{kind === 'other' && (
							<Box sx={{position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 2}}>
								<FileIcon name={file.name} size={96} />
								<Typography sx={{color: 'var(--u-muted)'}}>{t('No preview for this type of file')}</Typography>
								<Button variant="contained" startIcon={<DownloadOutlined />} onClick={() => onDownload(file)}>{t('Download')}</Button>
							</Box>
						)}

						{files.length > 1 && <>
							<IconButton aria-label={t('Previous file')} onClick={() => go(-1)} sx={{position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', width: 44, height: 44, bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', boxShadow: '0 2px 8px var(--u-shadow)', '&:hover': {bgcolor: 'var(--u-hover)'}}}><ChevronLeftOutlined /></IconButton>
							<IconButton aria-label={t('Next file')} onClick={() => go(1)} sx={{position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', width: 44, height: 44, bgcolor: 'var(--u-paper)', border: '1px solid var(--u-border)', boxShadow: '0 2px 8px var(--u-shadow)', '&:hover': {bgcolor: 'var(--u-hover)'}}}><ChevronRightOutlined /></IconButton>
						</>}
					</Box>
				)}

			{/* infos */}
			{mode === 'view' && <Box sx={{px: 2.5, py: 1, borderTop: '1px solid var(--u-border)', bgcolor: 'var(--u-toolbar)', fontSize: 12.5, color: 'var(--u-text3)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis'}}>{details}</Box>}

			<ConfirmDialog open={!!leaving} title={t('Changes not saved')} message={t('Your changes will be lost.')} label={t('Leave without saving')} danger
				onClose={() => setLeaving(null)} onConfirm={async () => { const next = leaving; setLeaving(null); setDirty(false); next?.() }} />
		</Dialog>
	)

}

// text read once for the editor (the editor keeps it)
function TextEditorLoader({file, onSaved, onDirty}: {file: DriveFile, onSaved: (file: DriveFile, copy: boolean) => void, onDirty: (dirty: boolean) => void})
{

	const [text, setText] = useState<string | null>(null)
	const [error, setError] = useState('')

	useEffect(() => {

		let alive = true
		readText(file).then((v) => alive && setText(v)).catch((e) => alive && setError(errorMessage(e)))
		return () => { alive = false }

	// read once: the editor follows the saves itself
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [file.id])

	if(error)return <Box sx={{p: 4, color: 'var(--u-error)'}}>{error}</Box>
	if(text === null)return <Box sx={{flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center'}}><CircularProgress /></Box>
	return <TextEditor file={file} text={text} onSaved={onSaved} onDirty={onDirty} />

}
