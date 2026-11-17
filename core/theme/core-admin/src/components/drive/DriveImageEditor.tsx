import { useCallback, useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react'
import { Box, Button, ButtonBase, CircularProgress, Divider, IconButton, InputBase, MenuItem, Select, Slider, Tooltip } from '@mui/material'
import {
	BlurOnOutlined, CheckOutlined, CloseOutlined, CropOutlined, CropSquareOutlined, FlipOutlined, NorthEastOutlined, PhotoSizeSelectLargeOutlined, RedoOutlined,
	RotateLeftOutlined, RotateRightOutlined, TitleOutlined, TuneOutlined, UndoOutlined,
} from '@mui/icons-material'
import { t } from '../../i18n'
import { driveApi, driveSize, errorMessage, errorReason, type DriveFile } from './driveApi'
import { NO_ADJUST, adjust, crop, cssFilter, draw, drawShape, flip, fromImage, normalize, pixelate, resize, rotate, toBlob, type Adjust, type Rect, type Shape } from './imageOps'

type Tool = 'none' | 'crop' | 'resize' | 'adjust' | 'text' | 'arrow' | 'rect' | 'blur'
type Drag = {kind: 'new' | 'move', x: number, y: number, rect: Rect} | null

// colors of the annotations: drawn in the image itself, not colors of the interface
const COLORS = ['#e53935', '#fb8c00', '#fdd835', '#43a047', '#1e88e5', '#8e24aa', '#ffffff', '#111111']
const RATIOS: {label: string, value: number}[] = [{label: 'Free', value: 0}, {label: '1:1', value: 1}, {label: '4:3', value: 4 / 3}, {label: '16:9', value: 16 / 9}, {label: '3:4', value: 3 / 4}, {label: '9:16', value: 9 / 16}]
// avif: no browser writes it from a canvas, a lossless png is sent and the server converts it (gd)
const FORMATS: Record<string, {label: string, mime: string, quality: boolean}> = {jpg: {label: 'JPG', mime: 'image/jpeg', quality: true}, jpeg: {label: 'JPG', mime: 'image/jpeg', quality: true}, png: {label: 'PNG', mime: 'image/png', quality: false}, webp: {label: 'WebP', mime: 'image/webp', quality: true}, avif: {label: 'AVIF', mime: 'image/png', quality: true}}
// canvases kept for the undo (memory: 4 bytes by pixel each)
const HISTORY_MAX = 15

/**
 * image editor of the drive in the lightbox: rotation, flip, crop, resize, brightness / contrast / saturation, black and white, sepia, text, arrow, rectangle, pixelated zone
 * every operation gives a new canvas (undo / redo), the file is written by the browser (jpg, png, webp) then sent: replace or copy, exif left out
 */
export default function DriveImageEditor({file, avif, onSaved, onDirty}: {file: DriveFile, avif: boolean, onSaved: (file: DriveFile, copy: boolean) => void, onDirty: (dirty: boolean) => void})
{

	// result of the last save, shown in the toolbar (a toast would cover the buttons of the lightbox)
	const [note, setNote] = useState('')
	useEffect(() => {

		if(!note)return
		const timer = setTimeout(() => setNote(''), 5000)
		return () => clearTimeout(timer)

	}, [note])
	const [history, setHistory] = useState<HTMLCanvasElement[]>([])
	const [step, setStep] = useState(0)
	const [saved, setSaved] = useState(0)
	const [error, setError] = useState('')
	const [busy, setBusy] = useState(false)
	const [conflict, setConflict] = useState(false)
	const [base, setBase] = useState(file.hash)

	const [tool, setTool] = useState<Tool>('none')
	const [ratio, setRatio] = useState(0)
	const [area, setArea] = useState<Rect | null>(null)
	const [drag, setDrag] = useState<Drag>(null)
	const [shape, setShape] = useState<Shape | null>(null)
	const [color, setColor] = useState(COLORS[0])
	const [thickness, setThickness] = useState(2)
	const [draft, setDraft] = useState<{x: number, y: number, text: string} | null>(null)
	const [tune, setTune] = useState<Adjust>(NO_ADJUST)
	const [size, setSize] = useState({w: '', h: '', lock: true})
	const [format, setFormat] = useState((FORMATS[file.extension] && (file.extension !== 'avif' || avif)) ? file.extension : 'png')
	const [quality, setQuality] = useState(90)

	const box = useRef<HTMLDivElement>(null)
	const view = useRef<HTMLCanvasElement>(null)
	const overlay = useRef<HTMLCanvasElement>(null)
	const [room, setRoom] = useState({w: 800, h: 500})

	const current = history[step] ?? null
	const width = current?.width ?? 0
	const height = current?.height ?? 0
	const dirty = (step !== saved) || (history.length > 0 && format !== file.extension && !(format === 'jpg' && file.extension === 'jpeg'))
	useEffect(() => { onDirty(dirty) }, [dirty, onDirty])

	// image of the file, read again without cache
	useEffect(() => {

		const image = new Image()
		image.onload = () => { setHistory([fromImage(image)]); setStep(0); setSaved(0) }
		image.onerror = () => setError(t('This image cannot be read by the browser.'))
		image.src = `${file.url}?v=${file.hash}`

	}, [file.url, file.hash])

	// room of the image: the canvas is scaled down to fit, never enlarged
	useLayoutEffect(() => {

		const el = box.current
		if(!el)return
		const observer = new ResizeObserver(() => setRoom({w: el.clientWidth - 32, h: el.clientHeight - 32}))
		observer.observe(el)
		return () => observer.disconnect()

	}, [])

	const scale = width ? Math.min(1, room.w / width, room.h / height) : 1
	const lineWidth = Math.max(2, Math.round(Math.max(width, height) / 400)) * thickness
	const fontSize = Math.max(14, Math.round(Math.max(width, height) / 30)) * thickness / 2

	// current canvas shown, live filter while tuning
	useEffect(() => {

		const canvas = view.current
		if(!canvas || !current)return
		canvas.width = current.width
		canvas.height = current.height
		canvas.getContext('2d')?.drawImage(current, 0, 0)

	}, [current])

	// overlay: shape being drawn, crop frame (outside darkened), zone to pixelate
	useEffect(() => {

		const canvas = overlay.current
		if(!canvas || !width)return
		canvas.width = width
		canvas.height = height
		const ctx = canvas.getContext('2d')
		if(!ctx)return
		ctx.clearRect(0, 0, width, height)

		const frame = (r: Rect, dark: boolean) => {

			const n = normalize(r, width, height)
			if(dark)
			{

				ctx.fillStyle = 'rgba(0, 0, 0, .5)'
				ctx.fillRect(0, 0, width, height)
				ctx.clearRect(n.x, n.y, n.w, n.h)

			}
			ctx.setLineDash([Math.max(4, width / 150), Math.max(4, width / 150)])
			ctx.lineWidth = Math.max(1, Math.round(1.5 / scale))
			ctx.strokeStyle = '#fff'
			ctx.strokeRect(n.x, n.y, n.w, n.h)
			ctx.setLineDash([])

		}

		if((tool === 'crop' || tool === 'blur') && area)frame(area, tool === 'crop')
		if(shape)drawShape(ctx, shape)

	}, [area, shape, tool, width, height, scale])

	// history: the steps after the current one are dropped, the oldest ones over HISTORY_MAX
	const push = useCallback((next: HTMLCanvasElement) => {

		const kept = [...history.slice(0, step + 1), next]
		const over = Math.max(0, kept.length - HISTORY_MAX)
		setHistory(kept.slice(over))
		setStep(kept.length - over - 1)
		if(over)setSaved((s) => s - over)

	}, [history, step])

	const apply = (operation: (source: HTMLCanvasElement) => HTMLCanvasElement) => {

		if(!current)return
		try
		{

			push(operation(current))

		}
		catch
		{

			setError(t('This operation failed: the image is too large for the browser.'))

		}

	}

	const choose = (next: Tool) => {

		setTool((old) => (old === next) ? 'none' : next)
		setArea(null)
		setShape(null)
		setDraft(null)
		setTune(NO_ADJUST)
		if(next === 'resize')setSize({w: String(width), h: String(height), lock: true})

	}

	const undo = () => { if(step > 0){ setStep(step - 1); setArea(null) } }
	const redo = () => { if(step < history.length - 1){ setStep(step + 1); setArea(null) } }

	// pointer on the image, in pixels of the image
	const point = (e: ReactPointerEvent) => {

		const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
		return {x: Math.max(0, Math.min(width, (e.clientX - r.left) * width / r.width)), y: Math.max(0, Math.min(height, (e.clientY - r.top) * height / r.height))}

	}

	// rectangle from the start point, ratio kept for the crop
	const rectTo = (x0: number, y0: number, x: number, y: number): Rect => {

		let w = x - x0
		let h = y - y0
		if(tool === 'crop' && ratio)
		{

			const sign = (v: number) => (v < 0) ? -1 : 1
			if(Math.abs(w) / ratio > Math.abs(h))h = sign(h) * Math.abs(w) / ratio
			else w = sign(w) * Math.abs(h) * ratio
			// kept in the image
			const maxW = (w < 0) ? x0 : width - x0
			const maxH = (h < 0) ? y0 : height - y0
			const k = Math.min(1, maxW / Math.abs(w || 1), maxH / Math.abs(h || 1))
			w *= k
			h *= k

		}
		return {x: x0, y: y0, w, h}

	}

	const down = (e: ReactPointerEvent) => {

		if(tool === 'none' || tool === 'resize' || tool === 'adjust' || !width)return
		const p = point(e)
		;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)

		if(tool === 'text')
		{

			// no focus change by the browser: the input of the text keeps it
			e.preventDefault()
			if(draft?.text.trim())commitText()
			setDraft({x: p.x, y: p.y, text: ''})
			return

		}

		if(tool === 'crop' && area)
		{

			const n = normalize(area, width, height)
			if(p.x > n.x && p.x < n.x + n.w && p.y > n.y && p.y < n.y + n.h){ setDrag({kind: 'move', x: p.x, y: p.y, rect: n}); return }

		}

		setDrag({kind: 'new', x: p.x, y: p.y, rect: {x: p.x, y: p.y, w: 0, h: 0}})
		if(tool === 'arrow')setShape({kind: 'arrow', x1: p.x, y1: p.y, x2: p.x, y2: p.y, color, width: lineWidth})
		if(tool === 'rect')setShape({kind: 'rect', x: p.x, y: p.y, w: 0, h: 0, color, width: lineWidth})

	}

	const move = (e: ReactPointerEvent) => {

		if(!drag)return
		const p = point(e)

		if(drag.kind === 'move')
		{

			const r = drag.rect
			setArea({x: Math.max(0, Math.min(width - r.w, r.x + p.x - drag.x)), y: Math.max(0, Math.min(height - r.h, r.y + p.y - drag.y)), w: r.w, h: r.h})
			return

		}

		const r = rectTo(drag.x, drag.y, p.x, p.y)
		if(tool === 'crop' || tool === 'blur')setArea(r)
		if(tool === 'arrow')setShape({kind: 'arrow', x1: drag.x, y1: drag.y, x2: p.x, y2: p.y, color, width: lineWidth})
		if(tool === 'rect')setShape({kind: 'rect', ...r, color, width: lineWidth})

	}

	const up = () => {

		if(!drag)return
		setDrag(null)

		// a click without moving draws nothing
		if(shape && ((shape.kind === 'arrow' && Math.hypot(shape.x2 - shape.x1, shape.y2 - shape.y1) > 4) || (shape.kind === 'rect' && Math.abs(shape.w) > 3 && Math.abs(shape.h) > 3)))
		{

			const s = (shape.kind === 'rect') ? {...shape, ...normalize(shape, width, height)} : shape
			apply((c) => draw(c, s))

		}
		setShape(null)

		if(tool === 'blur' && area && Math.abs(area.w) > 3 && Math.abs(area.h) > 3)
		{

			const zone = area
			apply((c) => pixelate(c, zone))
			setArea(null)

		}

	}

	const commitText = () => {

		if(!draft?.text.trim())return setDraft(null)
		const s: Shape = {kind: 'text', x: draft.x, y: draft.y, text: draft.text, color, size: fontSize}
		apply((c) => draw(c, s))
		setDraft(null)

	}

	const applyCrop = () => {

		if(!area || Math.abs(area.w) < 2 || Math.abs(area.h) < 2)return
		const r = area
		apply((c) => crop(c, r))
		setArea(null)

	}

	const applyResize = () => {

		const w = parseInt(size.w, 10)
		const h = parseInt(size.h, 10)
		if(!(w > 0 && h > 0) || (w === width && h === height))return
		if(w * h > 100000000)return setError(t('The image would be too large.'))
		apply((c) => resize(c, w, h))
		setTool('none')

	}

	const applyTune = () => {

		const a = tune
		apply((c) => adjust(c, a))
		setTune(NO_ADJUST)
		setTool('none')

	}

	// file written by the browser then sent
	const save = async (mode: 'replace' | 'copy', force = false) => {

		if(!current || busy)return
		setBusy(true)
		setError('')

		try
		{

			const kind = FORMATS[format]
			const blob = await toBlob(current, kind.mime, quality / 100)
			if(!blob || blob.type !== kind.mime)throw new Error(t('This browser cannot write the format [FORMAT].', {FORMAT: kind.label}))

			const r = await driveApi.saveContent(file.id, {blob, extension: format, quality: (format === 'avif') ? quality : undefined, mode, base, force})
			if(mode === 'replace')
			{

				setSaved(step)
				setBase(r.file.hash)

			}
			setConflict(false)
			setNote((mode === 'copy') ? t('Copy saved as [NAME]', {NAME: r.file.name}) : t('Saved'))
			onSaved(r.file, mode === 'copy')

		}
		catch(e)
		{

			if(errorReason(e) === 'changed')setConflict(true)
			else setError((e instanceof Error && !('error' in e)) ? e.message : errorMessage(e))

		}
		finally
		{

			setBusy(false)

		}

	}

	// keys of the editor: undo, redo, Enter applies the crop (never while typing a text)
	const keyDown = (e: KeyboardEvent) => {

		if((e.target as HTMLElement).tagName === 'INPUT')return
		const ctrl = e.ctrlKey || e.metaKey
		if(ctrl && e.key.toLowerCase() === 'z'){ e.preventDefault(); if(e.shiftKey)redo(); else undo() }
		else if(ctrl && e.key.toLowerCase() === 'y'){ e.preventDefault(); redo() }
		else if(e.key === 'Enter' && tool === 'crop'){ e.preventDefault(); applyCrop() }

	}

	const toolButton = (name: Tool | '', label: string, icon: ReactNode, onClick: () => void, disabled = false) => (
		<Tooltip title={label} key={label}>
			<span>
				<IconButton aria-label={label} aria-pressed={name ? tool === name : undefined} disabled={disabled || !current} onClick={onClick}
					sx={{borderRadius: 2, width: 36, height: 36, color: (name && tool === name) ? 'var(--u-accent-text)' : 'var(--u-text2)', bgcolor: (name && tool === name) ? 'var(--u-accent-bg)' : 'transparent'}}>{icon}</IconButton>
			</span>
		</Tooltip>
	)

	const icon = {fontSize: 20}
	const chip = (on: boolean) => ({height: 28, px: 1.25, borderRadius: 1.5, fontSize: 13, fontWeight: on ? 600 : 400, border: '1px solid', borderColor: on ? 'var(--u-accent-border)' : 'var(--u-border-strong)', bgcolor: on ? 'var(--u-accent-bg)' : 'transparent', color: on ? 'var(--u-accent-text)' : 'var(--u-text2)'})
	const slider = (label: string, key: 'brightness' | 'contrast' | 'saturation') => (
		<Box sx={{display: 'flex', alignItems: 'center', gap: 1.25, width: 230}}>
			<Box component="span" sx={{fontSize: 13, color: 'var(--u-text3)', width: 82}}>{label}</Box>
			<Slider size="small" min={0} max={200} value={tune[key]} onChange={(_, v) => setTune({...tune, [key]: v as number})} aria-label={label} sx={{flex: 1}} />
			<Box component="span" sx={{fontSize: 12, width: 34, textAlign: 'right', fontVariantNumeric: 'tabular-nums'}}>{tune[key]}</Box>
		</Box>
	)

	// options of the tool chosen
	let options: ReactNode = null
	if(tool === 'crop')options = <>
		<Box component="span" sx={{fontSize: 13, color: 'var(--u-text3)'}}>{t('Ratio')}</Box>
		{RATIOS.map((r) => <ButtonBase key={r.label} onClick={() => { setRatio(r.value); setArea(null) }} sx={chip(ratio === r.value)}>{r.value ? r.label : t('Free')}</ButtonBase>)}
		<Box sx={{flex: 1, fontSize: 12.5, color: 'var(--u-muted)'}}>{area ? `${normalize(area, width, height).w} x ${normalize(area, width, height).h} px` : t('Draw the frame on the image')}</Box>
		<Button variant="contained" size="small" startIcon={<CheckOutlined />} disabled={!area} onClick={applyCrop} sx={{height: 30}}>{t('Crop')}</Button>
	</>
	else if(tool === 'resize')
	{

		const ratioWh = width / (height || 1)
		const set = (key: 'w' | 'h', v: string) => {

			const n = parseInt(v.replace(/\D/g, ''), 10)
			if(!size.lock || !n)return setSize({...size, [key]: v.replace(/\D/g, '')})
			setSize((key === 'w') ? {...size, w: String(n), h: String(Math.max(1, Math.round(n / ratioWh)))} : {...size, h: String(n), w: String(Math.max(1, Math.round(n * ratioWh)))})

		}
		const input = (key: 'w' | 'h', label: string) => <InputBase value={size[key]} onChange={(e) => set(key, e.target.value)} inputProps={{'aria-label': label, inputMode: 'numeric', style: {textAlign: 'center'}}}
			sx={{width: 84, height: 30, px: 1, fontSize: 13, border: '1px solid var(--u-border-strong)', borderRadius: 1.5, bgcolor: 'var(--u-paper)'}} />
		options = <>
			{input('w', t('Width'))}<Box component="span" sx={{color: 'var(--u-muted)'}}>x</Box>{input('h', t('Height'))}<Box component="span" sx={{fontSize: 13, color: 'var(--u-text3)'}}>px</Box>
			<ButtonBase onClick={() => setSize({...size, lock: !size.lock})} sx={chip(size.lock)}>{t('Keep the proportions')}</ButtonBase>
			{[75, 50, 25].map((p) => <ButtonBase key={p} onClick={() => setSize({w: String(Math.round(width * p / 100)), h: String(Math.round(height * p / 100)), lock: true})} sx={chip(false)}>{p} %</ButtonBase>)}
			<Box sx={{flex: 1}} />
			<Button variant="contained" size="small" startIcon={<CheckOutlined />} onClick={applyResize} sx={{height: 30}}>{t('Resize')}</Button>
		</>

	}
	else if(tool === 'adjust')options = <>
		{slider(t('Brightness'), 'brightness')}
		{slider(t('Contrast'), 'contrast')}
		{slider(t('Saturation'), 'saturation')}
		{(['none', 'grayscale', 'sepia'] as const).map((f) => <ButtonBase key={f} onClick={() => setTune({...tune, filter: f})} sx={chip(tune.filter === f)}>{{none: t('No filter'), grayscale: t('Black and white'), sepia: t('Sepia')}[f]}</ButtonBase>)}
		<Box sx={{flex: 1}} />
		<Button size="small" color="inherit" onClick={() => setTune(NO_ADJUST)} sx={{height: 30}}>{t('Reset')}</Button>
		<Button variant="contained" size="small" startIcon={<CheckOutlined />} onClick={applyTune} sx={{height: 30}}>{t('Apply')}</Button>
	</>
	else if(tool === 'text' || tool === 'arrow' || tool === 'rect' || tool === 'blur')options = <>
		{tool !== 'blur' && <>
			{COLORS.map((c) => <ButtonBase key={c} aria-label={c} onClick={() => setColor(c)} sx={{width: 24, height: 24, borderRadius: '50%', bgcolor: c, border: '2px solid', borderColor: (color === c) ? 'primary.main' : 'var(--u-border-strong)', boxShadow: (color === c) ? '0 0 0 2px var(--u-paper) inset' : 'none'}} />)}
			<Divider orientation="vertical" flexItem sx={{mx: 0.5}} />
			{[1, 2, 3].map((n) => <ButtonBase key={n} onClick={() => setThickness(n)} sx={chip(thickness === n)}>{{1: t('Thin'), 2: t('Medium'), 3: t('Thick')}[n]}</ButtonBase>)}
		</>}
		<Box sx={{flex: 1, fontSize: 12.5, color: 'var(--u-muted)'}}>{{text: t('Click on the image to write, Enter to place the text'), arrow: t('Drag from the start to the tip of the arrow'), rect: t('Drag to draw the rectangle'), blur: t('Drag over the zone to pixelate (faces, plates, personal data)')}[tool]}</Box>
	</>

	const kind = FORMATS[format]
	const cursor = (tool === 'none' || tool === 'resize' || tool === 'adjust') ? 'default' : ((tool === 'text') ? 'text' : 'crosshair')

	return (
		<Box onKeyDown={keyDown} sx={{flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column'}}>
			{/* tools */}
			<Box sx={{display: 'flex', alignItems: 'center', gap: 0.25, flexWrap: 'wrap', px: 1.5, py: 0.5, borderBottom: '1px solid var(--u-border)', bgcolor: 'var(--u-toolbar)'}}>
				{toolButton('', t('Undo (Ctrl+Z)'), <UndoOutlined sx={icon} />, undo, step === 0)}
				{toolButton('', t('Redo (Ctrl+Y)'), <RedoOutlined sx={icon} />, redo, step >= history.length - 1)}
				<Divider orientation="vertical" flexItem sx={{mx: 0.75, my: 0.75}} />
				{toolButton('', t('Rotate left'), <RotateLeftOutlined sx={icon} />, () => apply((c) => rotate(c, -1)))}
				{toolButton('', t('Rotate right'), <RotateRightOutlined sx={icon} />, () => apply((c) => rotate(c, 1)))}
				{toolButton('', t('Flip horizontally'), <FlipOutlined sx={icon} />, () => apply((c) => flip(c, true)))}
				{toolButton('', t('Flip vertically'), <FlipOutlined sx={{...icon, transform: 'rotate(90deg)'}} />, () => apply((c) => flip(c, false)))}
				<Divider orientation="vertical" flexItem sx={{mx: 0.75, my: 0.75}} />
				{toolButton('crop', t('Crop'), <CropOutlined sx={icon} />, () => choose('crop'))}
				{toolButton('resize', t('Resize'), <PhotoSizeSelectLargeOutlined sx={icon} />, () => choose('resize'))}
				{toolButton('adjust', t('Brightness, contrast, filters'), <TuneOutlined sx={icon} />, () => choose('adjust'))}
				<Divider orientation="vertical" flexItem sx={{mx: 0.75, my: 0.75}} />
				{toolButton('text', t('Text'), <TitleOutlined sx={icon} />, () => choose('text'))}
				{toolButton('arrow', t('Arrow'), <NorthEastOutlined sx={icon} />, () => choose('arrow'))}
				{toolButton('rect', t('Rectangle'), <CropSquareOutlined sx={icon} />, () => choose('rect'))}
				{toolButton('blur', t('Pixelate a zone'), <BlurOnOutlined sx={icon} />, () => choose('blur'))}
				<Box sx={{flex: 1}} />
				{note && <Box component="span" role="status" sx={{display: 'inline-flex', alignItems: 'center', gap: 0.5, mr: 1.5, fontSize: 12.5, fontWeight: 500, color: 'var(--u-green)'}}><CheckOutlined sx={{fontSize: 16}} />{note}</Box>}
				<Box component="span" sx={{fontSize: 12.5, color: 'var(--u-muted)', mr: 1, fontVariantNumeric: 'tabular-nums'}}>{width ? `${width} x ${height} px` : ''}</Box>
				<Select size="small" value={format} onChange={(e) => setFormat(e.target.value)} inputProps={{'aria-label': t('Image format')}} sx={{height: 32, fontSize: 13, minWidth: 84, bgcolor: 'var(--u-paper)'}}>
					{Object.entries(FORMATS).filter(([ext]) => ext !== ((file.extension === 'jpeg') ? 'jpg' : 'jpeg') && (ext !== 'avif' || avif)).map(([ext, f]) => <MenuItem key={ext} value={ext} sx={{fontSize: 13}}>{f.label}</MenuItem>)}
				</Select>
				{kind.quality && (
					<Box sx={{display: 'flex', alignItems: 'center', gap: 1, width: 170, ml: 1.5}}>
						<Box component="span" sx={{fontSize: 12.5, color: 'var(--u-text3)'}}>{t('Quality')}</Box>
						<Slider size="small" min={40} max={100} value={quality} onChange={(_, v) => setQuality(v as number)} aria-label={t('Quality')} sx={{flex: 1}} />
						<Box component="span" sx={{fontSize: 12, width: 26, fontVariantNumeric: 'tabular-nums'}}>{quality}</Box>
					</Box>
				)}
				<Button variant="outlined" color="inherit" disabled={busy || !current} onClick={() => save('copy')} sx={{ml: 1.5, height: 32, borderColor: 'var(--u-border-strong)', color: 'var(--u-text2)', fontWeight: 500}}>{t('Save a copy')}</Button>
				<Button variant="contained" disabled={busy || !current || !dirty} onClick={() => save('replace')} sx={{ml: 1, height: 32, minWidth: 110}}>{busy ? <CircularProgress size={18} color="inherit" /> : t('Save')}</Button>
			</Box>

			{options && <Box sx={{display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap', px: 2, py: 0.75, minHeight: 44, borderBottom: '1px solid var(--u-border)'}}>{options}</Box>}

			{conflict && (
				<Box role="alert" sx={{display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap', px: 2, py: 1.25, bgcolor: 'var(--u-orange-bg)', color: 'var(--u-orange)', fontSize: 13.5}}>
					<Box sx={{flex: 1, minWidth: 240}}>{t('This file was saved by someone else since you opened it.')}</Box>
					<Button size="small" variant="outlined" color="inherit" disabled={busy} onClick={() => save('copy')} sx={{height: 30}}>{t('Save a copy')}</Button>
					<Button size="small" variant="contained" color="warning" disabled={busy} onClick={() => save('replace', true)} sx={{height: 30}}>{t('Overwrite')}</Button>
				</Box>
			)}
			{error && <Box role="alert" sx={{display: 'flex', alignItems: 'center', px: 2, py: 1, bgcolor: 'var(--u-error-bg)', color: 'var(--u-error)', fontSize: 13.5}}><Box sx={{flex: 1}}>{error}</Box><IconButton size="small" aria-label={t('Close')} onClick={() => setError('')} sx={{color: 'inherit'}}><CloseOutlined sx={{fontSize: 16}} /></IconButton></Box>}

			{/* image: checkerboard for the transparency, overlay on top for the frames and the shapes being drawn */}
			<Box ref={box} sx={{flex: 1, minHeight: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', bgcolor: 'var(--u-head)'}}>
				{!current && !error && <CircularProgress />}
				{current && (
					<Box sx={{position: 'relative', width: width * scale, height: height * scale, flexShrink: 0, boxShadow: '0 2px 12px var(--u-shadow)',
						backgroundImage: 'conic-gradient(var(--u-border) 25%, transparent 0 50%, var(--u-border) 0 75%, transparent 0)', backgroundSize: '16px 16px'}}>
						<canvas ref={view} style={{position: 'absolute', inset: 0, width: '100%', height: '100%', filter: (tool === 'adjust') ? cssFilter(tune) : 'none'}} />
						<canvas ref={overlay} onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={up}
							style={{position: 'absolute', inset: 0, width: '100%', height: '100%', cursor, touchAction: 'none'}} />
						{draft && (
							<InputBase autoFocus value={draft.text} placeholder={t('Text')} onChange={(e) => setDraft({...draft, text: e.target.value})}
								onKeyDown={(e) => { e.stopPropagation(); if(e.key === 'Enter'){ e.preventDefault(); commitText() } else if(e.key === 'Escape'){ e.preventDefault(); setDraft(null) } }}
								onBlur={commitText}
								sx={{position: 'absolute', left: draft.x * scale, top: draft.y * scale, minWidth: 160, px: 0.5, fontSize: Math.max(12, fontSize * scale), fontWeight: 600, color, border: '1px dashed', borderColor: 'primary.main', bgcolor: ['#ffffff', '#fdd835'].includes(color) ? 'grey.800' : 'grey.100', '& input': {p: 0}}} />
						)}
					</Box>
				)}
			</Box>
			<Box sx={{px: 2, py: 0.5, fontSize: 12, color: 'var(--u-muted)', borderTop: '1px solid var(--u-border)'}}>
				{t('Original: [SIZE]', {SIZE: driveSize(file.size)})} - {t('The new file is written without its metadata (exif, gps).')}
			</Box>
		</Box>
	)

}
